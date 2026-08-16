<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\AdminSiteController;
use App\View\Composers\GlobalDataComposer;
use Database\Seeders\SettingsTableSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use PDO;
use ReflectionClass;
use Tests\TestCase;

class AdminSiteControllerTest extends TestCase
{
    private string $databasePath;

    /**
     * @var array<string, string|false>
     */
    private array $originalEnvironment = [];

    public function createApplication(): Application
    {
        $this->databasePath = $this->makeTempPath('nntmux-admin-site-test', '.sqlite');

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
        $pdo->exec("INSERT INTO settings (name, value) VALUES
            ('categorizeforeign', '0'),
            ('catwebdl', '0'),
            ('title', 'NNTmux Test'),
            ('home_link', '/')");

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
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
        ]);

        DB::purge();
        DB::reconnect();
        Cache::flush();

        $this->createSchema();
        $this->seedSettings();
        $this->resetGlobalComposerState();
    }

    protected function tearDown(): void
    {
        if ($this->databasePath !== '' && file_exists($this->databasePath)) {
            unlink($this->databasePath);
        }

        parent::tearDown();

        foreach ($this->originalEnvironment as $key => $value) {
            $this->setEnvironmentValue($key, $value === false ? null : $value);
        }
    }

    public function test_submit_converts_size_units_to_bytes(): void
    {
        $request = Request::create('/admin/site-edit', 'POST', [
            'action' => 'submit',
            'minsizetoformrelease' => '500',
            'minsizetoformrelease_unit' => 'MB',
            'maxsizetoformrelease' => '1.5',
            'maxsizetoformrelease_unit' => 'GB',
            'minsizetopostprocess' => '2',
            'minsizetopostprocess_unit' => 'MB',
            'maxsizetopostprocess' => '50',
            'maxsizetopostprocess_unit' => 'GB',
            'minsizetoprocessnfo' => '0',
            'minsizetoprocessnfo_unit' => 'MB',
            'maxsizetoprocessnfo' => '10',
            'maxsizetoprocessnfo_unit' => 'GB',
        ]);

        $response = app(AdminSiteController::class)->edit($request);

        $this->assertSame('524288000', $this->settingValue('minsizetoformrelease'));
        $this->assertSame('1610612736', $this->settingValue('maxsizetoformrelease'));
        $this->assertSame('2097152', $this->settingValue('minsizetopostprocess'));
        $this->assertSame('53687091200', $this->settingValue('maxsizetopostprocess'));
        $this->assertSame('0', $this->settingValue('minsizetoprocessnfo'));
        $this->assertSame('10737418240', $this->settingValue('maxsizetoprocessnfo'));
        $this->assertNull(DB::table('settings')->where('name', 'minsizetopostprocess_unit')->value('value'));
        $this->assertTrue($response->isRedirect());
    }

    public function test_submit_defaults_to_mb_when_unit_is_missing(): void
    {
        $request = Request::create('/admin/site-edit', 'POST', [
            'action' => 'submit',
            'minsizetopostprocess' => '3',
        ]);

        app(AdminSiteController::class)->edit($request);

        $this->assertSame('3145728', $this->settingValue('minsizetopostprocess'));
    }

    public function test_view_exposes_size_fields_split_into_value_and_unit(): void
    {
        DB::table('settings')->where('name', 'maxsizetopostprocess')->update(['value' => '53687091200']);
        DB::table('settings')->where('name', 'minsizetopostprocess')->update(['value' => '524288000']);
        DB::table('settings')->where('name', 'minsizetoformrelease')->update(['value' => '1572864']);
        Cache::flush();

        $request = Request::create('/admin/site-edit', 'GET');

        $response = app(AdminSiteController::class)->edit($request);

        $this->assertInstanceOf(View::class, $response);
        $sizeFields = $response->getData()['sizeFields'];

        $this->assertSame(['value' => 50, 'unit' => 'GB'], $sizeFields['maxsizetopostprocess']);
        $this->assertSame(['value' => 500, 'unit' => 'MB'], $sizeFields['minsizetopostprocess']);
        $this->assertSame(['value' => 1.5, 'unit' => 'MB'], $sizeFields['minsizetoformrelease']);
        $this->assertSame(['value' => 0, 'unit' => 'MB'], $sizeFields['maxsizetoformrelease']);
        $this->assertSame(['MB', 'GB'], $response->getData()['sizeUnits']);
    }

    public function test_submit_updates_discard_toggles_and_extension_pattern(): void
    {
        $request = Request::create('/admin/site-edit', 'POST', [
            'action' => 'submit',
            'discard_executable_extensions' => 'exe|bat',
            'discard_executables' => [
                '1000' => '1', // Console: was off, checked on
                '2000' => '1', // Movies: stays on
                // TV (5000) was on and is now unchecked: must turn off
            ],
        ]);

        $response = app(AdminSiteController::class)->edit($request);

        $this->assertTrue($response->isRedirect());
        $this->assertSame('exe|bat', $this->settingValue('discard_executable_extensions'));

        $toggles = DB::table('root_categories')->pluck('discard_executables', 'id');
        $this->assertEquals(0, $toggles[1]);
        $this->assertEquals(1, $toggles[1000]);
        $this->assertEquals(1, $toggles[2000]);
        $this->assertEquals(0, $toggles[4000]);
        $this->assertEquals(0, $toggles[5000], 'Unchecking a previously-on root must disable it.');

        $this->assertNull(
            DB::table('settings')->where('name', 'discard_executables')->value('value'),
            'The checkbox array must not leak into the settings table.'
        );
    }

    public function test_submit_without_discard_checkboxes_disables_all_roots(): void
    {
        $request = Request::create('/admin/site-edit', 'POST', [
            'action' => 'submit',
        ]);

        app(AdminSiteController::class)->edit($request);

        $this->assertSame(0, DB::table('root_categories')->where('discard_executables', 1)->count());
    }

    public function test_submit_updates_preview_generation_toggles_with_absent_checkbox_meaning_off(): void
    {
        $request = Request::create('/admin/site-edit', 'POST', [
            'action' => 'submit',
            'generate_previews' => [
                '1' => '1',
                '1000' => '1',
                '2000' => '1',
                '4000' => '1',
                // TV (5000) unchecked: generation must turn off for that root.
            ],
        ]);

        $response = app(AdminSiteController::class)->edit($request);

        $this->assertTrue($response->isRedirect());

        $toggles = DB::table('root_categories')->pluck('generate_previews', 'id');
        $this->assertEquals(1, $toggles[1]);
        $this->assertEquals(1, $toggles[1000]);
        $this->assertEquals(1, $toggles[2000]);
        $this->assertEquals(1, $toggles[4000]);
        $this->assertEquals(0, $toggles[5000], 'Unchecking a previously-on root must disable generation.');

        $this->assertNull(
            DB::table('settings')->where('name', 'generate_previews')->value('value'),
            'The checkbox array must not leak into the settings table.'
        );

        // Re-checking the root turns generation back on; no release state is
        // touched by the toggle itself.
        $request = Request::create('/admin/site-edit', 'POST', [
            'action' => 'submit',
            'generate_previews' => [
                '1' => '1',
                '1000' => '1',
                '2000' => '1',
                '4000' => '1',
                '5000' => '1',
            ],
        ]);

        app(AdminSiteController::class)->edit($request);

        $this->assertEquals(1, DB::table('root_categories')->where('id', 5000)->value('generate_previews'));
    }

    public function test_view_exposes_preview_roots_with_current_state(): void
    {
        DB::table('root_categories')->where('id', 4000)->update(['generate_previews' => 0]);

        $request = Request::create('/admin/site-edit', 'GET');

        $response = app(AdminSiteController::class)->edit($request);

        $this->assertInstanceOf(View::class, $response);

        $previewRoots = $response->getData()['previewRoots'];
        $togglesById = $previewRoots->pluck('generate_previews', 'id');

        $this->assertEquals(1, $togglesById[2000]);
        $this->assertEquals(0, $togglesById[4000]);
    }

    public function test_view_exposes_discard_roots_with_current_state(): void
    {
        $request = Request::create('/admin/site-edit', 'GET');

        $response = app(AdminSiteController::class)->edit($request);

        $this->assertInstanceOf(View::class, $response);

        $discardRoots = $response->getData()['discardRoots'];
        $togglesById = $discardRoots->pluck('discard_executables', 'id');

        $this->assertSame([1, 1000, 2000, 4000, 5000], $discardRoots->pluck('id')->map(fn ($id) => (int) $id)->all());
        $this->assertEquals(1, $togglesById[2000]);
        $this->assertEquals(0, $togglesById[4000]);
        $this->assertSame(
            'dll|exe|msi|scr|com|bat|cmd|pif',
            $response->getData()['site']['discard_executable_extensions'] ?? null
        );
    }

    public function test_postprocessing_settings_render_and_save_descriptive_title_toggle(): void
    {
        DB::table('settings')->where('name', 'descriptive_title_rename')->update(['value' => '0']);
        $response = app(AdminSiteController::class)->edit(Request::create('/admin/site-edit', 'GET'));

        $this->assertInstanceOf(View::class, $response);
        $this->assertStringContainsString(
            'Rename obfuscated releases from descriptive file names',
            $response->render()
        );

        $submitResponse = app(AdminSiteController::class)->edit(Request::create('/admin/site-edit', 'POST', [
            'action' => 'submit',
            'descriptive_title_rename' => '1',
        ]));

        $this->assertTrue($submitResponse->isRedirect());
        $this->assertSame('1', $this->settingValue('descriptive_title_rename'));
    }

    public function test_descriptive_title_setting_defaults_on_for_fresh_and_existing_installs(): void
    {
        (new SettingsTableSeeder)->run();
        $this->assertSame('1', $this->settingValue('descriptive_title_rename'));

        DB::table('settings')->where('name', 'descriptive_title_rename')->delete();
        $migration = require database_path('migrations/2026_08_16_024113_add_descriptive_title_rename_setting.php');
        $migration->up();
        $this->assertSame('1', $this->settingValue('descriptive_title_rename'));

        DB::table('settings')->where('name', 'descriptive_title_rename')->update(['value' => '0']);
        $migration->up();
        $this->assertSame('0', $this->settingValue('descriptive_title_rename'));

        $migration->down();
        $this->assertNull($this->settingValue('descriptive_title_rename'));
    }

    private function settingValue(string $name): ?string
    {
        $value = DB::table('settings')->where('name', $name)->value('value');

        return $value === null ? null : (string) $value;
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
        if (! Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table): void {
                $table->string('name')->primary();
                $table->text('value')->nullable();
            });
        }

        Schema::dropIfExists('root_categories');

        Schema::create('root_categories', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('title');
            $table->integer('status')->default(1);
            $table->boolean('discard_executables')->default(false);
            $table->boolean('generate_previews')->default(true);
            $table->timestamps();
        });

        DB::table('root_categories')->insert([
            ['id' => 1, 'title' => 'Other', 'discard_executables' => 0],
            ['id' => 1000, 'title' => 'Console', 'discard_executables' => 0],
            ['id' => 2000, 'title' => 'Movies', 'discard_executables' => 1],
            ['id' => 4000, 'title' => 'PC', 'discard_executables' => 0],
            ['id' => 5000, 'title' => 'TV', 'discard_executables' => 1],
        ]);
    }

    private function seedSettings(): void
    {
        DB::table('settings')->upsert([
            ['name' => 'title', 'value' => 'NNTmux Test'],
            ['name' => 'home_link', 'value' => '/'],
            ['name' => 'categorizeforeign', 'value' => '0'],
            ['name' => 'catwebdl', 'value' => '0'],
            ['name' => 'minsizetoformrelease', 'value' => '0'],
            ['name' => 'maxsizetoformrelease', 'value' => '0'],
            ['name' => 'minsizetopostprocess', 'value' => '1048576'],
            ['name' => 'maxsizetopostprocess', 'value' => '107374182400'],
            ['name' => 'minsizetoprocessnfo', 'value' => '1048576'],
            ['name' => 'maxsizetoprocessnfo', 'value' => '107374182400'],
            ['name' => 'discard_executable_extensions', 'value' => 'dll|exe|msi|scr|com|bat|cmd|pif'],
            ['name' => 'descriptive_title_rename', 'value' => '1'],
        ], ['name'], ['value']);
    }

    private function resetGlobalComposerState(): void
    {
        $reflection = new ReflectionClass(GlobalDataComposer::class);
        $property = $reflection->getProperty('resolvedData');
        $property->setValue(null, null);
    }
}
