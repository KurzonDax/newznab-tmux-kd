<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReleaseResolution;
use App\Enums\ReleaseSource;
use App\Facades\Search;
use App\Services\Releases\ReleaseDerivedFacts;
use App\Services\Search\Contracts\SearchDriverInterface;
use App\Services\Search\SearchService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Support\ProductionTables;
use Tests\Support\RecordsTransactionStatements;
use Tests\TestCase;

/**
 * Every release change reaches SearchService::updateRelease(), which keeps resolution, source,
 * release_tv_episodes and release_audio_languages in step.
 */
final class ReleaseDerivedFactsTest extends TestCase
{
    use RecordsTransactionStatements;

    /** @var list<array{int, int}> What the search driver saw in `releases` when it was called. */
    private array $indexed = [];

    protected function setUp(): void
    {
        parent::setUp();
        $tables = ProductionTables::fromAuthority();
        $tables->create('releases', ['id', 'searchname', 'categories_id', 'videos_id', 'tv_episodes_id', 'resolution', 'source']);
        $tables->create('tv_episodes', ['id', 'videos_id', 'series', 'episode']);
        $tables->create('release_tv_episodes');
        $tables->create('video_data', ['releases_id', 'videowidth', 'videoheight']);
        $tables->create('media_info_probes');
        $tables->create('media_info_tracks');
        $tables->create('audio_data', ['id', 'releases_id', 'audioid', 'audiolanguage']);
        $tables->create('languages');
        $tables->create('release_audio_languages');

        $driver = Mockery::mock(SearchDriverInterface::class);
        $driver->shouldReceive('updateRelease')->andReturnUsing(function (int|string $id): void {
            $this->indexed[] = $this->facts((int) $id);
        });
        app(SearchService::class)->extend('recording', fn (): SearchDriverInterface => $driver);
        config(['search.default' => 'recording']);
    }

    public function test_a_new_release_gets_both_values_before_it_is_indexed(): void
    {
        $this->insertRelease(1, 'Show.S01E01.1080p.WEB-DL-GRP');

        Search::updateRelease(1);

        $expected = [ReleaseResolution::FullHd->value, ReleaseSource::Web->value];
        $this->assertSame($expected, $this->facts(1));
        $this->assertSame([$expected], $this->indexed);
    }

    public function test_a_rename_and_a_recategorisation_leave_both_values_correct(): void
    {
        $this->insertRelease(1, 'a1b2c3d4e5f6');
        Search::updateRelease(1);
        $this->assertSame([ReleaseResolution::Unknown->value, ReleaseSource::Unknown->value], $this->facts(1));

        DB::table('releases')->where('id', 1)->update(['searchname' => 'Movie.2020.2160p.BluRay.REMUX-GRP']);
        Search::updateRelease('1');
        $this->assertSame([ReleaseResolution::Uhd->value, ReleaseSource::Remux->value], $this->facts(1));

        DB::table('releases')->where('id', 1)->update(['categories_id' => 2045]);
        Search::updateRelease(1);
        $this->assertSame([ReleaseResolution::Uhd->value, ReleaseSource::Remux->value], $this->facts(1));
    }

    public function test_media_info_arriving_later_replaces_the_name_and_a_complete_probe_wins(): void
    {
        $this->insertRelease(1, 'Show.S01E01.720p.HDTV');
        Search::updateRelease(1);
        $this->assertSame([ReleaseResolution::Hd->value, ReleaseSource::Hdtv->value], $this->facts(1));

        DB::table('video_data')->insert(['releases_id' => 1, 'videowidth' => 720, 'videoheight' => 576]);
        Search::updateRelease(1);
        $this->assertSame([ReleaseResolution::Sd->value, ReleaseSource::Hdtv->value], $this->facts(1));

        $this->insertProbe(1, 1, '2026-01-01 00:00:00', 'complete', 1920, 1080);
        $this->insertProbe(2, 1, '2026-01-02 00:00:00', 'partial', 3840, 2160);
        Search::updateRelease(1);
        $this->assertSame([ReleaseResolution::FullHd->value, ReleaseSource::Hdtv->value], $this->facts(1));
    }

