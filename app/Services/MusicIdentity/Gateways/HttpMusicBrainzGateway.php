<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\Gateways;

use App\Services\MusicIdentity\Contracts\MusicBrainzGateway;
use App\Services\MusicIdentity\DTO\CandidateIdentifiers;
use App\Services\MusicIdentity\DTO\CandidateMetadata;
use App\Services\MusicIdentity\DTO\RecordingCandidates;
use App\Services\MusicIdentity\DTO\RecordingQuery;
use App\Services\MusicIdentity\DTO\ReleaseCandidates;
use App\Services\MusicIdentity\DTO\ReleaseQuery;
use App\Services\MusicIdentity\Exceptions\InvalidMusicBrainzResponse;
use App\Services\MusicIdentity\Exceptions\MusicBrainzCircuitOpen;
use App\Services\MusicIdentity\Exceptions\MusicBrainzConfigurationException;
use App\Services\MusicIdentity\Exceptions\MusicBrainzGatewayException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use JsonException;
use Throwable;

/**
 * @phpstan-import-type MusicRecording from CandidateMetadata
 * @phpstan-import-type MusicRelease from CandidateMetadata
 * @phpstan-import-type MusicReleaseGroup from CandidateMetadata
 * @phpstan-import-type MusicArtist from CandidateMetadata
 * @phpstan-import-type NormalizedCandidateIdentifiers from CandidateIdentifiers
 * @phpstan-import-type NormalizedRecordingQuery from RecordingQuery
 * @phpstan-import-type NormalizedReleaseQuery from ReleaseQuery
 */
final class HttpMusicBrainzGateway implements MusicBrainzGateway
{
    private const string RECORDING_INCLUDES = 'artist-credits+isrcs+releases+release-groups';

    private const string RELEASE_INCLUDES = 'recordings+artist-credits+labels+release-groups+media+discids+isrcs+genres+tags+url-rels';

    private const string RELEASE_GROUP_INCLUDES = 'artist-credits+releases+genres+tags+url-rels';

    public function __construct(
        private readonly MusicBrainzNormalizer $normalizer,
        private readonly MusicBrainzRequestPacer $requestPacer,
    ) {}

    public function candidatesFor(RecordingQuery $query): RecordingCandidates
    {
        $endpoint = $this->endpoint();
        if ($endpoint === null) {
            return RecordingCandidates::empty();
        }
        $this->assertPublicConfiguration($endpoint);

        $queryValues = $query->normalized();
        $this->assertIdentifiers($queryValues);
        $requests = [];
        if ($queryValues['recordingId'] !== null) {
            $requests['recording_lookup'] = $this->descriptor(
                'recording/'.rawurlencode((string) $queryValues['recordingId']),
                ['inc' => self::RECORDING_INCLUDES, 'fmt' => 'json'],
                exact: true,
                shape: 'recording',
            );
        }
        if ($queryValues['isrc'] !== null) {
            $requests['isrc_lookup'] = $this->descriptor(
                'isrc/'.rawurlencode((string) $queryValues['isrc']),
                ['inc' => 'artist-credits+isrcs', 'fmt' => 'json'],
                exact: true,
                shape: 'isrc',
            );
        }
        if ($queryValues['discId'] !== null) {
            $requests['disc_id_lookup'] = $this->descriptor(
                'discid/'.rawurlencode((string) $queryValues['discId']),
                ['inc' => self::RELEASE_INCLUDES, 'cdstubs' => 'no', 'fmt' => 'json'],
                exact: true,
                shape: 'disc',
            );
        }
        if ($queryValues['discToc'] !== null) {
            $requests['disc_toc_lookup'] = $this->descriptor(
                'discid/-',
                [
                    'toc' => $queryValues['discToc'],
                    'media-format' => 'all',
                    'inc' => self::RELEASE_INCLUDES,
                    'cdstubs' => 'no',
                    'fmt' => 'json',
                ],
                exact: true,
                shape: 'disc',
            );
        }

        $lucene = $this->luceneQuery($queryValues);
        if ($lucene !== null) {
            $requests['recording_search'] = $this->descriptor('recording', [
                'query' => $lucene,
                'limit' => (int) ($queryValues['limit'] ?? config('music-identity.musicbrainz.search_limit', 25)),
                'offset' => (int) $queryValues['offset'],
                'fmt' => 'json',
            ], exact: false, shape: 'recording_search');
        }

        if ($requests === []) {
            return RecordingCandidates::empty();
        }

        $budget = $this->budget();
        $payloads = $this->fetchMany($endpoint, $requests, $budget);
        $recordings = [];
        $providerTotal = 0;

        foreach ($payloads as $source => $payload) {
            if ($payload === []) {
                continue;
            }

            if ($source === 'recording_lookup') {
                $recordings[] = $this->normalizer->recording($payload, $source);

                continue;
            }

            if ($source === 'isrc_lookup') {
                $rawRecordings = array_key_exists('recordings', $payload)
                    ? $this->normalizer->objects($payload, 'recordings')
                    : [$payload];
                foreach ($rawRecordings as $rawRecording) {
                    $recordings[] = $this->normalizer->recording($rawRecording, $source);
                }

                continue;
            }

            if (in_array($source, ['disc_id_lookup', 'disc_toc_lookup'], true)) {
                foreach ($this->normalizer->objects($payload, 'releases') as $release) {
                    array_push($recordings, ...$this->normalizer->recordingsFromRelease($release, $source));
                }

                continue;
            }

            $providerTotal = max($providerTotal, $this->normalizer->requiredCount($payload, 'recording-count'));
            foreach ($this->normalizer->objects($payload, 'recordings') as $rawRecording) {
                $recordings[] = $this->normalizer->recording($rawRecording, $source);
            }
        }

        $recordings = $this->mergeRecordings($recordings);

        return new RecordingCandidates($recordings, max($providerTotal, count($recordings)));
    }

