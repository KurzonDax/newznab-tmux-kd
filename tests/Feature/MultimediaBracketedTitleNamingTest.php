<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\ReleaseCleaningService;
use Database\Seeders\ReleaseNamingRegexesTableSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/**
 * alt.binaries.multimedia subjects carry the posted title in the fourth bracket and the group's
 * short file name in quotes. Formation passes the raw subject, so a numbered first bracket and
 * the `#`/`@` of the third must still reach the title rule, not the quoted-file-name rule.
 */
class MultimediaBracketedTitleNamingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();
        $this->registerSqliteFunction(
            'REGEXP',
            static function (?string $pattern, ?string $subject): int {
                if ($pattern === null || $pattern === '' || $subject === null) {
                    return 0;
                }
                set_error_handler(static fn (): true => true);
                $ok = @preg_match('/'.str_replace('/', '\\/', $pattern).'/i', $subject);
                restore_error_handler();

                return $ok === 1 ? 1 : 0;
            },
            2
        );

        $tables = ProductionTables::fromAuthority();
        $tables->create('release_naming_regexes', ['id', 'group_regex', 'regex', 'status', 'description', 'ordinal']);
        $tables->create('predb', ['id', 'title', 'filename', 'requestid', 'groups_id']);
        $tables->create('usenet_groups', ['id', 'name']);

        $this->seed(ReleaseNamingRegexesTableSeeder::class);
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.multimedia']);
        Cache::flush();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function bracketedTitleSubjects(): array
    {
        return [
            'raw subject with a numbered first bracket' => [
                '[5542]-[FULL]-[#a.b.teevee@EFNet]-[ About.Men.And.Women.S01E04.DVDRip.XviD-aAF ]-[60/60] - "aaf-amaws01e04.vol63+06.par2" yEnc',
                'About.Men.And.Women.S01E04.DVDRip.XviD-aAF',
            ],
            'stripped subject with a numbered first bracket' => [
                '[5542]-[FULL]-[a.b.teeveeEFNet]-[ About.Men.And.Women.S01E04.DVDRip.XviD-aAF ]-[60/60] - "aaf-amaws01e04.vol63+06.par2" yEnc',
                'About.Men.And.Women.S01E04.DVDRip.XviD-aAF',
            ],
            'the rule\'s own example with a hash first bracket' => [
                '[####]-[FULL]-[#a.b.teevee@EFNet]-[ Tosh.0.S02E14.720p.HDTV.x264-aAF ]-[10/21] - "aaf-tosh.0.s02e14.720p.r07" yEnc',
                'Tosh.0.S02E14.720p.HDTV.x264-aAF',
            ],
        ];
    }

    #[DataProvider('bracketedTitleSubjects')]
    public function test_the_posted_title_names_the_release_rather_than_the_quoted_file_name(string $subject, string $title): void
    {
        $result = (new ReleaseCleaningService)->releaseCleaner($subject, 'poster@example.com', 'alt.binaries.multimedia');

        $this->assertIsArray($result);
        $this->assertSame($title, $result['cleansubject']);
        $this->assertSame(744, $result['id']);
    }
}
