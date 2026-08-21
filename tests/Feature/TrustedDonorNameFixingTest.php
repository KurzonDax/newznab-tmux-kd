<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\ReleaseNameFixed;
use App\Facades\Search;
use App\Models\Category;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\ReleaseUpdateService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
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

    public function test_predb_donor_still_attaches_predb_when_names_already_match(): void
    {
        $this->insertRelease(1, 'Canonical.Release.2026.1080p-GROUP', Category::MOVIE_HD, predbId: 77);
        $this->insertRelease(2, 'Canonical.Release.2026.1080p-GROUP');
        DB::table('release_files')->insert([
            ['releases_id' => 1, 'name' => 'movie.mkv', 'crc32' => 'AABBCCDD'],
            ['releases_id' => 2, 'name' => 'movie.mkv', 'crc32' => 'AABBCCDD'],
        ]);

        Search::shouldReceive('updateRelease')->never();
        app(NameFixingService::class)->fixNamesWithCrc(2, true, 2, true, false);

        $this->assertSame(77, (int) DB::table('releases')->where('id', 2)->value('predb_id'));
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
            $table->string('fromname')->nullable();
            $table->unsignedInteger('groups_id');
            $table->unsignedInteger('categories_id');
            $table->unsignedBigInteger('size');
            $table->string('guid', 40);
            $table->char('leftguid', 1);
            $table->dateTime('adddate')->nullable();
            $table->unsignedInteger('predb_id')->default(0);
            $table->unsignedInteger('anidbid')->default(0);
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
            $table->string('imdbid')->nullable();
            $table->unsignedInteger('musicinfo_id')->nullable();
            $table->unsignedInteger('consoleinfo_id')->nullable();
            $table->unsignedInteger('bookinfo_id')->nullable();
        });

        Schema::create('release_files', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id');
            $table->string('name');
            $table->string('crc32')->default('');
        });

        Schema::create('media_infos', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id');
            $table->string('unique_id')->nullable();
            $table->string('movie_name')->nullable();
            $table->string('file_name')->nullable();
        });

        Schema::create('par_hashes', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id');
            $table->string('hash', 32);
        });
    }
}