    public function releaseCandidatesFor(ReleaseQuery $query): ReleaseCandidates
    {
        $endpoint = $this->endpoint();
        if ($endpoint === null) {
            return ReleaseCandidates::empty();
        }
        $this->assertPublicConfiguration($endpoint);

        $queryValues = $query->normalized();
        $this->assertIdentifiers($queryValues);
        $lucene = $this->releaseLuceneQuery($queryValues);
        if ($lucene === null) {
            return ReleaseCandidates::empty();
        }

        $payload = $this->fetchOne($endpoint, $this->descriptor('release', [
            'query' => $lucene,
            'limit' => (int) ($queryValues['limit'] ?? config('music-identity.musicbrainz.search_limit', 25)),
            'offset' => (int) $queryValues['offset'],
            'fmt' => 'json',
        ], exact: false, shape: 'release_search'), $this->budget());
        if ($payload === []) {
            return ReleaseCandidates::empty();
        }

        $providerTotal = $this->normalizer->requiredCount($payload, 'release-count');
        $releases = array_map(
            fn (array $release): array => $this->normalizer->releaseCandidate($release, 'release_search'),
            $this->normalizer->objects($payload, 'releases'),
        );

        return new ReleaseCandidates($releases, max($providerTotal, count($releases)));
    }

    public function hydrate(CandidateIdentifiers $identifiers): CandidateMetadata
    {
        $endpoint = $this->endpoint();
        if ($endpoint === null) {
            return CandidateMetadata::empty();
        }
        $this->assertPublicConfiguration($endpoint);

        $ids = $identifiers->normalized();
        $this->assertIdentifiers($ids);
        $requests = [];
        if ($ids['artistId'] !== null) {
            $requests['artist'] = $this->descriptor(
                'artist/'.rawurlencode($ids['artistId']),
                ['fmt' => 'json'],
                exact: true,
                shape: 'artist',
            );
        }
        if ($ids['recordingId'] !== null) {
            $requests['recording'] = $this->descriptor(
                'recording/'.rawurlencode($ids['recordingId']),
                ['inc' => self::RECORDING_INCLUDES, 'fmt' => 'json'],
                exact: true,
                shape: 'recording',
            );
        }
        if ($ids['releaseId'] !== null) {
            $requests['release'] = $this->descriptor(
                'release/'.rawurlencode($ids['releaseId']),
                ['inc' => self::RELEASE_INCLUDES, 'fmt' => 'json'],
                exact: true,
                shape: 'release',
            );
        }
        if ($ids['releaseGroupId'] !== null) {
            $requests['release_group'] = $this->descriptor(
                'release-group/'.rawurlencode($ids['releaseGroupId']),
                ['inc' => self::RELEASE_GROUP_INCLUDES, 'fmt' => 'json'],
                exact: true,
                shape: 'release_group',
            );
        }
        if ($ids['isrc'] !== null) {
            $requests['isrc'] = $this->descriptor(
                'isrc/'.rawurlencode($ids['isrc']),
                ['inc' => 'artist-credits+isrcs', 'fmt' => 'json'],
                exact: true,
                shape: 'isrc',
            );
        }
        if ($ids['discId'] !== null) {
            $requests['disc'] = $this->descriptor(
                'discid/'.rawurlencode($ids['discId']),
                ['inc' => self::RELEASE_INCLUDES, 'cdstubs' => 'no', 'fmt' => 'json'],
                exact: true,
                shape: 'disc',
            );
        }

        if ($requests === []) {
            return CandidateMetadata::empty();
        }

        $budget = $this->budget();
        $payloads = $this->fetchMany($endpoint, $requests, $budget);
        $recordings = [];
        $releases = [];
        $releaseGroups = [];
        $artists = [];
        $recordingIdsToBrowse = [];
        $releaseGroupIdsToBrowse = [];

        foreach ($payloads as $kind => $payload) {
            if ($payload === []) {
                continue;
            }

            if ($kind === 'artist') {
                $artists[] = $this->normalizer->artist($payload);

                continue;
            }

            if ($kind === 'recording') {
                $recording = $this->normalizer->recording($payload, 'recording_hydration');
                $recordings[] = $recording;
                $recordingIdsToBrowse[] = (string) $recording['recordingId'];

                continue;
            }

            if ($kind === 'release') {
                $this->collectRelease($payload, $recordings, $releases, $releaseGroups);

                continue;
            }

            if ($kind === 'release_group') {
                $releaseGroup = $this->normalizer->releaseGroup($payload);
                $releaseGroups[] = $releaseGroup;
                $releaseGroupIdsToBrowse[] = (string) $releaseGroup['releaseGroupId'];

                continue;
            }

            if ($kind === 'isrc') {
                $rawRecordings = array_key_exists('recordings', $payload)
                    ? $this->normalizer->objects($payload, 'recordings')
                    : [$payload];
                foreach ($rawRecordings as $rawRecording) {
                    $recording = $this->normalizer->recording($rawRecording, 'isrc_hydration');
                    $recordings[] = $recording;
                    $recordingIdsToBrowse[] = (string) $recording['recordingId'];
                }

                continue;
            }

            foreach ($this->normalizer->objects($payload, 'releases') as $release) {
                $this->collectRelease($release, $recordings, $releases, $releaseGroups);
            }
        }

        $editionLimit = max(1, (int) config('music-identity.candidate_generation.hydrated_release_edition_limit', 8));
        foreach (array_values(array_unique($recordingIdsToBrowse)) as $recordingId) {
            $remainingEditions = $editionLimit - count($this->uniqueBy($releases, 'releaseId'));
            if ($remainingEditions <= 0) {
                break;
            }
            foreach ($this->browseReleases($endpoint, 'recording', $recordingId, $budget, $remainingEditions) as $release) {
                $this->collectRelease($release, $recordings, $releases, $releaseGroups);
            }
        }
        foreach (array_values(array_unique($releaseGroupIdsToBrowse)) as $releaseGroupId) {
            $remainingEditions = $editionLimit - count($this->uniqueBy($releases, 'releaseId'));
            if ($remainingEditions <= 0) {
                break;
            }
            foreach ($this->browseReleases($endpoint, 'release-group', $releaseGroupId, $budget, $remainingEditions) as $release) {
                $this->collectRelease($release, $recordings, $releases, $releaseGroups);
            }
        }

        return new CandidateMetadata(
            $this->mergeRecordings($recordings),
            array_slice($this->uniqueBy($releases, 'releaseId'), 0, $editionLimit),
            $this->uniqueBy($releaseGroups, 'releaseGroupId'),
            $this->uniqueBy($artists, 'artistId'),
        );
    }

