<?php

declare(strict_types=1);

use App\Models\Category;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stores show details from TMDB (language, status, US rating, premiere, network, genres,
 * cast) and fills the network of every existing show from its publisher text. The other
 * details arrive on each show's next release.
 */
return new class extends Migration
{
    /** TMDB's sixteen TV genres after the contract's mapping (Sci-Fi & Fantasy → Sci-Fi + Fantasy, and so on). */
    private const array TV_GENRE_TITLES = [
        'Action', 'Adventure', 'Animation', 'Comedy', 'Crime', 'Documentary', 'Drama', 'Family',
        'Children', 'Mystery', 'News', 'Reality', 'Sci-Fi', 'Fantasy', 'Soap', 'Talk', 'War', 'Western',
    ];

    public function up(): void
    {
        Schema::create('networks', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name', 80);
            $table->unique('name', 'ux_networks_name');
        });

        Schema::create('people', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name', 120);
            $table->unsignedInteger('tmdb_id')->nullable();
            $table->unique('tmdb_id', 'ux_people_tmdb_id');
        });

        Schema::create('video_genres', function (Blueprint $table): void {
            $table->unsignedInteger('videos_id');
            $table->unsignedInteger('genres_id');
            $table->primary(['genres_id', 'videos_id']);
            $table->index('videos_id', 'ix_video_genres_video');
            $table->foreign('genres_id', 'fk_video_genres_genres_id')->references('id')->on('genres')->cascadeOnDelete();
        });

        Schema::create('video_people', function (Blueprint $table): void {
            $table->unsignedInteger('videos_id');
            $table->unsignedInteger('people_id');
            $table->unsignedTinyInteger('position')->comment('0-based cast rank in TMDB order');
            $table->primary(['people_id', 'videos_id']);
            $table->index(['videos_id', 'position'], 'ix_video_people_video');
            $table->foreign('people_id', 'fk_video_people_people_id')->references('id')->on('people')->cascadeOnDelete();
        });

        Schema::table('tv_info', function (Blueprint $table): void {
            $table->string('original_language', 8)->default('')->comment('TMDB original_language (ISO 639-1)');
            $table->unsignedTinyInteger('status')->default(0)->comment('0 unknown, 1 running, 2 ended');
            $table->string('content_rating_us', 8)->default('')->comment('TMDB US content rating');
            $table->date('premiered')->nullable()->comment('TMDB first_air_date');
            $table->unsignedInteger('networks_id')->nullable();
            $table->timestamp('details_refreshed_at')->nullable()->comment('When TMDB details were last fetched; NULL = never');
            $table->index(['premiered', 'videos_id'], 'ix_tv_info_premiered');
            $table->foreign('networks_id')->references('id')->on('networks')->nullOnDelete();
        });

        $this->insertTvGenres();
        $this->fillNetworksFromPublisher();
    }

    public function down(): void
    {
        Schema::table('tv_info', function (Blueprint $table): void {
            $table->dropForeign(['networks_id']);
            $table->dropIndex('ix_tv_info_premiered');
            $table->dropColumn(['original_language', 'status', 'content_rating_us', 'premiered', 'networks_id', 'details_refreshed_at']);
        });
        Schema::dropIfExists('video_people');
        Schema::dropIfExists('video_genres');
        Schema::dropIfExists('people');
        Schema::dropIfExists('networks');
        DB::table('genres')->where('type', Category::TV_ROOT)->delete();
    }

    private function insertTvGenres(): void
    {
        $existing = DB::table('genres')->where('type', Category::TV_ROOT)->pluck('title')->all();
        $rows = [];
        foreach (self::TV_GENRE_TITLES as $title) {
            if (! in_array($title, $existing, true)) {
                $rows[] = ['title' => $title, 'type' => Category::TV_ROOT, 'disabled' => 0];
            }
        }
        DB::table('genres')->insert($rows);
    }

    /**
     * One network per distinct trimmed, lower-cased publisher; the spelling kept is the
     * one on the lowest videos_id. Empty publishers keep networks_id NULL.
     */
    private function fillNetworksFromPublisher(): void
    {
        $groups = [];
        foreach (DB::table('tv_info')->select(['videos_id', 'publisher'])->orderBy('videos_id')->cursor() as $row) {
            $name = trim((string) $row->publisher);
            if ($name === '') {
                continue;
            }
            $key = mb_strtolower($name);
            $groups[$key] ??= ['name' => $name, 'ids' => []];
            $groups[$key]['ids'][] = (int) $row->videos_id;
        }

        foreach ($groups as $group) {
            DB::table('networks')->insertOrIgnore(['name' => $group['name']]);
            $networkId = DB::table('networks')->where('name', $group['name'])->value('id');
            foreach (array_chunk($group['ids'], 1000) as $ids) {
                DB::table('tv_info')->whereIn('videos_id', $ids)->update(['networks_id' => $networkId]);
            }
        }
    }
};
