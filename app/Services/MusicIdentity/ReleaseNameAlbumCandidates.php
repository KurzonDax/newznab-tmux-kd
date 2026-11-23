<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity;

use App\Services\MusicIdentity\Contracts\MusicBrainzGateway;
use App\Services\MusicIdentity\DTO\AudioEvidenceSet;
use App\Services\MusicIdentity\DTO\CandidateHypothesis;
use App\Services\MusicIdentity\DTO\CandidateIdentifiers;
use App\Services\MusicIdentity\DTO\CandidateIdentity;
use App\Services\MusicIdentity\DTO\CandidateMetadata;
use App\Services\MusicIdentity\DTO\CandidatePool;
use App\Services\MusicIdentity\DTO\CandidateSignal;
use App\Services\MusicIdentity\DTO\ReleaseGroupQuery;
use App\Services\MusicIdentity\DTO\ReleaseNameAlbum;
use App\Services\MusicIdentity\DTO\ReleaseNameAlbumMatch;
use App\Services\MusicIdentity\Enums\CandidateSignalKind;
use App\Services\MusicIdentity\Exceptions\MusicBrainzGatewayException;
use App\Services\MusicIdentity\Matching\ReleaseNameAlbumParser;

/**
 * The release name as identification evidence (issue #1033): the name captured with the evidence
 * is read as artist, album title and year, MusicBrainz release groups are searched by that, and
 * the name identifies an album only when exactly one group is left. The resolver asks only for a
 * release the file evidence left without an album.
 *
 * @phpstan-import-type MusicReleaseGroup from CandidateMetadata
 */