    /**
     * @param  NormalizedRecordingQuery  $query
     */
    private function luceneQuery(array $query): ?string
    {
        $clauses = [];
        foreach (['title' => 'recording', 'artist' => 'artist', 'releaseTitle' => 'release'] as $key => $field) {
            if ($query[$key] !== null) {
                $clauses[] = $this->textClause($field, (string) $query[$key], $query['fuzzy']);
            }
        }
        foreach (['musicBrainzReleaseTrackId' => 'tid', 'artistId' => 'arid'] as $key => $field) {
            if ($query[$key] !== null) {
                $clauses[] = sprintf('%s:"%s"', $field, $this->escapeLucene((string) $query[$key]));
            }
        }

        if ($query['durationMs'] !== null) {
            $duration = (int) $query['durationMs'];
            $tolerance = (int) $query['durationToleranceMs'];
            $clauses[] = sprintf(
                'qdur:[%d TO %d]',
                intdiv(max(0, $duration - $tolerance), 2_000),
                intdiv($duration + $tolerance, 2_000),
            );
        }

        return $clauses === [] ? null : implode(' AND ', $clauses);
    }

    /** @param NormalizedReleaseQuery $query */
    private function releaseLuceneQuery(array $query): ?string
    {
        $clauses = [];
        foreach (['title' => 'release', 'artist' => 'artist'] as $key => $field) {
            if ($query[$key] !== null) {
                $clauses[] = $this->textClause($field, (string) $query[$key], $query['fuzzy']);
            }
        }
        if ($query['artistId'] !== null) {
            $clauses[] = sprintf('arid:"%s"', $this->escapeLucene($query['artistId']));
        }
        if ($query['year'] !== null) {
            $clauses[] = 'date:'.$query['year'];
        }
        foreach (['barcode' => 'barcode', 'catalogNumber' => 'catno', 'label' => 'label'] as $key => $field) {
            if ($query[$key] !== null) {
                $clauses[] = sprintf('%s:"%s"', $field, $this->escapeLucene((string) $query[$key]));
            }
        }

        return $clauses === [] ? null : implode(' AND ', $clauses);
    }