    public function test_an_unchanged_release_causes_no_write(): void
    {
        $this->insertRelease(1, 'Show.S01E01.1080p.WEB-DL-GRP');
        Search::updateRelease(1);

        $this->assertSame(0, $this->writesDuring(fn () => Search::updateRelease(1)));
        $this->assertCount(2, $this->indexed);
    }

    public function test_a_release_stores_every_episode_it_names(): void
    {
        $this->insertRelease(1, 'Show.S01E01E02.1080p.WEB-DL-GRP', videosId: 7);
        $this->insertRelease(2, 'Show.S02E03-E05.720p.HDTV-GRP', videosId: 7);
        $this->insertRelease(3, 'Show.S03E00.Special.1080p.WEB-DL-GRP', videosId: 7);

        foreach ([1, 2, 3] as $id) {
            Search::updateRelease($id);
        }

        $this->assertSame([[1, 1], [1, 2]], $this->episodes(1));
        $this->assertSame([[2, 3], [2, 4], [2, 5]], $this->episodes(2));
        $this->assertSame([[3, 0]], $this->episodes(3));
    }

    public function test_a_season_pack_stores_one_row_with_no_episode(): void
    {
        $this->insertRelease(1, 'Show.S02.COMPLETE.1080p.WEB-DL-GRP', videosId: 7);

        Search::updateRelease(1);

        $this->assertSame([[2, null]], $this->episodes(1));
    }

    public function test_a_linked_release_that_names_nothing_takes_its_numbers_from_its_own_shows_episode(): void
    {
        DB::table('tv_episodes')->insert([
            ['id' => 40, 'videos_id' => 7, 'series' => 2024, 'episode' => 117],
            ['id' => 41, 'videos_id' => 8, 'series' => 3, 'episode' => 9],
        ]);
        $this->insertRelease(1, 'Daily.Show.2024.05.01.1080p.WEB-DL-GRP', videosId: 7, tvEpisodesId: 40);
        $this->insertRelease(2, 'Daily.Show.2024.05.02.1080p.WEB-DL-GRP', videosId: 7);
        $this->insertRelease(3, 'Daily.Show.2024.05.03.1080p.WEB-DL-GRP', videosId: 7, tvEpisodesId: 41);

        foreach ([1, 2, 3] as $id) {
            Search::updateRelease($id);
        }

        $this->assertSame([[2024, 117]], $this->episodes(1));
        $this->assertSame([], $this->episodes(2));
        $this->assertSame([], $this->episodes(3), 'An episode of another show is no link.');
    }

    public function test_renaming_matching_and_unmatching_keep_the_rows_correct(): void
    {
        $this->insertRelease(1, 'a1b2c3d4e5f6');
        Search::updateRelease(1);
        $this->assertSame([], $this->episodes(1));

        DB::table('releases')->where('id', 1)->update(['searchname' => 'Show.S01E04.1080p.WEB-DL-GRP', 'videos_id' => 7]);
        Search::updateRelease(1);
        $this->assertSame([[1, 4]], $this->episodes(1));

        DB::table('releases')->where('id', 1)->update(['searchname' => 'Show.S01.COMPLETE.1080p.WEB-DL-GRP']);
        Search::updateRelease(1);
        $this->assertSame([[1, null]], $this->episodes(1));

        DB::table('releases')->where('id', 1)->update(['searchname' => 'Show.S01E00.1080p.WEB-DL-GRP']);
        Search::updateRelease(1);
        $this->assertSame([[1, 0]], $this->episodes(1));

        DB::table('releases')->where('id', 1)->update(['videos_id' => 0]);
        Search::updateRelease(1);
        $this->assertSame([], $this->episodes(1));

        DB::table('releases')->where('id', 1)->update(['videos_id' => 7, 'categories_id' => 2040]);
        Search::updateRelease(1);
        $this->assertSame([], $this->episodes(1));

        DB::table('releases')->where('id', 1)->update(['categories_id' => 5040]);
        Search::updateRelease(1);
        $this->assertSame([[1, 0]], $this->episodes(1));
    }