final readonly class ReleaseNameAlbumCandidates
{
    /** The artist and title agreement the file-evidence album-text gate also asks for. */
    private const float MINIMUM_ARTIST_AGREEMENT = 0.8;

    private const float MINIMUM_TITLE_AGREEMENT = 0.85;

    /** MusicBrainz returns at most this many release groups for one search. */
    private const int SEARCH_LIMIT = 100;

    /**
     * The MusicBrainz release group types that answer a different-work word of the name as well as
     * the word in the group's own title does. A word not listed is answered only by the title.
     */
    private const array WORK_TYPES = [
        'live' => ['Live'],
        'remix' => ['Remix'],
        'remixes' => ['Remix'],
        'remixed' => ['Remix'],
        'mix' => ['DJ-mix', 'Mixtape/Street'],
        'mixes' => ['DJ-mix', 'Mixtape/Street'],
        'demo' => ['Demo'],
        'demos' => ['Demo'],
        'ep' => ['EP'],
        'single' => ['Single'],
        'singles' => ['Single'],
    ];

    public function __construct(
        private MusicBrainzGateway $gateway,
        private ReleaseNameAlbumParser $parser,
    ) {}

    /**
     * The release group the name identifies: the first reading of the name with a passing group
     * decides, and a reading left with several groups makes the name ambiguous. So does a search
     * MusicBrainz answered only in part: the groups it held back could pass too.
     *
     * @throws MusicBrainzGatewayException when a search fails
     */
    public function match(AudioEvidenceSet $evidence): ?ReleaseNameAlbumMatch
    {
        foreach ($this->readings($evidence) as [$album, $source]) {
            $found = $this->search($album);
            if ($found === null) {
                return null;
            }
            [$releaseGroups, $responseCacheKeys] = $found;
            $passing = array_values(array_filter($releaseGroups, fn (array $releaseGroup): bool => $this->passes($album, $releaseGroup)));
            if ($passing === []) {
                continue;
            }

            $rule = 'unique';
            if (count($passing) > 1 && $album->year !== null) {
                $year = (string) $album->year;
                $ofYear = array_values(array_filter(
                    $passing,
                    static fn (array $releaseGroup): bool => str_starts_with((string) $releaseGroup['firstReleaseDate'], $year),
                ));
                if ($ofYear !== []) {
                    $passing = $ofYear;
                    $rule = 'year';
                }
            }
            if (count($passing) > 1) {
                $passing = array_values(array_filter(
                    $passing,
                    static fn (array $releaseGroup): bool => $releaseGroup['primaryType'] === 'Album' && $releaseGroup['secondaryTypes'] === [],
                ));
                $rule = 'album_type';
            }
            if (count($passing) !== 1) {
                return null;
            }

            return new ReleaseNameAlbumMatch(
                releaseGroupId: $passing[0]['releaseGroupId'],
                artist: $album->artist,
                title: $album->titles[0],
                year: $album->year,
                rule: $rule,
                responseCacheKeys: $responseCacheKeys,
                source: $source,
            );
        }

        return null;
    }

    /**
     * The readings searched, in order: the name's, then the album tags' (issue #1276) when both
     * the artist and the album title are tagged. A name with the word VA or Various belongs to a
     * compilation or a chart pack, whose one probed track is tagged with its own artist and album,
     * so the tags are read only when their artist is Various Artists too.
     *
     * @return list<array{ReleaseNameAlbum, 'name'|'tags'}>
     */
    private function readings(AudioEvidenceSet $evidence): array
    {
        $readings = [];
        foreach ($evidence->releaseTitle === null ? [] : $this->parser->parse($evidence->releaseTitle) as $album) {
            $readings[] = [$album, 'name'];
        }
        if ($evidence->albumArtist === null || $evidence->albumTitle === null) {
            return $readings;
        }
        $tags = $this->parser->fromTags($evidence->albumArtist, $evidence->albumTitle, $evidence->releaseYear);
        if ($tags === null || ($tags->artist !== 'Various Artists' && $this->namesVariousArtists($evidence->releaseTitle))) {
            return $readings;
        }
        $readings[] = [$tags, 'tags'];

        return $readings;
    }

    private function namesVariousArtists(?string $releaseName): bool
    {
        $words = explode(' ', $this->parser->fold($releaseName) ?? '');

        return in_array('va', $words, true) || in_array('various', $words, true);
    }

    /**
     * The pool with the name's signal on the matched release group's candidate, which is appended
     * (hydrated) when the file evidence produced none. A match through the album tags signals in
     * the tags' own provenance family: the tags are the evidence the album-title search used, not
     * a second independent source.
     *
     * @throws MusicBrainzGatewayException when hydrating the new candidate fails
     */
    public function supplement(AudioEvidenceSet $evidence, CandidatePool $pool, ReleaseNameAlbumMatch $match): CandidatePool
    {
        $identity = new CandidateIdentity(releaseGroupId: $match->releaseGroupId);
        $byTags = $match->source === 'tags';
        $signal = new CandidateSignal(
            kind: $byTags ? CandidateSignalKind::AlbumTags : CandidateSignalKind::ReleaseName,
            value: $match->artist.' - '.$match->title,
            provenanceFamily: $byTags ? $evidence->albumProvenanceFamily() : 'evidence:'.$evidence->evidenceId.':release-name',
            exact: false,
            identity: $identity,
            responseCacheKeys: $match->responseCacheKeys,
        );

        $candidates = [];
        $found = false;
        foreach ($pool->candidates as $candidate) {
            if ($candidate->identity->key() === $identity->key()) {
                $found = true;
                $candidate = new CandidateHypothesis($candidate->identity, $candidate->metadata, [...$candidate->signals, $signal]);
            }
            $candidates[] = $candidate;
        }
        if (! $found) {
            $candidates[] = new CandidateHypothesis(
                identity: $identity,
                metadata: $this->gateway->hydrate(new CandidateIdentifiers(releaseGroupId: $match->releaseGroupId)),
                signals: [$signal],
            );
        }

        return new CandidatePool($candidates);
    }

    /**
     * How far two names agree, 0 to 1: the share of their distinct words that both hold. "The" is
     * not counted unless it is all there is.
     */
    public function agreement(?string $left, ?string $right): float
    {
        $left = $this->parser->fold($left);
        $right = $this->parser->fold($right);
        if ($left === null || $right === null) {
            return 0.0;
        }

        $leftWords = array_unique($this->significantWords($left));
        $rightWords = array_unique($this->significantWords($right));

        return count(array_intersect($leftWords, $rightWords)) / count(array_unique([...$leftWords, ...$rightWords]));
    }

    /**
     * One search per title variant; each release group once, in arrival order. Null when
     * MusicBrainz holds more groups for a search than it returned.
     *
     * @return array{list<MusicReleaseGroup>, list<string>}|null the groups and the response cache keys
     */
    private function search(ReleaseNameAlbum $album): ?array
    {
        $releaseGroups = [];
        $responseCacheKeys = [];
        foreach ($album->titles as $title) {
            $found = $this->gateway->releaseGroupCandidatesFor(new ReleaseGroupQuery(artist: $album->artist, title: $title, limit: self::SEARCH_LIMIT));
            if ($found->providerTotal > count($found->releaseGroups)) {
                return null;
            }
            foreach ($found->releaseGroups as $releaseGroup) {
                $releaseGroups[$releaseGroup['releaseGroupId']] ??= $releaseGroup;
            }
            array_push($responseCacheKeys, ...$found->responseCacheKeys);
        }

        return [array_values($releaseGroups), array_values(array_unique($responseCacheKeys))];
    }

    /** @param MusicReleaseGroup $releaseGroup */
    private function passes(ReleaseNameAlbum $album, array $releaseGroup): bool
    {
        $artistAgreement = $this->agreement($album->artist, $releaseGroup['artistCredit']);
        foreach ($releaseGroup['artists'] ?? [] as $artist) {
            $artistAgreement = max($artistAgreement, $this->agreement($album->artist, $artist['name']));
        }
        if ($artistAgreement < self::MINIMUM_ARTIST_AGREEMENT) {
            return false;
        }

        foreach ($album->titles as $title) {
            if ($this->agreement($title, $releaseGroup['title']) >= self::MINIMUM_TITLE_AGREEMENT) {
                return $this->answersWorkQualifiers($album, $releaseGroup);
            }
        }

        return false;
    }

    /**
     * Whether the group is the work the name says: "Album (Live)" is not the studio "Album",
     * however many other words agree.
     *
     * @param  MusicReleaseGroup  $releaseGroup
     */
    private function answersWorkQualifiers(ReleaseNameAlbum $album, array $releaseGroup): bool
    {
        $groupWords = explode(' ', $this->parser->fold($releaseGroup['title']) ?? '');
        $groupTypes = [$releaseGroup['primaryType'], ...$releaseGroup['secondaryTypes']];
        $named = array_intersect(explode(' ', $this->parser->fold($album->titles[0]) ?? ''), ReleaseNameAlbumParser::DIFFERENT_WORK);
        foreach ($named as $word) {
            if (! in_array($word, $groupWords, true) && array_intersect(self::WORK_TYPES[$word] ?? [], $groupTypes) === []) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private function significantWords(string $folded): array
    {
        $words = explode(' ', $folded);
        $withoutArticle = array_values(array_filter($words, static fn (string $word): bool => $word !== 'the'));

        return $withoutArticle === [] ? $words : $withoutArticle;
    }
}
