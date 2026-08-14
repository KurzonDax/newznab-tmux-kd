<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Category;
use App\Models\Release;
use App\Services\AdditionalProcessing\Config\PasswordInspectionMode;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RequeueMissingVideoPreviewsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge();
        DB::reconnect();

        Schema::create('releases', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('searchname');
            $table->string('fromname');
            $table->dateTime('postdate');
            $table->dateTime('adddate');
            $table->string('guid');
            $table->unsignedInteger('categories_id');
            $table->integer('nzbstatus');
            $table->integer('haspreview');
            $table->integer('passwordstatus');
            $table->integer('rarinnerfilecount');
            $table->integer('isrenamed');
        });
    }

    public function test_it_dry_runs_then_requeues_only_stuck_non_rar_video_releases_idempotently(): void
    {
        $this->setPasswordInspection(true);

        Release::withoutEvents(function (): void {
            Release::factory()->create($this->release(1, Category::TV_WEBDL));
            Release::factory()->create($this->release(2, Category::MOVIE_HD));
            Release::factory()->create($this->release(3, Category::TV_HD, hasPreview: 1));
            Release::factory()->create($this->release(4, Category::MOVIE_WEBDL, rarInnerFileCount: 2));
            Release::factory()->create($this->release(5, Category::TV_UHD, hasPreview: -1, passwordStatus: -1));
            Release::factory()->create($this->release(6, Category::MUSIC_MP3));
            Release::factory()->create($this->release(7, Category::TV_SD, passwordStatus: -1));
        });

        $this->artisan('releases:requeue-missing-video-previews', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run: 2 releases would be re-queued.')
            ->expectsOutputToContain('Dry run: 0 stranded releases would be repaired.')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('releases')->where('id', 1)->value('haspreview'));
        $this->assertSame(0, DB::table('releases')->where('id', 1)->value('passwordstatus'));

        $this->artisan('releases:requeue-missing-video-previews', ['--apply' => true])
            ->expectsOutputToContain('Re-queued 2 releases.')
            ->expectsOutputToContain('Repaired 0 stranded releases.')
            ->assertSuccessful();

        $pending = PasswordInspectionMode::pendingReleaseStatus();
        $this->assertSame(-1, $pending);

        $this->assertSame(-1, DB::table('releases')->where('id', 1)->value('haspreview'));
        $this->assertSame($pending, DB::table('releases')->where('id', 1)->value('passwordstatus'));
        $this->assertSame(-1, DB::table('releases')->where('id', 2)->value('haspreview'));
        $this->assertSame(1, DB::table('releases')->where('id', 3)->value('haspreview'));
        $this->assertSame(0, DB::table('releases')->where('id', 4)->value('haspreview'));
        $this->assertSame(-1, DB::table('releases')->where('id', 5)->value('haspreview'));
        $this->assertSame(0, DB::table('releases')->where('id', 6)->value('haspreview'));
        $this->assertSame(0, DB::table('releases')->where('id', 7)->value('haspreview'));

        $this->artisan('releases:requeue-missing-video-previews', ['--apply' => true])
            ->expectsOutputToContain('Re-queued 0 releases.')
            ->assertSuccessful();
    }

    public function test_apply_writes_the_mode_aware_pending_status_when_inspection_is_disabled(): void
    {
        $this->setPasswordInspection(false);

        Release::withoutEvents(function (): void {
            Release::factory()->create($this->release(1, Category::TV_WEBDL));
            Release::factory()->create($this->release(2, Category::MOVIE_HD));
        });

        $this->artisan('releases:requeue-missing-video-previews', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run: 2 releases would be re-queued.')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('releases')->where('id', 1)->value('haspreview'));

        $this->artisan('releases:requeue-missing-video-previews', ['--apply' => true])
            ->expectsOutputToContain('Re-queued 2 releases.')
            ->assertSuccessful();

        $pending = PasswordInspectionMode::pendingReleaseStatus();
        $this->assertSame(0, $pending);

        foreach ([1, 2] as $id) {
            $this->assertSame(-1, DB::table('releases')->where('id', $id)->value('haspreview'));
            $this->assertSame($pending, DB::table('releases')->where('id', $id)->value('passwordstatus'));
        }
    }

    public function test_apply_repairs_rows_stranded_by_prior_runs_when_inspection_is_disabled(): void
    {
        $this->setPasswordInspection(false);

        Release::withoutEvents(function (): void {
            // Stranded by a prior --apply run under the old hardcoded sentinel.
            Release::factory()->create($this->release(1, Category::TV_WEBDL, hasPreview: -1, passwordStatus: -1));
            Release::factory()->create($this->release(2, Category::MOVIE_HD, hasPreview: -1, passwordStatus: -1));
            // Outside the command's category scope: must not be touched.
            Release::factory()->create($this->release(3, Category::MUSIC_MP3, hasPreview: -1, passwordStatus: -1));
            // Inner rar files: outside the candidate universe.
            Release::factory()->create($this->release(4, Category::TV_HD, hasPreview: -1, passwordStatus: -1, rarInnerFileCount: 2));
            // Pending with the correct sentinel already: nothing to repair.
            Release::factory()->create($this->release(5, Category::TV_UHD, hasPreview: -1));
            // Genuinely passworded: never touched.
            Release::factory()->create($this->release(6, Category::MOVIE_WEBDL, hasPreview: -1, passwordStatus: 1));
        });

        $this->artisan('releases:requeue-missing-video-previews', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run: 2 stranded releases would be repaired.')
            ->assertSuccessful();

        $this->assertSame(-1, DB::table('releases')->where('id', 1)->value('passwordstatus'));

        $this->artisan('releases:requeue-missing-video-previews', ['--apply' => true])
            ->expectsOutputToContain('Repaired 2 stranded releases.')
            ->assertSuccessful();

        $pending = PasswordInspectionMode::pendingReleaseStatus();

        foreach ([1, 2] as $id) {
            $this->assertSame(-1, DB::table('releases')->where('id', $id)->value('haspreview'));
            $this->assertSame($pending, DB::table('releases')->where('id', $id)->value('passwordstatus'));
        }

        $this->assertSame(-1, DB::table('releases')->where('id', 3)->value('passwordstatus'));
        $this->assertSame(-1, DB::table('releases')->where('id', 4)->value('passwordstatus'));
        $this->assertSame($pending, DB::table('releases')->where('id', 5)->value('passwordstatus'));
        $this->assertSame(1, DB::table('releases')->where('id', 6)->value('passwordstatus'));

        $this->artisan('releases:requeue-missing-video-previews', ['--apply' => true])
            ->expectsOutputToContain('Repaired 0 stranded releases.')
            ->assertSuccessful();
    }

    public function test_apply_repairs_rows_stranded_by_a_mode_flip_when_inspection_is_enabled(): void
    {
        $this->setPasswordInspection(true);

        Release::withoutEvents(function (): void {
            // Pending preview but carrying the inspection-off sentinel the
            // active mode never selects.
            Release::factory()->create($this->release(1, Category::MOVIE_HD, hasPreview: -1, passwordStatus: 0));
            // Correct sentinel for the active mode: left alone.
            Release::factory()->create($this->release(2, Category::TV_UHD, hasPreview: -1, passwordStatus: -1));
        });

        $this->artisan('releases:requeue-missing-video-previews', ['--apply' => true])
            ->expectsOutputToContain('Repaired 1 stranded releases.')
            ->assertSuccessful();

        $this->assertSame(-1, DB::table('releases')->where('id', 1)->value('passwordstatus'));
        $this->assertSame(-1, DB::table('releases')->where('id', 2)->value('passwordstatus'));
    }

    public function test_it_rejects_conflicting_execution_modes(): void
    {
        $this->artisan('releases:requeue-missing-video-previews', [
            '--dry-run' => true,
            '--apply' => true,
        ])
            ->expectsOutputToContain('Choose either --dry-run or --apply, not both.')
            ->assertFailed();
    }

    private function setPasswordInspection(bool $enabled): void
    {
        config([
            'nntmux_settings.check_passworded_rars' => $enabled,
            'nntmux_settings.unrar_path' => '/usr/bin/unrar',
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function release(
        int $id,
        int $categoryId,
        int $hasPreview = 0,
        int $passwordStatus = 0,
        int $rarInnerFileCount = 0,
    ): array {
        return [
            'id' => $id,
            'categories_id' => $categoryId,
            'haspreview' => $hasPreview,
            'passwordstatus' => $passwordStatus,
            'rarinnerfilecount' => $rarInnerFileCount,
        ];
    }
}