    public function test_an_unchanged_pack_is_not_rewritten(): void
    {
        $this->insertRelease(1, 'Show.S02.COMPLETE.1080p.WEB-DL-GRP', videosId: 7);
        Search::updateRelease(1);

        $this->assertSame(0, $this->writesDuring(fn () => Search::updateRelease(1)));
        $this->assertSame([[2, null]], $this->episodes(1));
    }

    public function test_the_fill_writes_every_tv_release_with_a_show_and_can_be_rerun(): void
    {
        DB::table('tv_episodes')->insert([
            ['id' => 40, 'videos_id' => 7, 'series' => 3, 'episode' => 9],
            ['id' => 41, 'videos_id' => 8, 'series' => 4, 'episode' => 1],
        ]);
        $this->insertRelease(1, 'Show.S01E01E02.1080p.WEB-DL-GRP', videosId: 7);
        $this->insertRelease(2, 'Show.S02.COMPLETE.1080p.WEB-DL-GRP', videosId: 7);
        $this->insertRelease(3, 'Show.Name.720p.HDTV-GRP', videosId: 7, tvEpisodesId: 40);
        $this->insertRelease(4, 'Show.S01E05.1080p.WEB-DL-GRP');
        $this->insertRelease(5, 'Show.S01E06.1080p.WEB-DL-GRP', videosId: 7, categoriesId: 2040);
        $this->insertRelease(6, 'Show.Name.720p.HDTV-GRP', videosId: 7);
        $this->insertRelease(7, 'Show.Name.1080p.HDTV-GRP', videosId: 7, tvEpisodesId: 41);

        $facts = app(ReleaseDerivedFacts::class);
        $this->assertSame(4, $facts->fillTvEpisodes());
        $this->assertSame(4, $facts->fillTvEpisodes());

        $this->assertSame([[1, 1], [1, 2]], $this->episodes(1));
        $this->assertSame([[2, null]], $this->episodes(2));
        $this->assertSame([[3, 9]], $this->episodes(3));
        $this->assertSame(4, DB::table('release_tv_episodes')->count());
    }

    public function test_the_refill_migration_rewrites_the_rows_of_every_tv_release_with_a_show(): void
    {
        $this->insertRelease(1, 'Supernatural.S01.E19.Provenance.1080p.WEB-DL-GRP', videosId: 7);
        $this->insertRelease(2, 'Popeye the Sailor (1933) - S1940E09 - Popeye Presents Eugene the Jeep', videosId: 7);
        $this->insertRelease(3, 'Regular Show (2010) S01 1080p BluRay 8bit', videosId: 7);
        $this->insertRelease(4, 'Radioactive.Emergency.S01.COMBiNED.720p.WEB-DL-GRP', videosId: 7);
        $this->insertRelease(5, '[Erai-raws] Title S3 - 13 [1080p]', videosId: 7);
        $this->insertRelease(6, 'Bigg Boss S13 - Season 13 - Episode 47.01 Title 1080 x 1920', videosId: 7);
        $this->insertRelease(7, 'The.Show.S7_D2.1080p.DVDR-GRP', videosId: 7);
        $this->insertRelease(8, 'Title.S01-S06.1080p.WEB-DL-GRP', videosId: 7);
        $this->insertRelease(9, 'Show.S03-Bonus.Episode.1080p.WEB-DL-GRP', videosId: 7);
        $this->insertRelease(10, 'Show.S03.[Epi.01-06].720p.BluRay-GRP', videosId: 7);
        DB::table('release_tv_episodes')->insert(['releases_id' => 3, 'season' => 9, 'episode' => 9]);

        (require database_path('migrations/2026_09_25_100000_refill_release_tv_episodes.php'))->up();

        $this->assertSame([[1, 19]], $this->episodes(1));
        $this->assertSame([[1940, 9]], $this->episodes(2));
        $this->assertSame([[1, null]], $this->episodes(3));
        $this->assertSame([[1, null]], $this->episodes(4));
        $this->assertSame([[3, 13]], $this->episodes(5));
        $this->assertSame([[13, 47]], $this->episodes(6));
        foreach ([7, 8, 9, 10] as $id) {
            $this->assertSame([], $this->episodes($id));
        }
    }

