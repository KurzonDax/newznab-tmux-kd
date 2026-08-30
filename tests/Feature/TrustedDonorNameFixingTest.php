<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\ReleaseNameFixed;
use App\Facades\Search;
use App\Models\Category;
use App\Models\MediaInfo as MediaInfoRecord;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\ReleaseUpdateService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Mhor\MediaInfo\Container\MediaInfoContainer;
use Mhor\MediaInfo\Type\General;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

class TrustedDonorNameFixingTest extends TestCase
{
    use IsolatedSqliteDatabase;

    /**
     * @return array<string, string>
     */
    protected function bootstrapSettings(): array
    {
        return ['categorizeforeign' => '0', 'catwebdl' => '0', 'descriptive_title_rename' => '1'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootIsolatedDatabase();
        config([
            'nntmux.echocli' => false,
        ]);

        Event::fake([ReleaseNameFixed::class]);

        $this->createSchema();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_crc_match_renames_from_trusted_donor_without_predb(): void
    {
        $this->assertTrustedDonorRenamesTarget('crc');
    }

    public function test_media_uid_match_renames_from_trusted_donor_without_predb(): void
    {
        $this->assertTrustedDonorRenamesTarget('uid');
    }

    public function test_uid_group_election_preserves_richer_name_and_upgrades_stub(): void
    {
        $richerName = 'Breaking Bad S03 E06 BluRay 1080p English DTS 5.1 x264 ESub - mkvCinemas';
        $stubName = 'Breaking Bad S03 E06 BluRay - mkvCinemas';
        $this->insertRelease(1, $stubName, Category::MOVIE_OTHER, trusted: true);
        $this->insertRelease(2, $richerName, Category::MOVIE_OTHER, trusted: true);
        DB::table('media_infos')->insert([
            ['releases_id' => 1, 'unique_id' => 'breaking-bad-s03e06'],
            ['releases_id' => 2, 'unique_id' => 'breaking-bad-s03e06'],
        ]);

        Search::shouldReceive('updateRelease')->once()->with(1);
        app(NameFixingService::class)->fixNamesWithMedia(2, true, 2, true, false);

        $this->assertSame(
            [1 => $richerName, 2 => $richerName],
            DB::table('releases')->orderBy('id')->pluck('searchname', 'id')->all(),
        );
    }

    public function test_uid_group_keeps_incumbent_when_readable_names_have_incomparable_information(): void
    {
        $incumbentName = 'Movie.2020-GRP';
        $challengerName = 'Movie.1080p-GRP';
        $this->insertRelease(1, $incumbentName, Category::MOVIE_HD, trusted: true);
        $this->insertRelease(2, $challengerName, Category::MOVIE_HD, trusted: true);
        DB::table('media_infos')->insert([
            ['releases_id' => 1, 'unique_id' => 'incomparable-uid'],
            ['releases_id' => 2, 'unique_id' => 'incomparable-uid'],
        ]);

        Search::shouldReceive('updateRelease')->never();
        app(NameFixingService::class)->fixNamesWithMedia(2, true, 2, true, false);

        $this->assertSame(
            [1 => $incumbentName, 2 => $challengerName],
            DB::table('releases')->orderBy('id')->pluck('searchname', 'id')->all(),
        );
    }

    public function test_uid_group_does_not_downgrade_a_readable_name_based_on_its_category(): void
    {
        $richerName = 'Readable.Movie.2020.1080p-GROUP';
        $poorerName = 'Readable.Movie.1080p-GROUP';
        $this->insertRelease(1, $richerName, Category::OTHER_HASHED, trusted: true);
        $this->insertRelease(2, $poorerName, Category::MOVIE_HD, trusted: true);
        DB::table('media_infos')->insert([
            ['releases_id' => 1, 'unique_id' => 'misc-category-uid'],
            ['releases_id' => 2, 'unique_id' => 'misc-category-uid'],
        ]);

        Search::shouldReceive('updateRelease')->once()->with(2);
        app(NameFixingService::class)->fixNamesWithMedia(2, true, 2, true, false);

        $this->assertSame(
            [1 => $richerName, 2 => $richerName],
            DB::table('releases')->orderBy('id')->pluck('searchname', 'id')->all(),
        );
    }

    public function test_uid_group_propagates_to_poorer_members_with_different_release_totals(): void
    {
        $richerName = 'Movie.2020.1080p-GROUP';
        $this->insertRelease(1, $richerName, Category::MOVIE_HD, trusted: true);
        $this->insertRelease(2, '5da7b5393d4f4445ac4db1ee8e95f567');
        DB::table('releases')->where('id', 2)->update(['size' => 2_000_000]);
        DB::table('media_infos')->insert([
            ['releases_id' => 1, 'unique_id' => 'different-package-size-uid'],
            ['releases_id' => 2, 'unique_id' => 'different-package-size-uid'],
        ]);

        Search::shouldReceive('updateRelease')->once()->with(2);
        app(NameFixingService::class)->fixNamesWithMedia(2, true, 2, true, false);

        $this->assertSame($richerName, DB::table('releases')->where('id', 2)->value('searchname'));
    }

    public function test_uid_group_elects_a_trusted_readable_title_without_scene_signals(): void
    {
        $readableName = 'The Meaning of Life Documentary';
        $this->insertRelease(1, $readableName, Category::OTHER_MISC, trusted: true);
        $this->insertRelease(2, '5da7b5393d4f4445ac4db1ee8e95f567');
        DB::table('media_infos')->insert([
            ['releases_id' => 1, 'unique_id' => 'plain-readable-uid'],
            ['releases_id' => 2, 'unique_id' => 'plain-readable-uid'],
        ]);

        Search::shouldReceive('updateRelease')->once()->with(2);
        app(NameFixingService::class)->fixNamesWithMedia(2, true, 2, true, false);

        $this->assertSame($readableName, DB::table('releases')->where('id', 2)->value('searchname'));
    }

    public function test_uid_group_dry_run_does_not_rename_or_consume_members(): void
    {
        $stubName = 'Breaking Bad S03 E06 BluRay - mkvCinemas';
        $richerName = 'Breaking Bad S03 E06 BluRay 1080p English DTS 5.1 x264 ESub - mkvCinemas';
        $this->insertRelease(1, $stubName, Category::MOVIE_OTHER, trusted: true);
        $this->insertRelease(2, $richerName, Category::MOVIE_OTHER, trusted: true);
        DB::table('media_infos')->insert([
            ['releases_id' => 1, 'unique_id' => 'dry-run-uid'],
            ['releases_id' => 2, 'unique_id' => 'dry-run-uid'],
        ]);

        Search::shouldReceive('updateRelease')->never();
        app(NameFixingService::class)->fixNamesWithMedia(2, false, 2, true, false);

        $this->assertSame(
            [1 => [$stubName, 0], 2 => [$richerName, 0]],
            DB::table('releases')->orderBy('id')->get()->mapWithKeys(
                static fn (object $release): array => [
                    (int) $release->id => [(string) $release->searchname, (int) $release->proc_uid],
                ],
            )->all(),
        );
    }

    public function test_late_uid_donor_rearms_a_previously_processed_obfuscated_member(): void
    {
        $canonicalName = 'Late.Arrival.Series.S02E04.1080p.WEB-DL.DDP5.1.H.264-GROUP';
        $this->insertRelease(1, '6e4f6e56f38e480985f6d22f9e2ad52e');
        DB::table('releases')->where('id', 1)->update(['proc_uid' => 1]);
        DB::table('media_infos')->insert([
            'releases_id' => 1,
            'unique_id' => 'late-arrival-uid',
        ]);
        $this->insertRelease(2, $canonicalName, Category::MOVIE_HD, trusted: true);

        $general = new General;
        $general->set('unique_id', 'late-arrival-uid');
        $mediaInfo = new MediaInfoContainer;
        $mediaInfo->setGeneral($general);

        Search::shouldReceive('updateRelease')->once()->with(1);
        MediaInfoRecord::addData(2, $mediaInfo);

        $this->assertSame(
            [1 => $canonicalName, 2 => $canonicalName],
            DB::table('releases')->orderBy('id')->pluck('searchname', 'id')->all(),
        );
    }

    public function test_par2_hash_match_renames_from_trusted_donor_without_predb(): void
    {
        $this->assertTrustedDonorRenamesTarget('hash');
    }

    public function test_weak_rename_cannot_become_a_crc_donor(): void
    {
        $this->insertRelease(1, '6e4f6e56f38e480985f6d22f9e2ad52e', Category::MOVIE_HD);
        $this->insertRelease(2, '5da7b5393d4f4445ac4db1ee8e95f567');
        DB::table('release_files')->insert([
            ['releases_id' => 1, 'name' => 'movie.mkv', 'crc32' => '0053CA13'],
            ['releases_id' => 2, 'name' => 'movie.mkv', 'crc32' => '0053CA13'],
        ]);

        Search::shouldReceive('updateRelease')->once()->with(1);
        app(ReleaseUpdateService::class)->updateRelease(
            DB::table('releases')->where('id', 1)->first(),
            'Weakly.Guessed.Movie.2026.1080p-GROUP',
            'fileCheck: Filename',
            true,
            'Filenames, ',
            true,
            false,
        );

        app(NameFixingService::class)->fixNamesWithCrc(2, true, 2, true, false);

        $this->assertSame('5da7b5393d4f4445ac4db1ee8e95f567', DB::table('releases')->where('id', 2)->value('searchname'));
        $this->assertSame(0, (int) DB::table('releases')->where('id', 1)->value('is_trusted_name'));
    }

    public function test_uid_group_does_not_trust_a_poster_identity_without_name_evidence(): void
    {
        $this->insertRelease(1, 'Unverified.Release.2026.1080p-GROUP', Category::MOVIE_OTHER);
        DB::table('releases')->where('id', 1)->update(['fromname' => 'nonscene@Ef.net (EF)']);
        $this->insertRelease(2, '5da7b5393d4f4445ac4db1ee8e95f567');
        DB::table('media_infos')->insert([
            ['releases_id' => 1, 'unique_id' => 'untrusted-poster-uid'],
            ['releases_id' => 2, 'unique_id' => 'untrusted-poster-uid'],
        ]);

        Search::shouldReceive('updateRelease')->never();
        app(NameFixingService::class)->fixNamesWithMedia(2, true, 2, true, false);

        $this->assertSame(
            '5da7b5393d4f4445ac4db1ee8e95f567',
            DB::table('releases')->where('id', 2)->value('searchname'),
        );
    }

    public function test_predb_donor_still_attaches_predb_when_names_already_match(): void
    {
        $this->insertRelease(1, 'Canonical.Release.2026.1080p-GROUP', Category::MOVIE_HD, predbId: 77);
        $this->insertRelease(2, 'Canonical.Release.2026.1080p-GROUP');
        DB::table('release_files')->insert([
            ['releases_id' => 1, 'name' => 'movie.mkv', 'crc32' => 'AABBCCDD'],
            ['releases_id' => 2, 'name' => 'movie.mkv', 'crc32' => 'AABBCCDD'],
        ]);

        Search::shouldReceive('updateRelease')->once()->with(2);
        app(NameFixingService::class)->fixNamesWithCrc(2, true, 2, true, false);

        $this->assertSame(77, (int) DB::table('releases')->where('id', 2)->value('predb_id'));
        $this->assertSame(1, (int) DB::table('releases')->where('id', 2)->value('isrenamed'));
        $this->assertSame(1, (int) DB::table('releases')->where('id', 2)->value('is_trusted_name'));
        Event::assertDispatched(
            ReleaseNameFixed::class,
            fn (ReleaseNameFixed $event): bool => $event->releaseId === 2,
        );
    }

    public function test_same_name_uid_group_member_inherits_elected_predb_identity(): void
    {
        $canonicalName = 'Canonical.Release.2026.1080p-GROUP';
        $this->insertRelease(1, $canonicalName, Category::MOVIE_OTHER, trusted: true, predbId: 77);
        $this->insertRelease(2, $canonicalName, Category::MOVIE_OTHER);
        DB::table('media_infos')->insert([
            ['releases_id' => 1, 'unique_id' => 'same-name-predb-uid'],
            ['releases_id' => 2, 'unique_id' => 'same-name-predb-uid'],
        ]);

        Search::shouldReceive('updateRelease')->once()->with(2);
        app(NameFixingService::class)->fixNamesWithMedia(2, true, 2, true, false);

        $this->assertSame(
            [$canonicalName, 77, 1, 1],
            (static function (object $release): array {
                return [
                    (string) $release->searchname,
                    (int) $release->predb_id,
                    (int) $release->isrenamed,
                    (int) $release->is_trusted_name,
                ];
            })(DB::table('releases')->where('id', 2)->firstOrFail()),
        );
    }

    public function test_same_name_trusted_donor_without_predb_marks_target_processed(): void
    {
        $this->insertRelease(1, 'Canonical.Release.2026.1080p-GROUP', Category::MOVIE_HD, trusted: true);
        $this->insertRelease(2, 'Canonical.Release.2026.1080p-GROUP');
        DB::table('release_files')->insert([
            ['releases_id' => 1, 'name' => 'movie.mkv', 'crc32' => '11223344'],
            ['releases_id' => 2, 'name' => 'movie.mkv', 'crc32' => '11223344'],
        ]);

        Search::shouldReceive('updateRelease')->never();
        app(NameFixingService::class)->fixNamesWithCrc(2, true, 2, true, false);

        $this->assertSame(1, (int) DB::table('releases')->where('id', 2)->value('proc_crc32'));
    }

    public function test_donor_renames_propagate_transitively_without_flip_flopping(): void
    {
        $canonicalName = 'Canonical.Release.2026.1080p-GROUP';
        $this->insertRelease(1, $canonicalName, Category::MOVIE_HD, trusted: true);
        $this->insertRelease(2, '6f0c31cb66a544c1912a0fc16e3d7b73');
        DB::table('release_files')->insert([
            ['releases_id' => 1, 'name' => 'first.mkv', 'crc32' => 'AABBCCDD'],
            ['releases_id' => 2, 'name' => 'first.mkv', 'crc32' => 'AABBCCDD'],
        ]);

        Search::shouldReceive('updateRelease')->once()->with(2);
        app(NameFixingService::class)->fixNamesWithCrc(2, true, 2, true, false);

        $this->insertRelease(3, '5da7b5393d4f4445ac4db1ee8e95f567');
        DB::table('release_files')->insert([
            ['releases_id' => 2, 'name' => 'second.mkv', 'crc32' => '11223344'],
            ['releases_id' => 3, 'name' => 'second.mkv', 'crc32' => '11223344'],
        ]);

        Search::shouldReceive('updateRelease')->once()->with(3);
        app(NameFixingService::class)->fixNamesWithCrc(2, true, 2, true, false);
        app(NameFixingService::class)->fixNamesWithCrc(2, true, 2, true, false);

        $releases = DB::table('releases')->orderBy('id')->get();
        $this->assertCount(3, $releases);
        foreach ($releases as $release) {
            $this->assertSame($canonicalName, $release->searchname);
            $this->assertSame(1, (int) $release->is_trusted_name);
        }
    }

    private function assertTrustedDonorRenamesTarget(string $source): void
    {
        $this->insertRelease(1, 'Canonical.Release.2026.1080p-GROUP', Category::MOVIE_HD, trusted: true);
        $this->insertRelease(2, '6f0c31cb66a544c1912a0fc16e3d7b73');

        if ($source === 'crc') {
            DB::table('release_files')->insert([
                ['releases_id' => 1, 'name' => 'movie.mkv', 'crc32' => '0053CA13'],
                ['releases_id' => 2, 'name' => 'movie.mkv', 'crc32' => '0053CA13'],
            ]);
        } elseif ($source === 'uid') {
            DB::table('media_infos')->insert([
                ['releases_id' => 1, 'unique_id' => '9988776655443322'],
                ['releases_id' => 2, 'unique_id' => '9988776655443322'],
            ]);
        } else {
            DB::table('par_hashes')->insert([
                ['releases_id' => 1, 'hash' => '1234567890abcdef1234567890abcdef'],
                ['releases_id' => 2, 'hash' => '1234567890abcdef1234567890abcdef'],
            ]);
        }

        Search::shouldReceive('updateRelease')->once()->with(2);
        $service = app(NameFixingService::class);

        match ($source) {
            'crc' => $service->fixNamesWithCrc(2, true, 2, true, false),
            'uid' => $service->fixNamesWithMedia(2, true, 2, true, false),
            'hash' => $service->fixNamesWithParHash(2, true, 2, true, false),
            default => throw new \InvalidArgumentException("Unsupported source [{$source}]."),
        };

        $target = DB::table('releases')->where('id', 2)->first();
        $this->assertSame('Canonical.Release.2026.1080p-GROUP', $target->searchname);
        $this->assertSame(1, (int) $target->isrenamed);
        $this->assertSame(1, (int) $target->is_trusted_name);
        $this->assertSame(0, (int) $target->predb_id);
    }

    private function insertRelease(
        int $id,
        string $searchName,
        int $categoryId = Category::OTHER_HASHED,
        bool $trusted = false,
        int $predbId = 0,
    ): void {
        DB::table('releases')->insert([
            'id' => $id,
            'name' => $searchName,
            'searchname' => $searchName,
            'fromname' => 'poster@example.test',
            'groups_id' => 1,
            'categories_id' => $categoryId,
            'size' => 1_000_000,
            'guid' => str_pad((string) $id, 40, '0'),
            'leftguid' => (string) $id,
            'predb_id' => $predbId,
            'isrenamed' => $categoryId === Category::MOVIE_HD ? 1 : 0,
            'is_trusted_name' => $trusted ? 1 : 0,
            'adddate' => now(),
        ]);
    }

    private function createSchema(): void
    {
        Schema::create('releases', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
            $table->string('searchname');
            $table->string('searchname_normalized')->nullable();
            $table->string('display_name')->nullable();
            $table->string('fromname')->nullable();
            $table->unsignedInteger('groups_id');
            $table->unsignedInteger('categories_id');
            $table->unsignedBigInteger('size');
            $table->string('guid', 40);
            $table->char('leftguid', 1);
            $table->dateTime('adddate')->nullable();
            $table->unsignedInteger('predb_id')->default(0);
            $table->unsignedInteger('anidbid')->nullable();
            $table->boolean('isrenamed')->default(false);
            $table->boolean('is_trusted_name')->default(false);
            $table->boolean('iscategorized')->default(false);
            $table->integer('nfostatus')->default(0);
            $table->integer('proc_nfo')->default(0);
            $table->integer('proc_files')->default(0);
            $table->integer('proc_par2')->default(0);
            $table->integer('proc_uid')->default(0);
            $table->integer('proc_srr')->default(0);
            $table->integer('proc_hash16k')->default(0);
            $table->integer('proc_crc32')->default(0);
            $table->unsignedInteger('videos_id')->default(0);
            $table->unsignedInteger('tv_episodes_id')->default(0);
            $table->unsignedInteger('movieinfo_id')->nullable();
            $table->string('imdbid')->nullable();
            $table->unsignedInteger('musicinfo_id')->nullable();
            $table->unsignedInteger('consoleinfo_id')->nullable();
            $table->unsignedInteger('bookinfo_id')->nullable();
            $table->integer('gamesinfo_id')->default(0);
        });

        Schema::create('release_files', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id');
            $table->string('name');
            $table->string('crc32')->default('');
        });

        Schema::create('media_infos', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('releases_id');
            $table->string('unique_id')->nullable();
            $table->string('movie_name')->nullable();
            $table->string('file_name')->nullable();
            $table->timestamps();
        });

        Schema::create('par_hashes', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id');
            $table->string('hash', 32);
        });
    }
}
