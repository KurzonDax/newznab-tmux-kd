<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Models\Release;
use App\Services\ReleaseRepair\EvidenceChangedTransition;
use App\Services\ReleaseRepair\NzbRepairDocument;
use App\Services\Search\Contracts\SearchDriverInterface;
use App\Services\Search\Drivers\ManticoreSearchDriver;
use App\Services\Search\SearchService;
use Illuminate\Support\Facades\DB;
use Manticoresearch\Client;
use Manticoresearch\Exceptions\ResponseException;
use Manticoresearch\Request;
use Manticoresearch\Response;
use Manticoresearch\Table;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/**
 * A recovery transition that changes a release's indexed fields re-syncs its Manticore
 * document. When Manticore rejects that write, the release enters the failure ledger and
 * the existing repair command retries it (issue #1117).
 */
final class ManticoreRecoverySyncFailureTest extends TestCase
{
    private const int RETRY_ATTEMPTS = 2;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge();
        DB::reconnect();

        // ReleaseIndexProjection and ReleaseDerivedFacts read these tables before the write.
        foreach ([
            'releases', 'usenet_groups', 'categories', 'root_categories', 'movieinfo', 'musicinfo', 'consoleinfo', 'gamesinfo',
            'bookinfo', 'videos', 'tv_episodes', 'release_tv_episodes', 'release_nfos', 'video_data', 'media_infos', 'release_files',
            'audio_data', 'languages', 'release_audio_languages', 'release_subtitles', 'anidb_titles', 'media_info_probes',
            'media_info_tracks', 'release_audio_tags', 'release_audio_evidence', 'release_music_identifications',
            'musicbrainz_release_group_genres', 'musicbrainz_release_tracks', 'musicbrainz_artists', 'musicbrainz_artist_aliases',
            'release_music_identification_artists', 'release_music_candidate_attempts', 'search_index_failures',
        ] as $table) {
            ProductionTables::fromAuthority()->create($table);
        }
    }

    #[Test]
    public function a_failed_recovery_sync_is_retried_by_the_existing_repair_command(): void
    {
        $table = $this->createMock(Table::class);
        $table->expects($this->exactly(self::RETRY_ATTEMPTS))
            ->method('replaceDocument')
            ->willThrowException($this->rejection());
        $client = $this->createMock(Client::class);
        $client->method('table')->with('releases_rt')->willReturn($table);
        $driver = new ManticoreSearchDriver([
            'host' => '127.0.0.1',
            'port' => 9308,
            'retry_attempts' => self::RETRY_ATTEMPTS,
            'retry_delay_ms' => 0,
            'indexes' => ['releases' => 'releases_rt', 'predb' => 'predb_rt'],
        ]);
        $driver->manticoreSearch = $client;
        config(['search.default' => 'manticore']);
        app(SearchService::class)->forgetDrivers();
        app(SearchService::class)->extend('manticore', fn (): SearchDriverInterface => $driver);

        DB::table('releases')->insert([
            'id' => 49,
            'name' => 'Recovery.Release-GRP',
            'searchname' => 'Recovery.Release-GRP',
            'guid' => 'recovery-release',
            'completion' => 50.0,
            'totalpart' => 0,
            'nfostatus' => 0,
        ]);
        $candidate = Release::query()->select(['id', 'guid', 'completion', 'haspreview'])->findOrFail(49);
        $document = NzbRepairDocument::load(
            '<?xml version="1.0"?><nzb><file subject="Recovery (1/1)"><segments><segment bytes="100" number="1">part@example.test</segment></segments></file></nzb>',
        );
        $this->assertNotNull($document);

        (new EvidenceChangedTransition)->apply($candidate, $document);

        $failure = DB::table('search_index_failures')->where('release_id', 49)->first();
        $this->assertNotNull($failure);
        $this->assertSame('insertRelease', $failure->last_error);
        $this->assertNull($failure->resolved_at);
        DB::table('search_index_failures')->where('release_id', 49)->update([
            'next_attempt_at' => now()->subSecond(),
        ]);

        Search::spy();
        $this->artisan('nntmux:search-repair')->assertSuccessful();

        Search::shouldHaveReceived('updateRelease')->once()->with(49);
    }

    private function rejection(): ResponseException
    {
        $response = $this->createStub(Response::class);
        $response->method('getError')->willReturn('rejected');

        return new ResponseException($this->createStub(Request::class), $response);
    }
}
