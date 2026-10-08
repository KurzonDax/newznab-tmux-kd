<?php

declare(strict_types=1);

namespace Tests\Unit\MusicIdentity;

use App\Services\MusicIdentity\CoverArt\AlbumCoverFetcher;
use App\Services\MusicIdentity\CoverArt\CoverArtPacer;
use App\Services\MusicIdentity\CurrentMusicIdentity;
use App\Services\MusicIdentity\Enums\IdentificationStatus;
use GdImage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Cover Art Archive fronts for accepted albums (issue #1015): which image, the outcome kept per
 * lookup, and one lookup at a time across workers.
 */
final class AlbumCoverFetcherTest extends TestCase
{
    private const string BASE = 'https://caa.test';

    private const string EDITION = '33333333-3333-4333-8333-333333333333';

    private const string OTHER_EDITION = '44444444-4444-4444-8444-444444444444';

    private const string GROUP = '11111111-1111-4111-8111-111111111111';

    private string $covers = '';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'music-identity.cover_art.base_url' => self::BASE,
            'music-identity.cover_art.min_interval_milliseconds' => 1_000,
            'music-identity.cover_art.lock_wait_seconds' => 10,
            'music-identity.cover_art.retry.initial_seconds' => 3_600,
            'music-identity.cover_art.retry.maximum_seconds' => 86_400,
        ]);
        DB::purge();
        DB::reconnect();
        $this->migration('*_create_music_cover_art_lookups_table.php')->up();
        $this->covers = $this->makeTempDirectory('album-cover-fetcher');
        config(['nntmux_settings.covers_path' => $this->covers]);
        Cache::flush();
        Http::preventStrayRequests();
        Carbon::setTestNow('2026-10-08 12:00:00');
        Sleep::fake(syncWithCarbon: true);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function an_edition_uses_its_own_front(): void
    {
        Http::fake([self::BASE.'/release/'.self::EDITION.'/front-500' => Http::response($this->image(), 200, ['Content-Type' => 'image/jpeg'])]);

        app(AlbumCoverFetcher::class)->fetchFor($this->edition(self::EDITION));

        $this->assertLookup('release', self::EDITION, 'stored', self::EDITION);
        $this->assertNotNull(getImageAssetUrl('audio', self::EDITION));
        Http::assertSentCount(1);
    }

    #[Test]
    public function an_edition_without_a_front_falls_back_to_its_release_group_and_releases_of_one_album_share_one_download(): void
    {
        Http::fake([
            self::BASE.'/release/*' => Http::response('', 404),
            self::BASE.'/release-group/'.self::GROUP.'/front-500' => Http::response($this->image(), 200),
        ]);

        app(AlbumCoverFetcher::class)->fetchFor($this->edition(self::EDITION));
        app(AlbumCoverFetcher::class)->fetchFor($this->edition(self::OTHER_EDITION));

        $this->assertLookup('release', self::EDITION, 'stored', self::GROUP);
        $this->assertLookup('release', self::OTHER_EDITION, 'stored', self::GROUP);
        $this->assertLookup('release-group', self::GROUP, 'stored', self::GROUP);
        $this->assertNotNull(getImageAssetUrl('audio', self::GROUP));
        $this->assertNull(getImageAssetUrl('audio', self::EDITION));
        $this->assertSame(1, $this->requestsTo('/release-group/'), 'one download for the album');
        Http::assertSentCount(3);
    }

    #[Test]
    public function an_accepted_release_group_uses_its_own_front(): void
    {
        Http::fake([self::BASE.'/release-group/'.self::GROUP.'/front-500' => Http::response($this->image(), 200)]);

        app(AlbumCoverFetcher::class)->fetchFor($this->group(self::GROUP));

        $this->assertLookup('release-group', self::GROUP, 'stored', self::GROUP);
        Http::assertSentCount(1);
    }

    #[Test]
    public function a_404_records_no_front_image_and_is_never_fetched_again(): void
    {
        Http::fake(['*' => Http::response('', 404)]);

        app(AlbumCoverFetcher::class)->fetchFor($this->group(self::GROUP));
        Carbon::setTestNow(now()->addYear());
        app(AlbumCoverFetcher::class)->fetchFor($this->group(self::GROUP));

        $this->assertLookup('release-group', self::GROUP, 'no_front_image', null);
        Http::assertSentCount(1);
    }

    #[Test]
    public function a_5xx_or_transport_error_records_failed_and_retries_after_backoff(): void
    {
        $answers = [Http::response('', 503), 'transport', Http::response($this->image(), 200)];
        $calls = 0;
        Http::fake(function () use (&$answers, &$calls) {
            $calls++;
            $answer = array_shift($answers);
            if ($answer === 'transport') {
                throw new ConnectionException('Connection refused');
            }

            return $answer;
        });

        app(AlbumCoverFetcher::class)->fetchFor($this->group(self::GROUP));
        $this->assertLookup('release-group', self::GROUP, 'failed', null);
        $first = DB::table('music_cover_art_lookups')->first();
        $this->assertSame('HTTP 503', $first->last_error);
        $this->assertSame(now()->addSeconds(3_600)->toDateTimeString(), Carbon::parse($first->next_attempt_at)->toDateTimeString());

        app(AlbumCoverFetcher::class)->fetchFor($this->group(self::GROUP));
        $this->assertSame(1, $calls, 'not retried before its time');

        Carbon::setTestNow(now()->addSeconds(3_601));
        app(AlbumCoverFetcher::class)->fetchFor($this->group(self::GROUP));
        $second = DB::table('music_cover_art_lookups')->first();
        $this->assertSame(['failed', 2], [$second->outcome, (int) $second->attempt_count]);
        $this->assertStringContainsString('Connection refused', (string) $second->last_error);
        $this->assertSame(now()->addSeconds(7_200)->toDateTimeString(), Carbon::parse($second->next_attempt_at)->toDateTimeString(), 'the backoff doubles');

        Carbon::setTestNow(now()->addSeconds(7_201));
        app(AlbumCoverFetcher::class)->fetchFor($this->group(self::GROUP));
        $this->assertLookup('release-group', self::GROUP, 'stored', self::GROUP);
        $this->assertSame(3, $calls);
    }

    #[Test]
    public function lookups_run_one_at_a_time_at_least_a_second_apart_across_two_workers(): void
    {
        $startedAt = [];
        $lockHeld = [];
        Http::fake(function (Request $request) use (&$startedAt, &$lockHeld) {
            $startedAt[] = now()->getPreciseTimestamp(3);
            $lockHeld[] = Cache::lock(CoverArtPacer::LOCK, 5)->get() === false;

            return str_contains($request->url(), '/release/') ? Http::response('', 404) : Http::response($this->image(), 200);
        });
        $firstWorker = new AlbumCoverFetcher(new CoverArtPacer);
        $secondWorker = new AlbumCoverFetcher(new CoverArtPacer);

        $firstWorker->fetchFor($this->edition(self::EDITION));
        $secondWorker->fetchFor($this->edition(self::OTHER_EDITION));

        $this->assertCount(3, $startedAt);
        $this->assertSame([true, true, true], $lockHeld, 'every lookup holds the shared lock');
        $this->assertGreaterThanOrEqual(1_000, $startedAt[1] - $startedAt[0], 'the fallback is its own lookup');
        $this->assertGreaterThanOrEqual(1_000, $startedAt[2] - $startedAt[1], 'the second worker waits too');
    }

    #[Test]
    public function a_pacing_lock_timeout_defers_the_cover_without_an_outcome(): void
    {
        config(['music-identity.cover_art.lock_wait_seconds' => 0]);
        Http::fake();
        $held = Cache::lock(CoverArtPacer::LOCK, 60);
        $this->assertTrue($held->get());

        app(AlbumCoverFetcher::class)->fetchFor($this->group(self::GROUP));

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('music_cover_art_lookups')->count());
        $held->release();
    }

    #[Test]
    public function only_an_accepted_album_is_looked_up(): void
    {
        Http::fake();

        app(AlbumCoverFetcher::class)->fetchFor(new CurrentMusicIdentity(1, 1, IdentificationStatus::AcceptedRecording, null, self::GROUP));
        app(AlbumCoverFetcher::class)->fetchFor(new CurrentMusicIdentity(2, 2, IdentificationStatus::Unresolved, null, null));

        Http::assertNothingSent();
    }

    private function edition(string $releaseId): CurrentMusicIdentity
    {
        return new CurrentMusicIdentity(1, 1, IdentificationStatus::AcceptedEdition, $releaseId, self::GROUP);
    }

    private function group(string $groupId): CurrentMusicIdentity
    {
        return new CurrentMusicIdentity(1, 1, IdentificationStatus::AcceptedReleaseGroup, null, $groupId);
    }

    private function assertLookup(string $kind, string $musicBrainzId, string $outcome, ?string $image): void
    {
        $row = DB::table('music_cover_art_lookups')->where('kind', $kind)->where('musicbrainz_id', $musicBrainzId)->first();
        $this->assertNotNull($row, $kind.' '.$musicBrainzId);
        $this->assertSame([$outcome, $image], [$row->outcome, $row->image_musicbrainz_id]);
        $this->assertNotNull($row->checked_at);
    }

    private function requestsTo(string $path): int
    {
        return Http::recorded(static fn (Request $request): bool => str_contains($request->url(), $path))->count();
    }

    private function image(): string
    {
        $image = imagecreatetruecolor(40, 40);
        $this->assertInstanceOf(GdImage::class, $image);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 40, 60));
        ob_start();
        imagejpeg($image, null, 82);

        return (string) ob_get_clean();
    }

    private function migration(string $pattern): Migration
    {
        $paths = glob(database_path('migrations/'.$pattern)) ?: [];
        $this->assertCount(1, $paths);

        /** @var Migration $migration */
        $migration = require $paths[0];

        return $migration;
    }
}
