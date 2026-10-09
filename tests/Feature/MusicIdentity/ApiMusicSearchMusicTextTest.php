<?php

declare(strict_types=1);

namespace Tests\Feature\MusicIdentity;

use App\Data\Api\ReleaseData;
use App\Enums\SecondarySearchIndex;
use App\Facades\Search;
use App\Models\User;
use App\Services\MusicIdentity\DTO\AcceptedMusicText;
use App\Services\MusicIdentity\Enums\AcceptedIdentityScope;
use App\Services\MusicIdentity\Enums\IdentificationStatus;
use App\Services\Releases\ReleaseSearchService;
use App\Services\Search\DTO\ReleaseSearchQuery;
use App\Services\Search\DTO\SearchPage;
use App\Services\Search\SearchService;
use App\Services\Search\Support\ReleaseIndexProjection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\MusicIdentityDecisionFixtures;
use Tests\Support\MysqlIntervalSqlitePdo;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/**
 * API music search (v1 t=music&q=, v2 /api/v2/audio?id=, both through apiMusicSearch) finds a
 * release by the accepted MusicBrainz text in its release search document as well as through its
 * legacy musicinfo link, and never by its release name (#307). The search index is an in-memory
 * twin that reads the real release projection; provider responses are frozen candidate pools.
 */
final class ApiMusicSearchMusicTextTest extends TestCase
{
    use MusicIdentityDecisionFixtures;