    private function textClause(string $field, string $value, bool $fuzzy): string
    {
        $exact = sprintf('%s:"%s"', $field, $this->escapeLucene($value));
        if (! $fuzzy) {
            return $exact;
        }

        $tokens = preg_split('/\s+/u', $value) ?: [];
        $tokens = array_values(array_filter(
            $tokens,
            static fn (string $token): bool => mb_strlen($token) >= 4,
        ));
        if ($tokens === []) {
            return $exact;
        }

        $fuzzyClauses = array_map(
            fn (string $token): string => sprintf('%s:%s~', $field, $this->escapeLucene($token)),
            $tokens,
        );

        return sprintf('(%s OR (%s))', $exact, implode(' AND ', $fuzzyClauses));
    }

    private function escapeLucene(string $value): string
    {
        $escaped = preg_replace('/([+\-&|!(){}\[\]^"~*?:\\\\\/])/', '\\\\$1', $value);

        return $escaped ?? $value;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function browseReleases(
        string $endpoint,
        string $linkedEntity,
        string $identifier,
        MusicBrainzRequestBudget $budget,
        int $maximumResults,
    ): array {
        $offset = 0;
        $total = null;
        $releases = [];
        $limit = min(
            $maximumResults,
            min(100, max(1, (int) config('music-identity.musicbrainz.browse_limit', 100))),
        );

        do {
            $payload = $this->fetchOne($endpoint, $this->descriptor('release', [
                $linkedEntity => $identifier,
                'inc' => self::RELEASE_INCLUDES,
                'limit' => $limit,
                'offset' => $offset,
                'fmt' => 'json',
            ], exact: true, shape: 'release_browse'), $budget);

            if ($payload === []) {
                break;
            }

            $page = $this->normalizer->objects($payload, 'releases');
            $total = $this->normalizer->requiredCount($payload, 'release-count');
            $returnedOffset = $this->normalizer->requiredCount($payload, 'release-offset');
            if ($returnedOffset !== $offset) {
                throw new InvalidMusicBrainzResponse('MusicBrainz browse response offset does not match the requested offset.');
            }

            array_push($releases, ...array_slice($page, 0, $maximumResults - count($releases)));
            $returned = count($page);
            if ($returned === 0) {
                break;
            }

            // MusicBrainz may stop a release page at 500 release tracks, so the fixed
            // requested limit is not a valid cursor increment.
            $offset += $returned;
        } while ($offset < $total && count($releases) < $maximumResults);

        return $releases;
    }

    /**
     * @param  array<string, mixed>  $rawRelease
     * @param  list<MusicRecording>  $recordings
     * @param  list<MusicRelease>  $releases
     * @param  list<MusicReleaseGroup>  $releaseGroups
     */
    private function collectRelease(
        array $rawRelease,
        array &$recordings,
        array &$releases,
        array &$releaseGroups,
    ): void {
        $releases[] = $this->normalizer->release($rawRelease);
        array_push($recordings, ...$this->normalizer->recordingsFromRelease($rawRelease, 'release_hydration'));

        $group = $rawRelease['release-group'] ?? null;
        if (is_array($group) && ! array_is_list($group)) {
            $releaseGroups[] = $this->normalizer->releaseGroup($group);
        }
    }

    /**
     * @param  list<MusicRecording>  $recordings
     * @return list<MusicRecording>
     */
    private function mergeRecordings(array $recordings): array
    {
        $merged = [];
        foreach ($recordings as $recording) {
            $id = (string) $recording['recordingId'];
            if (! isset($merged[$id])) {
                $merged[$id] = $recording;

                continue;
            }

            foreach (['isrcs', 'releaseIds', 'releaseGroupIds', 'sources'] as $listKey) {
                $merged[$id][$listKey] = array_values(array_unique(array_merge(
                    (array) $merged[$id][$listKey],
                    (array) $recording[$listKey],
                )));
            }
            if ($merged[$id]['providerScore'] === null || (int) $recording['providerScore'] > (int) $merged[$id]['providerScore']) {
                $merged[$id]['providerScore'] = $recording['providerScore'];
            }
        }

        return array_values($merged);
    }

    /**
     * @template T of array<string, mixed>
     *
     * @param  list<T>  $items
     * @return list<T>
     */
    private function uniqueBy(array $items, string $key): array
    {
        $unique = [];
        foreach ($items as $item) {
            $unique[(string) $item[$key]] ??= $item;
        }

        return array_values($unique);
    }

    /**
     * @param  array<string, array{path: string, query: array<string, int|string>, exact: bool, shape: string}>  $requests
     * @return array<string, array<string, mixed>>
     */
    private function fetchMany(string $endpoint, array $requests, MusicBrainzRequestBudget $budget): array
    {
        if ($this->requestPacer->isPublicEndpoint($endpoint) || count($requests) === 1) {
            $responses = [];
            foreach ($requests as $key => $request) {
                $responses[$key] = $this->fetchOne($endpoint, $request, $budget);
            }

            return $responses;
        }

        $responses = [];
        $uncached = [];
        foreach ($requests as $key => $request) {
            $cached = Cache::get($this->cacheKey($endpoint, $request));
            if (is_array($cached)) {
                $responses[$key] = $cached;
            } else {
                $uncached[$key] = $request;
            }
        }

        if ($uncached === []) {
            return $responses;
        }

        $this->assertCircuitClosed($endpoint);
        $concurrency = max(1, (int) config('music-identity.musicbrainz.max_concurrency', 4));
        foreach (array_chunk($uncached, $concurrency, preserve_keys: true) as $chunk) {
            $pooled = Http::pool(function (Pool $pool) use ($budget, $chunk, $endpoint): array {
                $promises = [];
                foreach ($chunk as $key => $request) {
                    $promises[] = $this->pending($pool->as($key), $endpoint, $budget)
                        ->get($this->url($endpoint, $request['path']), $request['query']);
                }

                return $promises;
            }, concurrency: $concurrency);

            foreach ($chunk as $key => $request) {
                $response = $pooled[$key] ?? null;
                if ($response instanceof Throwable) {
                    if ($this->isTransientFailure($response)) {
                        $this->recordFailure($endpoint);
                    }
                    if ($response instanceof MusicBrainzGatewayException) {
                        throw $response;
                    }

                    throw new MusicBrainzGatewayException('MusicBrainz request failed: '.$response->getMessage(), previous: $response);
                }
                if (! $response instanceof Response) {
                    throw new MusicBrainzGatewayException('MusicBrainz request returned no response.');
                }

                $payload = $this->decodeResponse($response, $endpoint);
                $this->validatePayload($payload, $request['shape'], $endpoint);
                $this->recordSuccess($endpoint);
                $responses[$key] = $payload;
                $this->cache($endpoint, $request, $payload);
            }
        }

        return $responses;
    }

    /**
     * @param  array{path: string, query: array<string, int|string>, exact: bool, shape: string}  $request
     * @return array<string, mixed>
     */
    private function fetchOne(string $endpoint, array $request, MusicBrainzRequestBudget $budget): array
    {
        $cacheKey = $this->cacheKey($endpoint, $request);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $this->assertCircuitClosed($endpoint);

        try {
            $response = $this->pending(Http::acceptJson(), $endpoint, $budget)
                ->get($this->url($endpoint, $request['path']), $request['query']);
            $payload = $this->decodeResponse($response, $endpoint);
            $this->validatePayload($payload, $request['shape'], $endpoint);
            $this->recordSuccess($endpoint);
            $this->cache($endpoint, $request, $payload);

            return $payload;
        } catch (InvalidMusicBrainzResponse|MusicBrainzGatewayException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if ($this->isTransientFailure($exception)) {
                $this->recordFailure($endpoint);
            }

            throw new MusicBrainzGatewayException('MusicBrainz request failed: '.$exception->getMessage(), previous: $exception);
        }
    }

    /** @return array<string, mixed> */
    private function decodeResponse(Response $response, string $endpoint): array
    {
        if ($response->status() === 404) {
            $this->recordSuccess($endpoint);

            return [];
        }
        if (! $response->successful()) {
            if ($response->status() === 429 || $response->serverError()) {
                $this->recordFailure($endpoint);
            }

            throw new MusicBrainzGatewayException(sprintf('MusicBrainz returned HTTP %d.', $response->status()));
        }

        try {
            $payload = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->recordFailure($endpoint);

            throw new InvalidMusicBrainzResponse('MusicBrainz returned invalid JSON.', previous: $exception);
        }

        if (! is_array($payload) || array_is_list($payload)) {
            $this->recordFailure($endpoint);

            throw new InvalidMusicBrainzResponse('MusicBrainz returned JSON that is not an object.');
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function validatePayload(array $payload, string $shape, string $endpoint): void
    {
        if ($payload === []) {
            return;
        }

        try {
            if ($shape === 'artist') {
                $this->normalizer->artist($payload);

                return;
            }

            if ($shape === 'recording') {
                $this->normalizer->recording($payload, 'validation');

                return;
            }

            if ($shape === 'isrc') {
                $recordings = array_key_exists('recordings', $payload)
                    ? $this->normalizer->objects($payload, 'recordings')
                    : [$payload];
                foreach ($recordings as $recording) {
                    $this->normalizer->recording($recording, 'validation');
                }

                return;
            }

            if ($shape === 'disc') {
                foreach ($this->normalizer->objects($payload, 'releases') as $release) {
                    $this->normalizer->release($release);
                }

                return;
            }

            if ($shape === 'recording_search') {
                $this->normalizer->requiredCount($payload, 'recording-count');
                $this->normalizer->requiredCount($payload, 'recording-offset');
                foreach ($this->normalizer->objects($payload, 'recordings') as $recording) {
                    $this->normalizer->recording($recording, 'validation');
                }

                return;
            }

            if ($shape === 'release') {
                $this->normalizer->release($payload);

                return;
            }

            if ($shape === 'release_group') {
                $this->normalizer->releaseGroup($payload);

                return;
            }

            if ($shape === 'release_browse') {
                $this->normalizer->requiredCount($payload, 'release-count');
                $this->normalizer->requiredCount($payload, 'release-offset');
                foreach ($this->normalizer->objects($payload, 'releases') as $release) {
                    $this->normalizer->release($release);
                }

                return;
            }

            if ($shape === 'release_search') {
                $this->normalizer->requiredCount($payload, 'release-count');
                $this->normalizer->requiredCount($payload, 'release-offset');
                foreach ($this->normalizer->objects($payload, 'releases') as $release) {
                    $this->normalizer->releaseCandidate($release, 'validation');
                }

                return;
            }

            throw new InvalidMusicBrainzResponse('Unknown MusicBrainz response shape.');
        } catch (InvalidMusicBrainzResponse $exception) {
            $this->recordFailure($endpoint);

            throw $exception;
        }
    }

    private function pending(
        PendingRequest $request,
        string $endpoint,
        MusicBrainzRequestBudget $budget,
    ): PendingRequest {
        return $request
            ->acceptJson()
            ->withUserAgent($this->userAgent($endpoint))
            ->connectTimeout(max(0.1, (float) config('music-identity.musicbrainz.connect_timeout_seconds', 2)))
            ->timeout(max(0.1, (float) config('music-identity.musicbrainz.timeout_seconds', 8)))
            ->beforeSending(function () use ($budget, $endpoint): void {
                $budget->consume();
                $this->requestPacer->pace($endpoint);
            })
            ->retry(
                max(1, (int) config('music-identity.musicbrainz.retry.attempts', 3)),
                max(0, (int) config('music-identity.musicbrainz.retry.backoff_milliseconds', 250)),
                fn (Throwable $exception, PendingRequest $pendingRequest): bool => $this->shouldRetry($exception, $pendingRequest),
                throw: false,
            );
    }

    private function shouldRetry(Throwable $exception, PendingRequest $request): bool
    {
        unset($request);

        return $this->isTransientFailure($exception);
    }

    private function isTransientFailure(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        return $exception instanceof RequestException
            && ($exception->response->serverError() || $exception->response->status() === 429);
    }

    /**
     * @param  array<string, int|string>  $query
     * @return array{path: string, query: array<string, int|string>, exact: bool, shape: string}
     */
    private function descriptor(string $path, array $query, bool $exact, string $shape): array
    {
        return ['path' => $path, 'query' => $query, 'exact' => $exact, 'shape' => $shape];
    }

    /**
     * @param  array{path: string, query: array<string, int|string>, exact: bool, shape: string}  $request
     * @param  array<string, mixed>  $payload
     */
    private function cache(string $endpoint, array $request, array $payload): void
    {
        $ttl = $request['exact']
            ? (int) config('music-identity.musicbrainz.cache.exact_ttl_seconds', 604_800)
            : (int) config('music-identity.musicbrainz.cache.search_ttl_seconds', 3_600);
        Cache::put($this->cacheKey($endpoint, $request), $payload, max(1, $ttl));
    }

    /** @param array{path: string, query: array<string, int|string>, exact: bool, shape: string} $request */
    private function cacheKey(string $endpoint, array $request): string
    {
        $query = $request['query'];
        ksort($query);
        $normalized = [
            'endpoint' => strtolower($endpoint),
            'exact' => $request['exact'],
            'path' => $request['path'],
            'providerVersion' => (string) config('music-identity.musicbrainz.provider_version', 'ws2-v1'),
            'query' => $query,
            'shape' => $request['shape'],
        ];

        return 'musicbrainz:response:'.hash('sha256', (string) json_encode($normalized, JSON_THROW_ON_ERROR));
    }

    private function assertCircuitClosed(string $endpoint): void
    {
        if (Cache::has($this->circuitKey($endpoint, 'open'))) {
            throw new MusicBrainzCircuitOpen('MusicBrainz circuit is open after repeated provider failures.');
        }
    }

    private function recordFailure(string $endpoint): void
    {
        $failuresKey = $this->circuitKey($endpoint, 'failures');
        Cache::add($failuresKey, 0, now()->addDay());
        $failures = (int) Cache::increment($failuresKey);
        $threshold = max(1, (int) config('music-identity.musicbrainz.circuit_breaker.failure_threshold', 3));
        if ($failures >= $threshold) {
            Cache::put(
                $this->circuitKey($endpoint, 'open'),
                true,
                max(1, (int) config('music-identity.musicbrainz.circuit_breaker.open_seconds', 60)),
            );
        }
    }

    private function recordSuccess(string $endpoint): void
    {
        Cache::forget($this->circuitKey($endpoint, 'failures'));
    }

    private function circuitKey(string $endpoint, string $suffix): string
    {
        return sprintf(
            'musicbrainz:circuit:%s:%s:%s',
            hash('sha256', strtolower($endpoint)),
            config('music-identity.musicbrainz.provider_version', 'ws2-v1'),
            $suffix,
        );
    }

    private function endpoint(): ?string
    {
        $endpoint = trim((string) config('music-identity.musicbrainz.endpoint_url'));
        if ($endpoint === '') {
            return null;
        }

        $scheme = strtolower((string) parse_url($endpoint, PHP_URL_SCHEME));
        $host = parse_url($endpoint, PHP_URL_HOST);
        if (! in_array($scheme, ['http', 'https'], true) || ! is_string($host) || $host === '') {
            throw new MusicBrainzConfigurationException('MusicBrainz endpoint must be an absolute HTTP(S) URL.');
        }

        return rtrim($endpoint, '/').'/';
    }

    private function assertPublicConfiguration(string $endpoint): void
    {
        if ($this->requestPacer->isPublicEndpoint($endpoint) && trim((string) config('music-identity.musicbrainz.user_agent_contact')) === '') {
            throw new MusicBrainzConfigurationException(
                'MUSICBRAINZ_USER_AGENT_CONTACT is required when using musicbrainz.org.',
            );
        }
    }

    private function userAgent(string $endpoint): string
    {
        $contact = trim((string) config('music-identity.musicbrainz.user_agent_contact'));

        return $contact === '' || ! $this->requestPacer->isPublicEndpoint($endpoint)
            ? 'NNTmux/1.0'
            : 'NNTmux/1.0 ('.$contact.')';
    }

    private function url(string $endpoint, string $path): string
    {
        return $endpoint.ltrim($path, '/');
    }

    private function budget(): MusicBrainzRequestBudget
    {
        return new MusicBrainzRequestBudget(
            max(1, (int) config('music-identity.musicbrainz.request_budget', 12)),
        );
    }

    /** @param NormalizedCandidateIdentifiers|NormalizedRecordingQuery|NormalizedReleaseQuery $identifiers */
    private function assertIdentifiers(array $identifiers): void
    {
        foreach (['recordingId', 'releaseId', 'releaseGroupId', 'musicBrainzReleaseTrackId', 'artistId'] as $key) {
            $value = $identifiers[$key] ?? null;
            if ($value !== null && preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/D', (string) $value) !== 1) {
                throw new \InvalidArgumentException(sprintf('%s must be a valid MusicBrainz identifier.', $key));
            }
        }

        $isrc = $identifiers['isrc'] ?? null;
        if ($isrc !== null && preg_match('/^[A-Z]{2}[A-Z0-9]{3}[0-9]{7}$/D', (string) $isrc) !== 1) {
            throw new \InvalidArgumentException('isrc must be a valid 12-character ISRC.');
        }

        $discId = $identifiers['discId'] ?? null;
        if ($discId !== null && preg_match('/^[A-Za-z0-9._-]{28}$/D', (string) $discId) !== 1) {
            throw new \InvalidArgumentException('discId must be a valid 28-character MusicBrainz Disc ID.');
        }
    }
}
