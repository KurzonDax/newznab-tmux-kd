<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Category;
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
            $table->unsignedInteger('categories_id');
            $table->integer('haspreview');
            $table->integer('passwordstatus');
            $table->integer('rarinnerfilecount');
            $table->timestamps();
        });
    }

    public function test_it_dry_runs_then_requeues_only_stuck_non_rar_video_releases_idempotently(): void
    {
        DB::table('releases')->insert([
            $this->release(1, Category::TV_WEBDL),
            $this->release(2, Category::MOVIE_HD),
            $this->release(3, Category::TV_HD, hasPreview: 1),
            $this->release(4, Category::MOVIE_WEBDL, rarInnerFileCount: 2),
            $this->release(5, Category::TV_UHD, hasPreview: -1, passwordStatus: -1),
            $this->release(6, Category::MUSIC_MP3),
            $this->release(7, Category::TV_SD, passwordStatus: -1),
        ]);

        $this->artisan('releases:requeue-missing-video-previews', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run: 2 releases would be re-queued.')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('releases')->where('id', 1)->value('haspreview'));
        $this->assertSame(0, DB::table('releases')->where('id', 1)->value('passwordstatus'));

        $this->artisan('releases:requeue-missing-video-previews', ['--apply' => true])
            ->expectsOutputToContain('Re-queued 2 releases.')
            ->assertSuccessful();

        $this->assertSame(-1, DB::table('releases')->where('id', 1)->value('haspreview'));
        $this->assertSame(-1, DB::table('releases')->where('id', 1)->value('passwordstatus'));
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
            'created_at' => 0,
            'updated_at' => 0,
        ];
    }
}