    public function test_a_release_stores_the_languages_of_the_selected_probes_audio_tracks(): void
    {
        $this->insertRelease(1, 'Movie.2020.1080p.BluRay.x264-GRP', categoriesId: 2040);
        $this->insertAudioProbe(1, 1, '2026-01-01 00:00:00', 'complete', ['en', 'hin']);
        $this->insertAudioProbe(2, 1, '2026-01-02 00:00:00', 'partial', ['fr']);
        DB::table('audio_data')->insert(['releases_id' => 1, 'audioid' => 1, 'audiolanguage' => 'German']);

        Search::updateRelease(1);

        $this->assertSame(['English', 'Hindi'], $this->audioLanguages(1));
    }

    public function test_the_legacy_audio_rows_are_used_when_the_probe_names_no_language(): void
    {
        $this->insertRelease(1, 'Show.S01E01.1080p.WEB-DL-GRP');
        $this->insertRelease(2, 'Show.S01E02.1080p.WEB-DL-GRP');
        $this->insertRelease(3, 'Show.S01E03.1080p.WEB-DL-GRP');
        $this->insertAudioProbe(1, 2, '2026-01-01 00:00:00', 'complete', ['und', 'zxx', null]);
        $this->insertProbe(2, 3, '2026-01-01 00:00:00', 'complete', 1920, 1080);
        foreach ([1, 2, 3] as $id) {
            DB::table('audio_data')->insert([
                ['releases_id' => $id, 'audioid' => 1, 'audiolanguage' => 'English (US)'],
                ['releases_id' => $id, 'audioid' => 2, 'audiolanguage' => 'Japanese'],
            ]);
        }

        foreach ([1, 2, 3] as $id) {
            Search::updateRelease($id);
        }

        $this->assertSame(['English', 'Japanese'], $this->audioLanguages(1), 'No probe.');
        $this->assertSame(['English', 'Japanese'], $this->audioLanguages(2), 'The probe names no language.');
        $this->assertSame(['English', 'Japanese'], $this->audioLanguages(3), 'The probe has no audio track.');
    }

    public function test_audio_languages_follow_the_name_rule(): void
    {
        $this->insertRelease(1, 'Movie.2020.MULTi.1080p.BluRay.x264-GRP', categoriesId: 2040);
        $this->insertRelease(2, 'Movie.2020.1080p.BluRay.x264-GRP', categoriesId: 2040);
        $this->insertRelease(3, 'Album-2020-FLAC', categoriesId: 3040);
        $this->insertRelease(4, 'Movie.2021.1080p.BluRay.x264-GRP', categoriesId: 2040);
        $this->insertAudioProbe(1, 1, '2026-01-01 00:00:00', 'complete', ['en-US', 'en', 'Klingon', 'ja']);
        DB::table('audio_data')->insert([
            ['releases_id' => 2, 'audioid' => 1, 'audiolanguage' => 'English (US)'],
            ['releases_id' => 2, 'audioid' => 2, 'audiolanguage' => 'English'],
            ['releases_id' => 3, 'audioid' => 1, 'audiolanguage' => 'zxx'],
            ['releases_id' => 3, 'audioid' => 2, 'audiolanguage' => 'und'],
            ['releases_id' => 4, 'audioid' => 1, 'audiolanguage' => 'Unknown language'],
            ['releases_id' => 4, 'audioid' => 2, 'audiolanguage' => 'None'],
        ]);

        foreach ([1, 2, 3, 4] as $id) {
            Search::updateRelease($id);
        }

        $this->assertSame(['English', 'Japanese', 'Klingon'], $this->audioLanguages(1));
        $this->assertSame(['English'], $this->audioLanguages(2));
        $this->assertSame([], $this->audioLanguages(3));
        $this->assertSame([], $this->audioLanguages(4));
        $this->assertSame(['English', 'Japanese', 'Klingon'], DB::table('languages')->orderBy('name')->pluck('name')->all());
    }

