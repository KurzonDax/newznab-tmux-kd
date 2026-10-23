<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ImageAssetProfile;
use App\Models\AnidbInfo;
use App\Models\AnidbTitle;
use App\Services\ProviderGateway\ProviderGateway;
use App\Services\ProviderGateway\ShortCircuitConnectException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Carbon;

class PopulateAniListService
{
    /**
     * AniList GraphQL API endpoint
     */
    private const API_URL = 'https://graphql.anilist.co';

    /**
     * Whether to echo message output.
     */
    public bool $echooutput;

    /**
     * The directory to store anime covers.
     */
    public string $imgSavePath;

    /**
     * HTTP client for API requests
     */
    protected Client $client;

    private ReleaseImageService $imageService;

    /**
     * @throws \Exception
     */
    public function __construct(?ReleaseImageService $imageService = null)
    {
        $this->echooutput = config('nntmux.echocli');

        // Use storage_path directly to match CoverController expectations
        $this->imgSavePath = storage_path('covers/anime/');
        $this->imageService = $imageService ?? new ReleaseImageService;
        // Requests go through the provider gateway, which spaces them fifteen a minute in this
        // process and pauses AniList for fifteen minutes after a 429 it does not retry.
        $this->client = app(ProviderGateway::class)->guzzle('anilist', [
            'base_uri' => self::API_URL,
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);
    }

    /**
     * Main switch that initiates AniList table population.
     *
     * @throws \Exception
     */
    public function populateTable(string $type = '', int|string $anilistId = ''): void
    {
        switch ($type) {
            case 'info':
                $this->populateInfoTable((string) $anilistId);
                break;
        }
    }

    /**
     * Search for anime by title using AniList GraphQL API.
     *
     * @return array<string, mixed>|false
     *
     * @throws \Exception
     */
    public function searchAnime(string $title, int $limit = 10)
    {
        $query = '
            query ($search: String, $perPage: Int) {
                Page (perPage: $perPage) {
                    media (search: $search, type: ANIME) {
                        id
                        idMal
                        title {
                            romaji
                            english
                            native
                        }
                        type
                        format
                        status
                        countryOfOrigin
                        episodes
                        duration
                        source
                        popularity
                        favourites
                        startDate {
                            year
                            month
                            day
                        }
                        endDate {
                            year
                            month
                            day
                        }
                        description
                        averageScore
                        hashtag
                        coverImage {
                            large
                        }
                        genres
                        studios {
                            nodes {
                                name
                            }
                        }
                        characters {
                            nodes {
                                name {
                                    full
                                }
                            }
                        }
                        relations {
                            edges {
                                relationType
                                node {
                                    id
                                    title {
                                        romaji
                                        english
                                    }
                                }
                            }
                        }
                    }
                }
            }
        ';

        $variables = [
            'search' => $title,
            'perPage' => $limit,
        ];

        $response = $this->makeGraphQLRequest($query, $variables);

        if ($response === false || ! isset($response['data']['Page']['media'])) {
            return false;
        }

        return $response['data']['Page']['media'];
    }

    /**
     * Refresh AniList data for a specific anidbid.
     * If anilist_id exists in database, uses it. Otherwise returns false.
     *
     * @return bool True on success, false on failure
     *
     * @throws \Exception
     */
    public function refreshAnimeByAnidbid(int $anidbid): bool
    {
        // Get anilist_id from database
        $anidbInfo = AnidbInfo::query()->where('anidbid', $anidbid)->first();

        if (! $anidbInfo || ! $anidbInfo->anilist_id) {
            return false;
        }

        // Fetch fresh data from AniList
        $anilistData = $this->getAnimeById($anidbInfo->anilist_id);

        if ($anilistData === false) {
            return false;
        }

        // Update database with fresh data
        $this->insertAniListInfo($anidbid, $anilistData);

        return true;
    }

    /**
     * Get anime by AniList ID.
     *
     * @return array<string, mixed>|false
     *
     * @throws \Exception
     */
    public function getAnimeById(int $anilistId)
    {
        $query = '
            query ($id: Int) {
                Media (id: $id, type: ANIME) {
                    id
                    idMal
                    title {
                        romaji
                        english
                        native
                    }
                    type
                    format
                    status
                    countryOfOrigin
                    episodes
                    duration
                    source
                    popularity
                    favourites
                    startDate {
                        year
                        month
                        day
                    }
                    endDate {
                        year
                        month
                        day
                    }
                    description
                    averageScore
                    hashtag
                    coverImage {
                        large
                    }
                    genres
                    studios {
                        nodes {
                            name
                        }
                    }
                    characters {
                        nodes {
                            name {
                                full
                            }
                        }
                    }
                    relations {
                        edges {
                            relationType
                            node {
                                id
                                title {
                                    romaji
                                    english
                                }
                            }
                        }
                    }
                }
            }
        ';

        $variables = ['id' => $anilistId];

        $response = $this->makeGraphQLRequest($query, $variables);

        if ($response === false || ! isset($response['data']['Media'])) {
            return false;
        }

        return $response['data']['Media'];
    }

    /**
     * Make a GraphQL request to AniList API.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>|false
     *
     * @throws \Exception while AniList is paused after a 429
     */
    private function makeGraphQLRequest(string $query, array $variables = [])
    {
        try {
            $response = $this->client->post('', [
                'json' => [
                    'query' => $query,
                    'variables' => $variables,
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $body = json_decode($response->getBody()->getContents(), true);

            if ($statusCode === 429) {
                $this->reportPause();

                return false;
            }

            if ($statusCode === 200 && isset($body['data'])) {
                return $body;
            }

            if (isset($body['errors'])) {
                if ($this->echooutput) {
                    cli()->error('AniList API Error: '.json_encode($body['errors']));
                }

                return false;
            }

            return false;
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() === 429) {
                $this->reportPause();

                throw $this->pausedException();
            }

            if ($this->echooutput) {
                cli()->error('AniList API Request Failed: '.$e->getMessage());
            }

            return false;
        } catch (ShortCircuitConnectException $e) {
            if (app(ProviderGateway::class)->heldUntil('anilist') !== null) {
                if ($this->echooutput) {
                    cli()->warning('API calls paused due to 429 error. Resuming in '.(int) ceil(now()->diffInSeconds($this->pausedUntil())).' seconds...');
                }

                // Throw exception to stop processing
                throw $this->pausedException();
            }

            if ($this->echooutput) {
                cli()->error('AniList API Request Failed: '.$e->getMessage());
            }

            return false;
        } catch (GuzzleException $e) {
            if ($this->echooutput) {
                cli()->error('AniList API Request Failed: '.$e->getMessage());
            }

            return false;
        }
    }

    private function reportPause(): void
    {
        if ($this->echooutput) {
            cli()->error('AniList API returned 429 (Too Many Requests). Pausing all API calls for 15 minutes.');
        }
    }

    private function pausedUntil(): Carbon
    {
        return app(ProviderGateway::class)->heldUntil('anilist') ?? now();
    }

    private function pausedException(): \Exception
    {
        return new \Exception('AniList API rate limit exceeded (429). Paused until '.$this->pausedUntil()->format('Y-m-d H:i:s'));
    }

    /**
     * Insert or update AniList title data.
     */
    private function insertAniListTitle(int $anidbid, string $type, string $lang, string $title): void
    {
        $check = AnidbTitle::query()->where([
            'anidbid' => $anidbid,
            'type' => $type,
            'lang' => $lang,
            'title' => $title,
        ])->first();

        if ($check === null) {
            AnidbTitle::insertOrIgnore([
                'anidbid' => $anidbid,
                'type' => $type,
                'lang' => $lang,
                'title' => $title,
            ]);
        }
    }

    /**
     * Insert or update AniList info data.
     *
     * @param  array<string, mixed>  $anilistData
     */
    protected function insertAniListInfo(int $anidbid, array $anilistData): void
    {
        // Extract data from AniList response
        $startDate = null;
        if (isset($anilistData['startDate']['year'], $anilistData['startDate']['month'], $anilistData['startDate']['day'])) {
            $startDate = sprintf(
                '%04d-%02d-%02d',
                $anilistData['startDate']['year'],
                $anilistData['startDate']['month'] ?? 1,
                $anilistData['startDate']['day'] ?? 1
            );
        }

        $endDate = null;
        if (isset($anilistData['endDate']['year'], $anilistData['endDate']['month'], $anilistData['endDate']['day'])) {
            $endDate = sprintf(
                '%04d-%02d-%02d',
                $anilistData['endDate']['year'],
                $anilistData['endDate']['month'] ?? 1,
                $anilistData['endDate']['day'] ?? 1
            );
        }

        $description = $anilistData['description'] ?? '';
        // Remove HTML tags from description
        $description = strip_tags($description);
        $description = html_entity_decode($description, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $rating = isset($anilistData['averageScore']) ? (string) $anilistData['averageScore'] : null;

        $picture = null;
        if (isset($anilistData['coverImage']['large'])) {
            $picture = basename(parse_url($anilistData['coverImage']['large'], PHP_URL_PATH));
        }

        $genres = '';
        if (isset($anilistData['genres']) && is_array($anilistData['genres'])) {
            $genres = implode(', ', $anilistData['genres']);
        }

        $creators = '';
        if (isset($anilistData['studios']['nodes']) && is_array($anilistData['studios']['nodes'])) {
            $studioNames = array_map(function ($studio) {
                return $studio['name'] ?? '';
            }, $anilistData['studios']['nodes']);
            $creators = implode(', ', array_filter($studioNames));
        }

        $characters = '';
        if (isset($anilistData['characters']['nodes']) && is_array($anilistData['characters']['nodes'])) {
            $characterNames = array_map(function ($character) {
                return $character['name']['full'] ?? '';
            }, array_slice($anilistData['characters']['nodes'], 0, 10)); // Limit to 10 characters
            $characters = implode(', ', array_filter($characterNames));
        }

        $related = '';
        $similar = '';
        if (isset($anilistData['relations']['edges']) && is_array($anilistData['relations']['edges'])) {
            $relatedItems = [];
            $similarItems = [];
            foreach ($anilistData['relations']['edges'] as $edge) {
                $relationType = $edge['relationType'] ?? '';
                $title = $edge['node']['title']['english'] ?? $edge['node']['title']['romaji'] ?? '';
                $id = $edge['node']['id'] ?? '';

                if (in_array($relationType, ['SEQUEL', 'PREQUEL', 'SIDE_STORY', 'PARENT', 'SPIN_OFF'], true)) {
                    $relatedItems[] = $title.' ('.$id.')';
                } elseif (in_array($relationType, ['ALTERNATIVE', 'CHARACTER'], true)) {
                    $similarItems[] = $title.' ('.$id.')';
                }
            }
            $related = implode(', ', array_slice($relatedItems, 0, 20));
            $similar = implode(', ', array_slice($similarItems, 0, 20));
        }

        $anilistId = $anilistData['id'] ?? null;
        $malId = $anilistData['idMal'] ?? null;
        $country = $anilistData['countryOfOrigin'] ?? null;
        $mediaType = $anilistData['type'] ?? null; // ANIME or MANGA
        $episodes = isset($anilistData['episodes']) ? (int) $anilistData['episodes'] : null;
        $duration = isset($anilistData['duration']) ? (int) $anilistData['duration'] : null;
        $status = $anilistData['status'] ?? null;
        $source = $anilistData['source'] ?? null;
        $hashtag = $anilistData['hashtag'] ?? null;

        // Use updateOrInsert to handle race conditions atomically
        // This prevents duplicate key errors when multiple processes try to insert the same record
        $check = AnidbInfo::query()->where('anidbid', $anidbid)->first();

        $data = [
            'anidbid' => $anidbid,
            'anilist_id' => $anilistId,
            'mal_id' => $malId,
            'country' => $country,
            'media_type' => $mediaType,
            'type' => $anilistData['format'] ?? null,
            'episodes' => $episodes,
            'duration' => $duration,
            'status' => $status,
            'source' => $source,
            'hashtag' => $hashtag,
            'startdate' => $startDate,
            'enddate' => $endDate,
            'description' => $description,
            'rating' => $rating,
            'picture' => $picture,
            'categories' => $genres,
            'characters' => $characters,
            'creators' => $creators,
            'related' => $related,
            'similar' => $similar,
            'updated' => now(),
        ];

        // If record exists, preserve existing values for null fields
        if ($check !== null) {
            $data['anilist_id'] = $anilistId ?? $check->anilist_id;
            $data['mal_id'] = $malId ?? $check->mal_id;
            $data['country'] = $country ?? $check->country;
            $data['media_type'] = $mediaType ?? $check->media_type;
            $data['type'] = $anilistData['format'] ?? $anilistData['type'] ?? $check->type;
            $data['episodes'] = $episodes ?? $check->episodes;
            $data['duration'] = $duration ?? $check->duration;
            $data['status'] = $status ?? $check->status;
            $data['source'] = $source ?? $check->source;
            $data['hashtag'] = $hashtag ?? $check->hashtag;
            $data['startdate'] = $startDate ?? $check->startdate;
            $data['enddate'] = $endDate ?? $check->enddate;
            $data['description'] = $description ?: $check->description;
            $data['rating'] = $rating ?? $check->rating;
            $data['picture'] = $picture ?? $check->picture;
            $data['categories'] = $genres ?: $check->categories;
            $data['characters'] = $characters ?: $check->characters;
            $data['creators'] = $creators ?: $check->creators;
            $data['related'] = $related ?: $check->related;
            $data['similar'] = $similar ?: $check->similar;
        }

        // Use updateOrInsert to atomically handle insert/update (prevents race conditions)
        AnidbInfo::query()->updateOrInsert(
            ['anidbid' => $anidbid],
            $data
        );

        // Insert titles
        if (isset($anilistData['title']['romaji']) && ! empty($anilistData['title']['romaji'])) {
            $this->insertAniListTitle($anidbid, 'main', 'x-jat', $anilistData['title']['romaji']);
        }
        if (isset($anilistData['title']['english']) && ! empty($anilistData['title']['english'])) {
            $this->insertAniListTitle($anidbid, 'official', 'en', $anilistData['title']['english']);
        }
        if (isset($anilistData['title']['native']) && ! empty($anilistData['title']['native'])) {
            $this->insertAniListTitle($anidbid, 'main', 'ja', $anilistData['title']['native']);
        }

        // Download cover image if available
        if (! empty($picture) && isset($anilistData['coverImage']['large'])) {
            $this->downloadCoverImage($anidbid, $anilistData['coverImage']['large']);
        }
    }

    /**
     * Download cover image from AniList.
     */
    private function downloadCoverImage(int $anidbid, string $imageUrl): void
    {
        $coverName = $anidbid.'-cover';
        if ($this->imageService->imageExists($this->imgSavePath, $coverName)) {
            return; // Already exists
        }

        try {
            $result = $this->imageService->saveRemoteImage(
                $coverName,
                $imageUrl,
                $this->imgSavePath,
                ImageAssetProfile::Original,
            );

            if (! $result->success) {
                throw new \RuntimeException($result->failureReason ?? 'Failed to process anime cover.');
            }

            if ($this->echooutput) {
                cli()->info("Downloaded cover image for ID {$anidbid} from AniList to {$result->path}");
            }
        } catch (GuzzleException $e) {
            if ($this->echooutput) {
                cli()->warning("Failed to download cover image for ID {$anidbid}: ".$e->getMessage());
            }
        } catch (\Exception $e) {
            if ($this->echooutput) {
                cli()->error("Error saving cover image for ID {$anidbid}: ".$e->getMessage());
            }
        }
    }

    /**
     * Directs flow for populating the AniList Info table.
     *
     * @throws \Exception
     */
    private function populateInfoTable(string $anilistId = ''): void
    {
        if (empty($anilistId)) {
            // Get titles that need updating (missing anilist_id or stale)
            $anidbIds = AnidbTitle::query()
                ->selectRaw('DISTINCT anidb_titles.anidbid')
                ->leftJoin('anidb_info as ai', 'ai.anidbid', '=', 'anidb_titles.anidbid')
                ->where(function ($query) {
                    $query->whereNull('ai.anilist_id')
                        ->orWhere('ai.updated', '<', now()->subWeek());
                })
                ->limit(100) // Process in batches
                ->get();

            foreach ($anidbIds as $anidb) {
                // Try to find by title first
                $title = AnidbTitle::query()
                    ->where('anidbid', $anidb->anidbid)
                    ->where('lang', 'en')
                    ->value('title');

                if ($title) {
                    $searchResults = $this->searchAnime($title, 1);
                    if ($searchResults) {
                        $anilistData = $searchResults[0]; // @phpstan-ignore offsetAccess.notFound
                        $this->insertAniListInfo($anidb->anidbid, $anilistData);
                        // Rate limiting is handled in makeGraphQLRequest
                    }
                }
            }
        } else {
            // Get specific anime by AniList ID
            $anilistData = $this->getAnimeById((int) $anilistId);

            if ($anilistData === false) {
                if ($this->echooutput) {
                    cli()->info("Anime with AniList ID: {$anilistId} not found.");
                }

                return;
            }

            // We need to find or create an anidbid for this
            // For now, use anilist_id as anidbid if not found
            $anidbid = AnidbInfo::query()
                ->where('anilist_id', $anilistId)
                ->value('anidbid');

            if (! $anidbid) {
                // Create a new entry using anilist_id as anidbid
                $anidbid = (int) $anilistId;
            }

            $this->insertAniListInfo($anidbid, $anilistData);
        }
    }
}
