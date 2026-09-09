<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Services\Categorization\CategorizationPipeline;
use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\Pipes\AbstractCategorizationPipe;
use App\Services\Categorization\ReleaseContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

class SeasonEpisodeNeverMoviesTest extends TestCase
{
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->boolean('route_obfuscated_names')->default(false);
            $table->unsignedInteger('obfuscated_default_root_categories_id')->nullable();
            $table->unsignedInteger('forced_root_categories_id')->nullable();
        });
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.boneless']);
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_four_digit_season_episode_is_tv_sd(): void
    {
        $result = CategorizationPipeline::createDefault()->categorize(
            1,
            'Dangerous.Encounters.(2005).-.S2010E05.-.Cannibal.Squid.[SDTV][MP3.2.0][XviD]-KAFFEREP',
            debug: true,
        );

        $this->assertSame(Category::TV_SD, $result['categories_id']);
    }

    public function test_episode_token_overrides_movie_group_tie(): void
    {
        DB::table('usenet_groups')->where('id', 1)->update(['name' => 'alt.binaries.dvd']);

        $result = CategorizationPipeline::createDefault()->categorize(
            1, 'Murdoch Mysteries S02E13 Anything You Can Do', debug: true,
        );

        $this->assertSame(Category::TV_OTHER, $result['categories_id']);
        $this->assertSame('tv_token_over_movies', $result['debug']['matched_by']);
        $this->assertSame('group_name_movie', $result['debug']['categorizer_details']['original_matched_by']);
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function releaseCategories(): array
    {
        return [
            'season pack' => ['Murdoch Mysteries S02', Category::TV_OTHER],
            'year season pack' => ['Example Show S2010', Category::TV_OTHER],
            'dotted episode' => ['Murdoch Mysteries S02.E13 Anything You Can Do', Category::TV_OTHER],
            'multiple episodes' => ['Murdoch Mysteries S02E12E13 Anything You Can Do', Category::TV_OTHER],
            'historical season' => ['Popeye.The.Sailor.(1933).-.S1946E04.-.Peep.In.The.Deep.[Bluray-1080p.Remux][DTS-HD.MA.2.0][AVC]-EPSiLON', Category::TV_HD],
            'ordinary movie' => ['Movie.Name.2019.1080p.BluRay.x264-GRP', Category::MOVIE_HD],
            'adult episode stays adult' => ['Brazzers.Show.S2010E05', Category::XXX_OTHER],
            'misc hash stays locked' => [str_repeat('a1b2', 8), Category::OTHER_HASHED],
        ];
    }

    #[DataProvider('releaseCategories')]
    public function test_movie_group_does_not_override_release_evidence(string $name, int $expected): void
    {
        DB::table('usenet_groups')->where('id', 1)->update(['name' => 'alt.binaries.dvd']);

        $result = CategorizationPipeline::createDefault()->categorize(1, $name, debug: true);

        $this->assertSame($expected, $result['categories_id']);
        if (Category::rootCategoryFor($expected) !== Category::TV_ROOT) {
            $this->assertArrayNotHasKey('TvTokenOverMovies', $result['debug']['all_results']);
        }
    }

    public function test_explicit_forced_movie_root_applies_after_tv_guard(): void
    {
        DB::table('usenet_groups')->where('id', 1)->update([
            'name' => 'alt.binaries.dvd',
            'forced_root_categories_id' => Category::MOVIE_ROOT,
        ]);

        $result = CategorizationPipeline::createDefault()->categorize(1, 'Murdoch Mysteries S02', debug: true);

        $this->assertSame(Category::MOVIE_OTHER, $result['categories_id']);
        $this->assertSame('group_forced_root', $result['debug']['matched_by']);
        $this->assertSame('tv_token_over_movies', $result['debug']['categorizer_details']['organic_match']);
    }

    public function test_guard_runs_even_when_a_movie_pipe_stops_the_pipeline_early(): void
    {
        $moviePipe = new class extends AbstractCategorizationPipe
        {
            public function getName(): string
            {
                return 'MovieEvidence';
            }

            protected function categorize(ReleaseContext $context): CategorizationResult
            {
                return new CategorizationResult(Category::MOVIE_HD, 1.0, 'strong_movie');
            }
        };

        $result = (new CategorizationPipeline([$moviePipe]))->categorize(1, 'Example.Show.S2010E05.1080p', debug: true);

        $this->assertSame(Category::TV_HD, $result['categories_id']);
        $this->assertSame('tv_token_over_movies', $result['debug']['matched_by']);
        $this->assertSame('strong_movie', $result['debug']['all_results']['TvTokenOverMovies']['original_matched_by']);
    }
}
