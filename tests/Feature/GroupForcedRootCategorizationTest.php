<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Services\Categorization\CategorizationPipeline;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Per-group forced root category (#136), exercised through the real entry point.
 *
 * The override is a finalization step inside CategorizationPipeline::categorize(),
 * so these tests drive that method rather than hand-assembling pipes.
 */
class GroupForcedRootCategorizationTest extends TestCase
{
    private const int FORCED_GROUP_ID = 57;

    private const int PLAIN_GROUP_ID = 58;

    private string $databasePath;

    /**
     * @var array<string, string|false>
     */
    private array $originalEnvironment = [];

    public function createApplication(): Application
    {
        $this->databasePath = $this->makeTempPath('nntmux-forced-root-test', '.sqlite');

        $this->originalEnvironment = [
            'APP_ENV' => getenv('APP_ENV'),
            'DB_CONNECTION' => getenv('DB_CONNECTION'),
            'DB_DATABASE' => getenv('DB_DATABASE'),
        ];

        $pdo = new PDO('sqlite:'.$this->databasePath);
        $pdo->exec('CREATE TABLE settings (name VARCHAR PRIMARY KEY, value TEXT NULL)');
        $pdo->exec("INSERT INTO settings (name, value) VALUES ('categorizeforeign', '0'), ('catwebdl', '1')");

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

        $this->createSchema();
        $this->seedGroups();
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

    /**
     * @return array<string, array{0: string}>
     */
    public static function forcedReleaseProvider(): array
    {
        return [
            'obvious movie' => ['The.Matrix.1999.1080p.BluRay.x264'],
            'tv episode' => ['Show.Name.S01E01.1080p.WEB-DL-GROUP'],
            'scene mp3 album' => ['Artist-Album-2020-FLAC'],
            'hookup hotshot compilation' => ['HookupHotshot - 2020 Flashback Highlight Compilation'],
        ];
    }

    #[DataProvider('forcedReleaseProvider')]
    public function test_every_release_in_a_forced_group_lands_in_the_forced_root(string $releaseName): void
    {
        $this->assertSame(
            Category::XXX_ROOT,
            Category::rootCategoryFor($this->categorize(self::FORCED_GROUP_ID, $releaseName)),
        );
    }

    /**
     * The subset of the above that is not adult on its own merits — HookupHotshot
     * is a known studio since #135, so it is XXX with or without a forced root.
     *
     * @return array<string, array{0: string}>
     */
    public static function organicallyNonAdultProvider(): array
    {
        $releases = self::forcedReleaseProvider();
        unset($releases['hookup hotshot compilation']);

        return $releases;
    }

    #[DataProvider('organicallyNonAdultProvider')]
    public function test_the_same_releases_are_untouched_in_a_group_without_a_forced_root(string $releaseName): void
    {
        $this->assertNotSame(
            Category::XXX_ROOT,
            Category::rootCategoryFor($this->categorize(self::PLAIN_GROUP_ID, $releaseName)),
        );
    }

    /**
     * The regression test for the early-exit flaw: the audiobook rule scores
     * 0.95, so a pipe built on AbstractCategorizationPipe would be skipped
     * before it ever ran.
     */
    public function test_a_high_confidence_organic_match_is_still_forced(): void
    {
        $result = $this->categorizeWithDebug(self::FORCED_GROUP_ID, 'Stephen King - The Stand (Audiobook) MP3');

        $this->assertSame(
            [Category::MUSIC_AUDIOBOOK, 0.95, 'audiobook'],
            [
                $result['debug']['all_results']['Music']['category_id'],
                $result['debug']['all_results']['Music']['confidence'],
                $result['debug']['all_results']['Music']['matched_by'],
            ],
        );
        $this->assertSame(Category::XXX_OTHER, $result['categories_id']);
        $this->assertSame('group_forced_root', $result['debug']['matched_by']);
    }

    public function test_a_more_specific_category_in_the_forced_root_is_kept(): void
    {
        $result = $this->categorizeWithDebug(self::FORCED_GROUP_ID, 'Brazzers.24.01.01.Name.XXX.1080p.MP4-XXX');

        $this->assertSame(Category::XXX_CLIPHD, $result['categories_id']);
        $this->assertSame('clip_hd_studio_date', $result['debug']['matched_by']);
    }

    public function test_hashed_names_keep_the_hashed_behaviour(): void
    {
        $result = $this->categorizeWithDebug(self::FORCED_GROUP_ID, 'd41d8cd98f00b204e9800998ecf8427e');

        $this->assertSame(Category::OTHER_HASHED, $result['categories_id']);
        $this->assertTrue($result['debug']['locked_to_misc']);
    }

    public function test_debug_output_reports_both_the_organic_and_the_forced_result(): void
    {
        $result = $this->categorizeWithDebug(self::FORCED_GROUP_ID, 'The.Matrix.1999.1080p.BluRay.x264');

        $this->assertSame(
            [Category::XXX_OTHER, 'group_forced_root', Category::MOVIE_HD, 'hd'],
            [
                $result['debug']['all_results']['GroupForcedRoot']['category_id'],
                $result['debug']['all_results']['GroupForcedRoot']['matched_by'],
                $result['debug']['all_results']['GroupForcedRoot']['suppressed']['category_id'],
                $result['debug']['all_results']['GroupForcedRoot']['suppressed']['matched_by'],
            ],
        );
    }

    public function test_an_unknown_forced_root_is_ignored(): void
    {
        DB::table('usenet_groups')->where('id', self::FORCED_GROUP_ID)->update([
            'forced_root_categories_id' => 9999,
        ]);

        $this->assertSame(
            Category::MOVIE_HD,
            $this->categorize(self::FORCED_GROUP_ID, 'The.Matrix.1999.1080p.BluRay.x264'),
        );
    }

    private function categorize(int $groupId, string $releaseName): int
    {
        return (int) CategorizationPipeline::createDefault()->categorize($groupId, $releaseName)['categories_id'];
    }

    /**
     * @return array<string, mixed>
     */
    private function categorizeWithDebug(int $groupId, string $releaseName): array
    {
        return CategorizationPipeline::createDefault()->categorize($groupId, $releaseName, '', true);
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
        if (Schema::hasTable('usenet_groups')) {
            return;
        }

        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name')->default('');
            $table->boolean('route_obfuscated_names')->default(false);
            $table->unsignedInteger('obfuscated_default_root_categories_id')->nullable();
            $table->unsignedInteger('forced_root_categories_id')->nullable();
        });
    }

    private function seedGroups(): void
    {
        DB::table('usenet_groups')->insert([
            [
                'id' => self::FORCED_GROUP_ID,
                'name' => 'alt.binaries.ijsklontje',
                'forced_root_categories_id' => Category::XXX_ROOT,
            ],
            [
                'id' => self::PLAIN_GROUP_ID,
                'name' => 'alt.binaries.multimedia',
                'forced_root_categories_id' => null,
            ],
        ]);
    }
}