    public function test_an_unrecognised_audio_value_names_no_language_so_the_release_is_unknown(): void
    {
        $this->insertRelease(1, 'Movie.2020.1080p.BluRay.x264-GRP', categoriesId: 2040);
        $this->insertRelease(2, 'Movie.2021.1080p.BluRay.x264-GRP', categoriesId: 2040);
        DB::table('audio_data')->insert([
            ['releases_id' => 1, 'audioid' => 1, 'audiolanguage' => 'Original'],
            ['releases_id' => 2, 'audioid' => 1, 'audiolanguage' => 'English'],
            ['releases_id' => 2, 'audioid' => 2, 'audiolanguage' => 'e (A)'],
        ]);

        foreach ([1, 2] as $id) {
            Search::updateRelease($id);
        }

        $this->assertSame(0, DB::table('release_audio_languages')->where('releases_id', 1)->count(), 'Unknown in the Audio filter.');
        $this->assertSame(['English'], $this->audioLanguages(2));
        $this->assertSame(['English'], DB::table('languages')->pluck('name')->all());
    }

    public function test_unchanged_audio_languages_cause_no_write_and_new_media_info_replaces_them(): void
    {
        $this->insertRelease(1, 'Movie.2020.1080p.BluRay.x264-GRP', categoriesId: 2040);
        DB::table('audio_data')->insert(['releases_id' => 1, 'audioid' => 1, 'audiolanguage' => 'English']);
        Search::updateRelease(1);
        $this->assertSame(['English'], $this->audioLanguages(1));

        $this->assertSame(0, $this->writesDuring(fn () => Search::updateRelease(1)));

        $this->insertAudioProbe(1, 1, '2026-01-01 00:00:00', 'complete', ['fr', 'de']);
        Search::updateRelease(1);
        $this->assertSame(['French', 'German'], $this->audioLanguages(1));
        $this->assertSame(1, DB::table('languages')->where('name', 'English')->count(), 'A name stays for the next release.');
    }

    public function test_the_fill_migration_writes_what_refresh_writes_and_a_second_run_changes_nothing(): void
    {
        $this->insertRelease(1, 'Movie.2020.1080p.BluRay.x264-GRP', categoriesId: 2040);
        $this->insertRelease(2, 'Show.S01E01.1080p.WEB-DL-GRP');
        $this->insertRelease(3, 'Show.S01E02.1080p.WEB-DL-GRP');
        $this->insertRelease(4, 'Album-2020-FLAC', categoriesId: 3040);
        $this->insertRelease(5, 'Nothing.Known.1080p-GRP');
        $this->insertAudioProbe(1, 1, '2026-01-01 00:00:00', 'complete', ['en', 'hin', 'en-GB']);
        $this->insertAudioProbe(2, 2, '2026-01-01 00:00:00', 'complete', ['und']);
        DB::table('audio_data')->insert([
            ['releases_id' => 1, 'audioid' => 1, 'audiolanguage' => 'German'],
            ['releases_id' => 2, 'audioid' => 1, 'audiolanguage' => 'Spanish'],
            ['releases_id' => 3, 'audioid' => 1, 'audiolanguage' => 'pt-BR'],
            ['releases_id' => 3, 'audioid' => 2, 'audiolanguage' => 'Klingon'],
            ['releases_id' => 4, 'audioid' => 1, 'audiolanguage' => 'zxx'],
        ]);
        $facts = app(ReleaseDerivedFacts::class);
        foreach ([1, 2, 3, 4, 5] as $id) {
            $facts->refresh($id);
        }
        $refreshed = $this->allAudioLanguages();
        DB::table('release_audio_languages')->delete();

        $fill = require database_path('migrations/2026_09_27_200100_fill_release_audio_languages.php');
        $fill->up();

        $this->assertSame([1 => ['English', 'Hindi'], 2 => ['Spanish'], 3 => ['Klingon', 'Portuguese']], $refreshed);
        $this->assertSame($refreshed, $this->allAudioLanguages());
        $this->assertSame(0, $this->writesDuring(fn () => $fill->up()));
        $this->assertSame($refreshed, $this->allAudioLanguages());
    }

