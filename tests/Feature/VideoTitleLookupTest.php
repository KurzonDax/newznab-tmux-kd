<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\TvProcessing\Providers\LocalDbProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class VideoTitleLookupTest extends TestCase
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

        Schema::create('videos', function (Blueprint $table): void {
            $table->increments('id');
            $table->tinyInteger('type')->default(0);
            $table->string('title');
            $table->tinyInteger('source')->default(0);
        });
        Schema::create('videos_aliases', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('videos_id');
            $table->string('title');
        });
    }

    #[Test]
    public function it_matches_a_year_suffixed_cleanname_to_a_colon_title(): void
    {
        $videoId = $this->createVideo('Batman: Caped Crusader');

        $this->assertSame(
            $videoId,
            (new LocalDbProvider)->getByTitle('Batman Caped Crusader (2024)', 0),
        );
    }

    #[Test]
    public function it_preserves_matching_a_cleanname_without_a_year(): void
    {
        $videoId = $this->createVideo('Batman: Caped Crusader');

        $this->assertSame(
            $videoId,
            (new LocalDbProvider)->getByTitle('Batman Caped Crusader', 0),
        );
    }

    #[Test]
    public function it_preserves_year_stripped_exact_match_precedence(): void
    {
        $expectedVideoId = $this->createVideo('Batman Caped Crusader');
        $this->createVideo('Batman: Caped Crusader (2024)');

        $this->assertSame(
            $expectedVideoId,
            (new LocalDbProvider)->getByTitle('Batman Caped Crusader (2024)', 0),
        );
    }

    #[Test]
    public function it_matches_a_year_suffixed_cleanname_to_an_apostrophe_title(): void
    {
        $videoId = $this->createVideo("Grey's Anatomy");

        $this->assertSame(
            $videoId,
            (new LocalDbProvider)->getByTitle('Greys Anatomy (2005)', 0),
        );
    }

    #[Test]
    public function it_loosely_matches_the_year_stripped_title_variant(): void
    {
        $videoId = $this->createVideo('Batman: The Caped Crusader');

        $this->assertSame(
            $videoId,
            (new LocalDbProvider)->getByTitle('Batman Caped Crusader (2024)', 0),
        );
    }

    #[Test]
    public function it_returns_zero_when_no_title_variant_matches(): void
    {
        $this->createVideo('Batman: Caped Crusader');

        $this->assertSame(
            0,
            (new LocalDbProvider)->getByTitle('Unrelated Show (2024)', 0),
        );
    }

    private function createVideo(string $title): int
    {
        return (int) DB::table('videos')->insertGetId([
            'title' => $title,
            'type' => 0,
        ]);
    }
}