    /** @var list<ReleaseSearchQuery> */
    private array $queries = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'music-identity.algorithm_version' => 'music-identity-v2',
        ]);
        DB::purge();
        DB::reconnect();
        $pdo = new MysqlIntervalSqlitePdo('sqlite::memory:');
        DB::connection()->setPdo($pdo)->setReadPdo($pdo);
        Cache::flush();
        Carbon::setTestNow('2026-10-08 12:00:00');
        Search::spy();

        foreach ([
            'settings', 'releases', 'usenet_groups', 'categories', 'root_categories', 'movieinfo', 'musicinfo', 'consoleinfo', 'gamesinfo',
            'bookinfo', 'videos', 'tv_episodes', 'release_nfos', 'video_data', 'media_infos', 'release_files', 'audio_data',
            'release_subtitles', 'anidb_titles', 'media_info_probes', 'media_info_tracks', 'release_audio_tags',
            'release_audio_evidence', 'release_music_identifications', 'musicbrainz_release_group_genres', 'musicbrainz_release_tracks', 'musicbrainz_artists', 'musicbrainz_artist_aliases', 'release_music_identification_artists', 'release_music_candidate_attempts',
        ] as $table) {
            ProductionTables::fromAuthority()->create($table);
        }
        DB::table('root_categories')->insert(['id' => 3000, 'title' => 'Audio']);
        DB::table('categories')->insert([
            ['id' => 3010, 'title' => 'MP3', 'root_categories_id' => 3000],
            ['id' => 3040, 'title' => 'Lossless', 'root_categories_id' => 3000],
        ]);
        DB::table('musicinfo')->insert(['id' => 7, 'title' => 'Legacy Album', 'artist' => 'Legacy Artist']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function an_accepted_album_is_found_by_its_album_title_artist_and_a_track_title_but_not_its_release_name(): void
    {
        $releaseId = $this->release('Obfuscated.Album-GRP');
        $this->resolveAndStore($releaseId, $this->albumCandidate(), $this->albumEvidence());

        foreach (['Example Album', 'Alias Album', 'Example Artist', 'Last Light', 'example artist first light', 'Guest Artist'] as $text) {
            $this->assertSame([$releaseId], $this->found($text), $text);
        }
        $this->assertSame([], $this->found('Obfuscated'), 'the release name is not music text');
    }

    #[Test]
    public function a_recording_only_acceptance_adds_its_recording_and_no_album_text_and_keeps_the_legacy_album(): void
    {
        $unlinked = $this->release('Unlinked.Release-GRP');
        $legacy = $this->release('Legacy.Linked.Release-GRP', musicInfoId: 7);
        foreach ([$unlinked, $legacy] as $releaseId) {
            $this->resolveAndStore($releaseId, $this->recordingCandidate(), $this->recordingEvidence());
        }

        $this->assertSame([$unlinked, $legacy], $this->found('Recorded Track'));
        $this->assertSame([], $this->found('Candidate Album'), 'the unaccepted album candidate adds nothing');
        $this->assertSame([], $this->found('Candidate Alias'));
        $this->assertSame([$legacy], $this->found('Legacy Album'), 'only the legacy-linked release keeps its legacy album');
    }

    #[Test]
    public function an_accepted_albums_artist_names_and_searched_aliases_find_it(): void
    {
        $album = $this->release('Obfuscated.Album-GRP');
        $this->resolveAndStore($album, $this->albumCandidate(), $this->albumEvidence());
        $recording = $this->release('Recording.Only-GRP');
        $this->resolveAndStore($recording, $this->recordingCandidate(), $this->recordingEvidence());

        foreach (['Canonical Example Band', 'Altname Ensemble', 'Hintword Band'] as $text) {
            $this->assertSame([$album], $this->found($text), $text.' finds the album, not the recording-only acceptance');
        }
        foreach (['Legalname Person', 'Untypedname Group'] as $text) {
            $this->assertSame([], $this->found($text), $text);
        }
    }

    #[Test]
    public function needs_review_and_conflicted_text_finds_nothing(): void
    {
        $text = new AcceptedMusicText(AcceptedIdentityScope::ReleaseGroup, 'Reviewed Album', artistCredit: 'Reviewed Artist', releaseId: '44444444-4444-4444-8444-444444444444', tracks: [
            ['mediumPosition' => 1, 'trackPosition' => 1, 'title' => 'Reviewed Track', 'lengthMs' => null, 'artistCredit' => 'Reviewed Artist'],
        ], artists: $this->creditedArtists());
        $reviewed = $this->release('Reviewed.Release-GRP');
        $conflicted = $this->release('Conflicted.Release-GRP');
        $this->persist($this->evidenceRecord($reviewed, 1), IdentificationStatus::NeedsReview, text: $text);
        $this->persist($this->evidenceRecord($conflicted, 1), IdentificationStatus::Conflicted, text: $text);

        foreach (['Reviewed Album', 'Reviewed Artist', 'Reviewed Track', 'Canonical Example Band', 'Altname Ensemble'] as $query) {
            $this->assertSame([], $this->found($query), $query);
        }
    }

    #[Test]
    public function a_release_linked_only_through_musicinfo_is_still_found(): void
    {
        $releaseId = $this->release('Legacy.Only.Release-GRP', musicInfoId: 7);

        $this->assertSame([$releaseId], $this->found('Legacy Album'));
        $this->assertSame([$releaseId], $this->found('Legacy Artist'));
    }

    #[Test]
    public function group_age_category_size_sort_offset_and_limit_apply_to_both_kinds_of_match(): void
    {
        DB::table('usenet_groups')->insert([['id' => 1, 'name' => 'alt.binaries.example'], ['id' => 2, 'name' => 'alt.binaries.other']]);
        $recent = ['groups_id' => 1, 'postdate' => '2026-10-01 12:00:00', 'size' => 2000];
        $recorded = [
            'small' => $this->release('Small.Track-GRP', attributes: ['size' => 500] + $recent),
            'mp3' => $this->release('Mp3.Track-GRP', attributes: ['categories_id' => 3010] + $recent),
            'otherGroup' => $this->release('Other.Group.Track-GRP', attributes: ['groups_id' => 2] + $recent),
            'old' => $this->release('Old.Track-GRP', attributes: ['postdate' => '2026-08-01 12:00:00'] + $recent),
            'first' => $this->release('First.Track-GRP', attributes: $recent),
            'last' => $this->release('Last.Track-GRP', attributes: ['size' => 5000] + $recent),
        ];
        foreach ($recorded as $releaseId) {
            $this->resolveAndStore($releaseId, $this->recordingCandidate(), $this->recordingEvidence());
        }
        $legacyOnly = $this->release('Legacy.Linked-GRP', musicInfoId: 8, attributes: ['size' => 4000] + $recent);
        $this->release('Legacy.Other.Group-GRP', musicInfoId: 8, attributes: ['groups_id' => 2] + $recent);
        $this->release('Legacy.Old-GRP', musicInfoId: 8, attributes: ['postdate' => '2026-08-01 12:00:00'] + $recent);
        DB::table('musicinfo')->insert(['id' => 8, 'title' => 'Recorded Track', 'artist' => 'Legacy Artist']);
        $this->fakeSearchIndex();

        $page = (new ReleaseSearchService)->apiMusicSearch('Recorded Track', 'alt.binaries.example', 1, 2, 30, [3010], [3040], 1000, 'size_asc');

        $this->assertSame([$legacyOnly, $recorded['last']], array_map(static fn (object $row): int => (int) $row->id, $page->all()));
        $this->assertSame(3, (int) $page[0]->_totalrows, 'first, the legacy-only release and last match after the filters');
        $criteria = end($this->queries)->criteria();
        $this->assertTrue($criteria['music_text_only']);
        $this->assertFalse($criteria['try_fuzzy']);
        $this->assertSame(1, $criteria['groups_id']);
        $this->assertSame(30, $criteria['max_age_days']);
        $this->assertSame([3040], $criteria['category_ids']);
        $this->assertSame([3010], $criteria['excluded_category_ids']);
        $this->assertSame(1000, $criteria['min_size']);
        $this->assertSame(['size', 'asc'], [$criteria['sort_field'], $criteria['sort_dir']]);
    }

    #[Test]
    public function a_music_text_match_has_exactly_the_v1_and_v2_fields_of_a_legacy_match(): void
    {
        $recorded = $this->release('Recorded.Release-GRP');
        $this->resolveAndStore($recorded, $this->recordingCandidate(), $this->recordingEvidence());
        $legacy = $this->release('Legacy.Release-GRP', musicInfoId: 7);

        $musicRow = $this->search('Recorded Track')[0];
        $legacyRow = $this->search('Legacy Album')[0];

        $fields = static fn (object $row): array => array_keys(array_diff_key((array) $row, ['_totalrows' => true]));
        $this->assertSame($fields($legacyRow), $fields($musicRow));
        $user = (new User)->forceFill(['id' => 1, 'api_token' => 'test-token']);
        $v2 = static fn (object $row): array => array_keys(ReleaseData::toArrayFromRelease($row, $user, 'https://indexer.example.test/details/', 'https://indexer.example.test/getnzb'));
        $this->assertSame($v2($legacyRow), $v2($musicRow));
        $this->assertSame([$recorded, $legacy], [(int) $musicRow->id, (int) $legacyRow->id]);
    }

    #[Test]
    public function the_v1_and_v2_music_endpoints_return_an_album_found_release_exactly_as_its_legacy_match(): void
    {
        $token = $this->apiUser();
        $releaseId = $this->release('Obfuscated.Album-GRP', musicInfoId: 7, attributes: ['groups_id' => 1, 'postdate' => '2026-10-01 12:00:00']);
        $this->resolveAndStore($releaseId, $this->albumCandidate(), $this->albumEvidence());
        $guid = (string) DB::table('releases')->where('id', $releaseId)->value('guid');

        $items = [];
        foreach (['Legacy Album', 'Example Album', 'Example Artist', 'First Light', 'Altname Ensemble'] as $text) {
            $this->fakeSearchIndex();
            $xml = $this->get('/api/v1/api?'.http_build_query(['t' => 'music', 'apikey' => $token, 'q' => $text, 'extended' => 1]))->assertOk()->getContent();
            $this->fakeSearchIndex();
            $json = $this->get('/api/v1/api?'.http_build_query(['t' => 'music', 'apikey' => $token, 'q' => $text, 'extended' => 1, 'o' => 'json']))->assertOk()->json();
            $this->fakeSearchIndex();
            $v2 = $this->get('/api/v2/audio?'.http_build_query(['api_token' => $token, 'id' => $text]))->assertOk()->json();

            $this->assertSame(1, preg_match_all('#<item>.*?</item>#s', (string) $xml, $xmlItems), $text);
            $this->assertStringContainsString($guid, $xmlItems[0][0]);
            $this->assertCount(1, $v2['results'], $text);
            $items[$text] = ['xml' => $xmlItems[0][0], 'json' => $json['channel']['item'] ?? $json['item'] ?? $json, 'v2' => $v2['results']];
        }

        foreach (['Example Album', 'Example Artist', 'First Light', 'Altname Ensemble'] as $text) {
            $this->assertSame($items['Legacy Album'], $items[$text], $text.' returns the release exactly as the legacy album does');
        }
        $this->assertStringNotContainsString('Example Album', json_encode(array_values($items), JSON_THROW_ON_ERROR), 'no MusicBrainz text enters a response');
        $this->assertStringNotContainsString('Altname', json_encode(array_values($items), JSON_THROW_ON_ERROR), 'no artist alias enters a response');
    }

    /** An API user with today's role limits; returns its token. */
    private function apiUser(): string
    {
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        foreach (['roles', 'users', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions', 'user_requests', 'user_downloads', 'user_excluded_categories', 'registration_periods'] as $table) {
            ProductionTables::fromAuthority()->create($table);
        }
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.example']);
        DB::table('roles')->insert([
            'id' => 1, 'name' => 'User', 'guard_name' => 'web', 'rate_limit' => 60, 'apirequests' => 1000, 'downloadrequests' => 100,
            'addyears' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $token = str_repeat('a', 32);
        $userId = DB::table('users')->insertGetId([
            'username' => 'music_api_user', 'email' => 'music-api@example.test', 'password' => bcrypt('secret'), 'roles_id' => 1,
            'api_token' => $token, 'verified' => 1, 'email_verified_at' => now(), 'rate_limit' => 60, 'created_at' => now(), 'updated_at' => now(),
        ]);
        // The Audio root is visible to a user whose role and own permissions both allow it.
        DB::table('permissions')->insert(['id' => 1, 'name' => 'view audio', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('role_has_permissions')->insert(['permission_id' => 1, 'role_id' => 1]);
        DB::table('model_has_roles')->insert(['role_id' => 1, 'model_type' => User::class, 'model_id' => $userId]);
        DB::table('model_has_permissions')->insert(['permission_id' => 1, 'model_type' => User::class, 'model_id' => $userId]);

        return $token;
    }

    /** @return list<int> release ids, oldest first */
    private function found(string $text): array
    {
        $ids = array_map(static fn (object $row): int => (int) $row->id, $this->search($text)->all());
        sort($ids);

        return $ids;
    }

    private function search(string $text): mixed
    {
        $this->fakeSearchIndex();

        return (new ReleaseSearchService)->apiMusicSearch($text, -1, 0, 100, 0, [], [-1], 0);
    }

    /** The music metadata index matches musicinfo title and artist; the release index matches each release's projected music fields. */
    private function fakeSearchIndex(): void
    {
        Cache::flush();
        $this->queries = [];
        $search = Mockery::mock(SearchService::class, [$this->app]);
        $search->shouldReceive('isAvailable')->andReturn(true);
        $search->shouldReceive('getCurrentDriver')->andReturn('manticore');
        $search->shouldReceive('searchSecondary')->andReturnUsing(function (SecondarySearchIndex $index, string $query, int $limit): array {
            $this->assertSame(SecondarySearchIndex::Music, $index);
            $ids = DB::table('musicinfo')->get()
                ->filter(fn (object $row): bool => $this->containsEveryWord($query, $row->title.' '.$row->artist))
                ->map(static fn (object $row): int => (int) $row->id)->values()->all();

            return ['id' => array_slice($ids, 0, $limit), 'data' => []];
        });
        $search->shouldReceive('searchReleasePage')->andReturnUsing(function (ReleaseSearchQuery $query): SearchPage {
            $this->queries[] = $query;
            $criteria = $query->criteria();
            $this->assertTrue($criteria['music_text_only'], 'music search reads only the music text fields');
            $ids = [];
            foreach (DB::table('releases')->pluck('id') as $releaseId) {
                $document = ReleaseIndexProjection::forId((int) $releaseId);
                $this->assertNotNull($document);
                $music = implode(' ', array_map(static fn (string $field): string => (string) $document[$field], ['album_title', 'artist', 'music_tracks']));
                if ($this->containsEveryWord((string) $criteria['phrases'], $music)) {
                    $ids[] = (int) $releaseId;
                }
            }

            return new SearchPage(array_slice($ids, $query->offset, $query->limit), count($ids), false, 'manticore');
        });
        Search::swap($search);
    }

    /** Every query word appears among the text's words, case-insensitively. */
    private function containsEveryWord(string $query, string $text): bool
    {
        $words = static fn (string $value): array => preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_diff($words($query), $words($text)) === [];
    }
}