    public function test_a_release_with_no_episode_rows_gets_them_after_its_lock_with_no_delete(): void
    {
        $this->insertRelease(1, 'Show.S01E01.1080p.WEB-DL-GRP', videosId: 7);

        $transactions = $this->transactionStatements(fn () => Search::updateRelease(1));

        $this->assertCount(1, $transactions);
        $this->assertParentLockedFirst($transactions[0], 'releases', 1, ['release_tv_episodes']);
        $this->assertSame(0, $this->deletesOn($transactions[0], 'release_tv_episodes'));
        $this->assertSame([[1, 1]], $this->episodes(1));
    }

    public function test_stored_episode_rows_are_replaced_after_the_release_lock(): void
    {
        $this->insertRelease(1, 'Show.S01E01.1080p.WEB-DL-GRP', videosId: 7);
        Search::updateRelease(1);
        DB::table('releases')->where('id', 1)->update(['searchname' => 'Show.S01E02E03.1080p.WEB-DL-GRP']);

        $transactions = $this->transactionStatements(fn () => Search::updateRelease(1));

        $this->assertCount(1, $transactions);
        $this->assertParentLockedFirst($transactions[0], 'releases', 1, ['release_tv_episodes']);
        $this->assertSame(1, $this->deletesOn($transactions[0], 'release_tv_episodes'));
        $this->assertSame([[1, 2], [1, 3]], $this->episodes(1));
    }

    public function test_a_release_with_no_audio_language_rows_gets_them_after_its_lock_with_no_delete(): void
    {
        $this->insertRelease(1, 'Movie.2020.1080p.BluRay.x264-GRP', categoriesId: 2040);
        DB::table('audio_data')->insert(['releases_id' => 1, 'audioid' => 1, 'audiolanguage' => 'English']);

        $transactions = $this->transactionStatements(fn () => Search::updateRelease(1));

        $this->assertCount(1, $transactions);
        $this->assertParentLockedFirst($transactions[0], 'releases', 1, ['release_audio_languages']);
        $this->assertSame(0, $this->deletesOn($transactions[0], 'release_audio_languages'));
        $this->assertSame(['English'], $this->audioLanguages(1));
    }

    public function test_stored_audio_language_rows_are_replaced_after_the_release_lock(): void
    {
        $this->insertRelease(1, 'Movie.2020.1080p.BluRay.x264-GRP', categoriesId: 2040);
        DB::table('audio_data')->insert(['releases_id' => 1, 'audioid' => 1, 'audiolanguage' => 'English']);
        Search::updateRelease(1);
        $this->insertAudioProbe(1, 1, '2026-01-01 00:00:00', 'complete', ['fr', 'de']);

        $transactions = $this->transactionStatements(fn () => Search::updateRelease(1));

        $this->assertCount(1, $transactions);
        $this->assertParentLockedFirst($transactions[0], 'releases', 1, ['release_audio_languages']);
        $this->assertSame(1, $this->deletesOn($transactions[0], 'release_audio_languages'));
        $this->assertSame(['French', 'German'], $this->audioLanguages(1));
    }

    public function test_inside_an_open_transaction_a_release_with_no_rows_still_deletes_first(): void
    {
        $this->insertRelease(1, 'Show.S01E01.1080p.WEB-DL-GRP', videosId: 7);
        DB::table('audio_data')->insert(['releases_id' => 1, 'audioid' => 1, 'audiolanguage' => 'English']);

        // The enclosing transaction's snapshot may predate rows another writer committed.
        $transactions = $this->transactionStatements(fn () => DB::transaction(fn () => Search::updateRelease(1)));

        $this->assertCount(1, $transactions);
        $this->assertSame(1, $this->deletesOn($transactions[0], 'release_tv_episodes'));
        $this->assertSame(1, $this->deletesOn($transactions[0], 'release_audio_languages'));
        $this->assertSame([[1, 1]], $this->episodes(1));
        $this->assertSame(['English'], $this->audioLanguages(1));
    }

