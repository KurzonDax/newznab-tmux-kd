<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Models\Release;
use App\Services\Releases\ExecutableReleaseDiscardService;
use Database\Seeders\RootCategoriesTableSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PDO;
use Tests\TestCase;

class ExecutableReleaseDiscardServiceTest extends TestCase
{
    private string $databasePath;

    /**
     * @var array<string, string|false>
     */
    private array $originalEnvironment = [];

    public function createApplication()
    {
        $this->databasePath = $this->makeTempPath('nntmux-executable-discard-test', '.sqlite');

        $this->originalEnvironment = [
            'APP_ENV' => getenv('APP_ENV'),
            'DB_CONNECTION' => getenv('DB_CONNECTION'),
            'DB_DATABASE' => getenv('DB_DATABASE'),
        ];

        if (file_exists($this->databasePath)) {
            unlink($this->databasePath);
        }

        $pdo = new PDO('sqlite:'.$this->databasePath);
        $pdo->exec('CREATE TABLE settings (name VARCHAR PRIMARY KEY, value TEXT NULL)');
        $pdo->exec("INSERT INTO settings (name, value) VALUES ('categorizeforeign', '0'), ('catwebdl', '0')");

        $this->setEnvironmentValue('APP_ENV', 'testing');
        $this->setEnvironmentValue('DB_CONNECTION', 'sqlite');
        $this->setEnvironmentValue('DB_DATABASE', $this->databasePath);

        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $this->databasePath,
        ]);

        DB::purge();
        DB::reconnect();
        Cache::flush();

        $this->createSchema();
        $this->seedCategories();
    }

    protected function tearDown(): void
    {
        Mockery::close();

        if ($this->databasePath !== '' && file_exists($this->databasePath)) {
            unlink($this->databasePath);
        }

        parent::tearDown();

        foreach ($this->originalEnvironment as $key => $value) {
            $this->setEnvironmentValue($key, $value === false ? null : $value);
        }
    }

    public function test_default_extensions_match_case_insensitively(): void
    {
        $service = new ExecutableReleaseDiscardService;

        foreach (['dll', 'exe', 'msi', 'scr', 'com', 'bat', 'cmd', 'pif'] as $extension) {
            $this->assertTrue(
                $service->matchesExecutablePattern('payload.'.$extension),
                "Expected .{$extension} to match the default pattern."
            );
            $this->assertTrue(
                $service->matchesExecutablePattern('PAYLOAD.'.strtoupper($extension)),
                "Expected uppercase .{$extension} to match the default pattern."
            );
        }
    }

    public function test_non_executable_and_lookalike_names_do_not_match(): void
    {
        $service = new ExecutableReleaseDiscardService;

        foreach ([
            'release.nfo',
            'Example.Movie.2026.mkv',
            'file.exe.txt',
            'exe',
            'archive.exezz',
            'notes.execute',
            '',
        ] as $name) {
            $this->assertFalse(
                $service->matchesExecutablePattern($name),
                "Expected '{$name}' not to match the default pattern."
            );
        }
    }

    public function test_pattern_setting_overrides_default(): void
    {
        DB::table('settings')->insert([
            'name' => ExecutableReleaseDiscardService::EXTENSIONS_SETTING,
            'value' => 'xyz|abc',
        ]);
        Cache::flush();

        $service = new ExecutableReleaseDiscardService;

        $this->assertTrue($service->matchesExecutablePattern('payload.xyz'));
        $this->assertTrue($service->matchesExecutablePattern('payload.ABC'));
        $this->assertFalse($service->matchesExecutablePattern('payload.exe'));
    }

    public function test_blank_pattern_setting_falls_back_to_default(): void
    {
        DB::table('settings')->insert([
            'name' => ExecutableReleaseDiscardService::EXTENSIONS_SETTING,
            'value' => '  ',
        ]);
        Cache::flush();

        $service = new ExecutableReleaseDiscardService;

        $this->assertTrue($service->matchesExecutablePattern('payload.exe'));
    }

    public function test_should_discard_requires_enabled_root_toggle(): void
    {
        $service = new ExecutableReleaseDiscardService;

        // Category 6999 rolls up to XXX (6000), toggle on.
        $this->assertTrue($service->shouldDiscard('Fixer/Fixer.exe', 6999));

        // Category 4050 rolls up to PC (4000), toggle off.
        $this->assertFalse($service->shouldDiscard('Fixer/Fixer.exe', 4050));

        // Unknown category cannot resolve a root: never discard.
        $this->assertFalse($service->shouldDiscard('Fixer/Fixer.exe', 99999));

        // Non-matching file never discards, even with the toggle on.
        $this->assertFalse($service->shouldDiscard('sample.mkv', 6999));
    }

    public function test_first_discardable_file_name_scans_complete_lists(): void
    {
        $service = new ExecutableReleaseDiscardService;

        $files = [
            ['name' => 'sample.mkv'],
            ['size' => 42], // entry without a name key is skipped
            ['name' => 'Fixer/Fixer.exe'],
        ];

        $this->assertSame('Fixer/Fixer.exe', $service->firstDiscardableFileName($files, 6999));
        $this->assertNull($service->firstDiscardableFileName($files, 4050), 'Toggled-off root never matches.');
        $this->assertNull($service->firstDiscardableFileName([['name' => 'clean.mkv']], 6999));
    }

    public function test_seeder_ships_expected_root_defaults(): void
    {
        DB::table('categories')->delete();
        (new RootCategoriesTableSeeder)->run();

        $toggles = DB::table('root_categories')->pluck('discard_executables', 'id');

        $this->assertEquals(0, $toggles[1], 'Other must ship with discarding off.');
        $this->assertEquals(0, $toggles[1000], 'Console must ship with discarding off.');
        $this->assertEquals(1, $toggles[2000], 'Movies must ship with discarding on.');
        $this->assertEquals(1, $toggles[3000], 'Audio must ship with discarding on.');
        $this->assertEquals(0, $toggles[4000], 'PC must ship with discarding off.');
        $this->assertEquals(1, $toggles[5000], 'TV must ship with discarding on.');
        $this->assertEquals(1, $toggles[6000], 'XXX must ship with discarding on.');
        $this->assertEquals(1, $toggles[7000], 'Books must ship with discarding on.');
    }

    public function test_discard_removes_release_and_file_rows(): void
    {
        $this->insertRelease(1, 'guid-1', 6999, 'CapCut 9.2.0.3931 Pre-Activated', 'spammer@example.com');
        DB::table('release_files')->insert([
            ['releases_id' => 1, 'name' => 'Fixer/Fixer.exe', 'size' => 1024],
            ['releases_id' => 1, 'name' => 'readme.nfo', 'size' => 128],
        ]);

        Search::shouldReceive('deleteRelease')->once()->with(1);
        Log::shouldReceive('warning')->once()->with(
            'Discarding release containing executable file',
            Mockery::on(function (array $payload): bool {
                return $payload['release_id'] === 1
                    && $payload['name'] === 'CapCut 9.2.0.3931 Pre-Activated'
                    && $payload['categories_id'] === 6999
                    && $payload['poster'] === 'spammer@example.com'
                    && $payload['file'] === 'Fixer/Fixer.exe';
            })
        );

        $release = Release::query()->findOrFail(1);
        (new ExecutableReleaseDiscardService)->discard($release, 'Fixer/Fixer.exe');

        $this->assertSame(0, DB::table('releases')->count());
        $this->assertSame(0, DB::table('release_files')->count());
    }

    public function test_discard_by_id_handles_missing_release(): void
    {
        $service = new ExecutableReleaseDiscardService;

        $this->assertFalse($service->discardById(42, 'payload.exe'));
    }

    public function test_sweep_purges_matching_backlog_only(): void
    {
        // Discarded: executable file, toggled-on root (XXX).
        $this->insertRelease(1, 'guid-1', 6999, 'CapCut 9.2.0.3931 Pre-Activated', 'spammer@example.com');
        DB::table('release_files')->insert([
            ['releases_id' => 1, 'name' => 'CapCut_creatortool.exe', 'size' => 1024],
        ]);

        // Kept: executable file, toggled-off root (PC).
        $this->insertRelease(2, 'guid-2', 4050, 'Some.Legit.App.v1.0', 'poster@example.com');
        DB::table('release_files')->insert([
            ['releases_id' => 2, 'name' => 'setup.exe', 'size' => 2048],
        ]);

        // Kept: no executable file, toggled-on root.
        $this->insertRelease(3, 'guid-3', 6999, 'Example.Release', 'poster@example.com');
        DB::table('release_files')->insert([
            ['releases_id' => 3, 'name' => 'sample.mkv', 'size' => 4096],
        ]);

        // Kept: lookalike name that only resembles an executable.
        $this->insertRelease(4, 'guid-4', 6999, 'Lookalike.Release', 'poster@example.com');
        DB::table('release_files')->insert([
            ['releases_id' => 4, 'name' => 'file.exe.txt', 'size' => 512],
        ]);

        Search::shouldReceive('deleteRelease')->once()->with(1);
        Log::shouldReceive('warning')->once();

        $swept = [];
        $count = (new ExecutableReleaseDiscardService)->sweep(function ($release) use (&$swept): void {
            $swept[] = (int) $release->id;
        });

        $this->assertSame(1, $count);
        $this->assertSame([1], $swept);
        $this->assertSame([2, 3, 4], DB::table('releases')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(0, DB::table('release_files')->where('releases_id', 1)->count());
        $this->assertSame(3, DB::table('release_files')->count());
    }

    public function test_sweep_without_enabled_roots_is_a_no_op(): void
    {
        DB::table('root_categories')->update(['discard_executables' => 0]);

        $this->insertRelease(1, 'guid-1', 6999, 'CapCut 9.2.0.3931 Pre-Activated', 'spammer@example.com');
        DB::table('release_files')->insert([
            ['releases_id' => 1, 'name' => 'Fixer/Fixer.exe', 'size' => 1024],
        ]);

        $count = (new ExecutableReleaseDiscardService)->sweep();

        $this->assertSame(0, $count);
        $this->assertSame(1, DB::table('releases')->count());
    }

    private function insertRelease(int $id, string $guid, int $categoriesId, string $searchname, string $fromname): void
    {
        DB::table('releases')->insert([
            'id' => $id,
            'guid' => $guid,
            'name' => $searchname,
            'searchname' => $searchname,
            'fromname' => $fromname,
            'categories_id' => $categoriesId,
            'size' => 1024,
            'groups_id' => 1,
        ]);
    }

    private function setEnvironmentValue(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            return;
        }

        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private function createSchema(): void
    {
        DB::statement('PRAGMA foreign_keys = ON');

        if (! Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table): void {
                $table->string('name')->primary();
                $table->text('value')->nullable();
            });
        }

        DB::table('settings')->upsert([
            ['name' => 'categorizeforeign', 'value' => '0'],
            ['name' => 'catwebdl', 'value' => '0'],
        ], ['name'], ['value']);

        DB::table('settings')->where('name', ExecutableReleaseDiscardService::EXTENSIONS_SETTING)->delete();

        Schema::dropIfExists('release_files');
        Schema::dropIfExists('releases');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('root_categories');

        Schema::create('root_categories', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('title');
            $table->integer('status')->default(1);
            $table->boolean('discard_executables')->default(false);
            $table->boolean('generate_previews')->default(true);
        });

        Schema::create('categories', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('title');
            $table->unsignedInteger('root_categories_id');
        });

        Schema::create('releases', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('guid');
            $table->string('name')->default('');
            $table->string('searchname')->default('');
            $table->string('fromname')->default('');
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedInteger('groups_id')->default(0);
            $table->integer('categories_id')->default(10);
        });

        Schema::create('release_files', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id');
            $table->string('name');
            $table->unsignedBigInteger('size')->default(0);
            $table->primary(['releases_id', 'name']);
            $table->foreign('releases_id')->references('id')->on('releases')->cascadeOnDelete();
        });
    }

    private function seedCategories(): void
    {
        DB::table('root_categories')->insert([
            ['id' => 1, 'title' => 'Other', 'discard_executables' => 0],
            ['id' => 1000, 'title' => 'Console', 'discard_executables' => 0],
            ['id' => 2000, 'title' => 'Movies', 'discard_executables' => 1],
            ['id' => 3000, 'title' => 'Audio', 'discard_executables' => 1],
            ['id' => 4000, 'title' => 'PC', 'discard_executables' => 0],
            ['id' => 5000, 'title' => 'TV', 'discard_executables' => 1],
            ['id' => 6000, 'title' => 'XXX', 'discard_executables' => 1],
            ['id' => 7000, 'title' => 'Books', 'discard_executables' => 1],
        ]);

        DB::table('categories')->insert([
            ['id' => 10, 'title' => 'Other > Misc', 'root_categories_id' => 1],
            ['id' => 4050, 'title' => 'PC > Games', 'root_categories_id' => 4000],
            ['id' => 6999, 'title' => 'XXX > Other', 'root_categories_id' => 6000],
        ]);
    }
}
