<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\View\Composers\GlobalDataComposer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestView;
use ReflectionProperty;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

class CoverListingArtworkTest extends TestCase
{
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->withoutVite();
        Cache::flush();
        (new ReflectionProperty(GlobalDataComposer::class, 'resolvedData'))->setValue(null, null);

        Schema::create('content', function (Blueprint $table): void {
            $table->id();
            $table->integer('status');
            $table->integer('contenttype');
            $table->integer('ordinal');
        });

        config(['nntmux_settings.covers_path' => $this->makeTempDirectory('listing-covers')]);
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(GlobalDataComposer::class, 'resolvedData'))->setValue(null, null);
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_audio_listing_uses_the_album_id_and_existing_image_extension(): void
    {
        $this->createCover('music', '42.webp');

        $this->renderListing('music', 42)
            ->assertSee('src="'.url('/covers/music/42.webp').'"', false)
            ->assertDontSee('src="'.url('/covers/music/1').'"', false);
    }

    public function test_games_listing_uses_the_game_id_and_existing_image_extension(): void
    {
        $this->createCover('games', '73.jpg');

        $this->renderListing('games', 73)
            ->assertSee('src="'.url('/covers/games/73.jpg').'"', false)
            ->assertDontSee('src="'.url('/covers/games/1').'"', false);
    }

    private function createCover(string $type, string $filename): void
    {
        $directory = config('nntmux_settings.covers_path').'/'.$type;
        mkdir($directory);
        file_put_contents($directory.'/'.$filename, 'image fixture');
    }

    private function renderListing(string $type, int $id): TestView
    {
        $entity = (object) [
            'id' => $id,
            'title' => 'Example title',
            'artist' => 'Example artist',
            'cover' => 1,
            'releases' => [(object) ['guid' => 'example-release', $type.'info_id' => $id]],
        ];
        $results = new LengthAwarePaginator([$entity], 1, 48);

        return $this->view($type.'.index', [
            'results' => $results,
            'resultsadd' => $results,
            'categorytitle' => 'All',
        ]);
    }
}