    public function test_a_missing_release_is_still_handed_to_the_driver(): void
    {
        Search::updateRelease(99);

        $this->assertSame(0, DB::table('releases')->count());
        $this->assertCount(1, $this->indexed);
    }

    private function insertRelease(int $id, string $searchname, int $videosId = 0, int $tvEpisodesId = 0, int $categoriesId = 5040): void
    {
        DB::table('releases')->insert([
            'id' => $id, 'searchname' => $searchname, 'categories_id' => $categoriesId, 'videos_id' => $videosId,
            'tv_episodes_id' => $tvEpisodesId, 'resolution' => 0, 'source' => 0,
        ]);
    }

    private function writesDuring(callable $action): int
    {
        $writes = 0;
        DB::listen(function (QueryExecuted $query) use (&$writes): void {
            $writes += (int) preg_match('/^\s*(update|insert|delete)\b/i', $query->sql);
        });
        $action();

        return $writes;
    }

    /** @return list<array{int, ?int}> */
    private function episodes(int $releaseId): array
    {
        return DB::table('release_tv_episodes')->where('releases_id', $releaseId)->orderBy('id')->get(['season', 'episode'])
            ->map(static fn (object $row): array => [(int) $row->season, $row->episode === null ? null : (int) $row->episode])
            ->all();
    }

    private function insertProbe(int $id, int $releaseId, string $capturedAt, string $completeness, int $width, int $height): void
    {
        DB::table('media_info_probes')->insert([
            'id' => $id, 'releases_id' => $releaseId, 'captured_at' => $capturedAt, 'source_kind' => 'sample',
            'source_completeness' => $completeness, 'schema_version' => 1, 'diagnostic_filtered' => 0, 'diagnostic_truncated' => 0,
        ]);
        DB::table('media_info_tracks')->insert([
            'media_info_probe_id' => $id, 'type' => 'video', 'track_index' => 0, 'width' => $width, 'height' => $height,
            'diagnostic_filtered' => 0, 'diagnostic_truncated' => 0,
        ]);
    }

    /** @param  list<?string>  $languages */
    private function insertAudioProbe(int $id, int $releaseId, string $capturedAt, string $completeness, array $languages): void
    {
        DB::table('media_info_probes')->insert([
            'id' => $id, 'releases_id' => $releaseId, 'captured_at' => $capturedAt, 'source_kind' => 'sample',
            'source_completeness' => $completeness, 'schema_version' => 1, 'diagnostic_filtered' => 0, 'diagnostic_truncated' => 0,
        ]);
        foreach ($languages as $index => $language) {
            DB::table('media_info_tracks')->insert([
                'media_info_probe_id' => $id, 'type' => 'audio', 'track_index' => $index, 'language' => $language,
                'diagnostic_filtered' => 0, 'diagnostic_truncated' => 0,
            ]);
        }
    }

    /** @return list<string> */
    private function audioLanguages(int $releaseId): array
    {
        return $this->allAudioLanguages()[$releaseId] ?? [];
    }

    /** @return array<int, list<string>> Names by release, both sorted. */
    private function allAudioLanguages(): array
    {
        $rows = [];
        DB::table('release_audio_languages')->join('languages', 'languages.id', '=', 'release_audio_languages.languages_id')
            ->orderBy('releases_id')->orderBy('name')->get(['releases_id', 'name'])
            ->each(static function (object $row) use (&$rows): void {
                $rows[(int) $row->releases_id][] = (string) $row->name;
            });

        return $rows;
    }

    /** @return array{int, int} */
    private function facts(int $id): array
    {
        $row = DB::table('releases')->where('id', $id)->first(['resolution', 'source']);

        return [(int) ($row->resolution ?? -1), (int) ($row->source ?? -1)];
    }
}
