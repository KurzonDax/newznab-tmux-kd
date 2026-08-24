<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Services\Categorization\CategorizationPipeline;
use App\Services\Categorization\CategorizationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

/**
 * Per-group forced root category (#136), exercised through the real entry point.
 *
 * The override is a finalization step inside CategorizationPipeline::categorize(),
 * so these tests drive that method rather than hand-assembling pipes.
 */
class GroupForcedRootCategorizationTest extends TestCase
{
    use IsolatedSqliteDatabase;

    private const int FORCED_GROUP_ID = 57;

    private const int PLAIN_GROUP_ID = 58;

    private const int MOVIE_FORCED_GROUP_ID = 59;

    private const int MUSIC_FORCED_GROUP_ID = 60;

    /**
     * @return array<string, string>
     */
    protected function bootstrapSettings(): array
    {
        return ['categorizeforeign' => '0', 'catwebdl' => '1'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();

        $this->createSchema();
        $this->seedGroups();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
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

    public function test_a_forced_secondary_group_governs_regardless_of_scan_order(): void
    {
        $releaseName = 'Unmistakably.Generic.Release.Name';

        $this->assertSame(
            Category::MUSIC_OTHER,
            $this->categorize(self::PLAIN_GROUP_ID, $releaseName, [
                self::PLAIN_GROUP_ID,
                self::MUSIC_FORCED_GROUP_ID,
            ]),
        );
        $this->assertSame(
            Category::MUSIC_OTHER,
            $this->categorize(self::MUSIC_FORCED_GROUP_ID, $releaseName, [
                self::MUSIC_FORCED_GROUP_ID,
                self::PLAIN_GROUP_ID,
            ]),
        );
    }

    public function test_a_forced_primary_group_wins_conflicting_forces(): void
    {
        $this->assertSame(
            Category::XXX_OTHER,
            $this->categorize(self::FORCED_GROUP_ID, 'Generic.Release', [
                self::MOVIE_FORCED_GROUP_ID,
                self::FORCED_GROUP_ID,
            ]),
        );
    }

    public function test_the_lowest_forced_root_wins_when_the_primary_group_is_unforced(): void
    {
        foreach ([
            [self::FORCED_GROUP_ID, self::MOVIE_FORCED_GROUP_ID],
            [self::MOVIE_FORCED_GROUP_ID, self::FORCED_GROUP_ID],
        ] as $associatedGroupIds) {
            $this->assertSame(
                Category::MOVIE_OTHER,
                $this->categorize(self::PLAIN_GROUP_ID, 'Generic.Release', $associatedGroupIds),
            );
        }
    }

    public function test_a_persisted_release_uses_its_junction_groups(): void
    {
        DB::table('releases_groups')->insert([
            'releases_id' => 900,
            'groups_id' => self::MUSIC_FORCED_GROUP_ID,
        ]);

        $result = (new CategorizationService)->determineCategory(
            self::PLAIN_GROUP_ID,
            'Generic.Release',
            releaseId: 900,
        );

        $this->assertSame(Category::MUSIC_OTHER, $result['categories_id']);
    }

    /**
     * @param  list<int>  $associatedGroupIds
     */
    private function categorize(int $groupId, string $releaseName, array $associatedGroupIds = []): int
    {
        return (int) CategorizationPipeline::createDefault()->categorize(
            $groupId,
            $releaseName,
            associatedGroupIds: $associatedGroupIds,
        )['categories_id'];
    }

    /**
     * @return array<string, mixed>
     */
    private function categorizeWithDebug(int $groupId, string $releaseName): array
    {
        return CategorizationPipeline::createDefault()->categorize($groupId, $releaseName, '', true);
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

        Schema::create('releases_groups', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id');
            $table->unsignedInteger('groups_id');
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
            [
                'id' => self::MOVIE_FORCED_GROUP_ID,
                'name' => 'alt.binaries.movies',
                'forced_root_categories_id' => Category::MOVIE_ROOT,
            ],
            [
                'id' => self::MUSIC_FORCED_GROUP_ID,
                'name' => 'alt.binaries.sounds',
                'forced_root_categories_id' => Category::MUSIC_ROOT,
            ],
        ]);
    }
}
