<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Binaries\BinariesConfig;
use App\Services\Binaries\HeaderParser;
use App\Services\Binaries\HeaderStorageService;
use Database\Seeders\CollectionRegexesTableSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\Reconciliation\CreatesPostingSchema;
use Tests\TestCase;

abstract class TokenPrefixedArchiveTestCase extends TestCase
{
    use CreatesPostingSchema;

    protected const string PREVIOUS = '/(.+)[\-_ ]{0,3}[\\(\\[]\\d+\\/(?P<match0>\\d+[\\)\\]][\-_ ]{0,3}("|#34;).+?)(\\.part\\d*|\\.rar)?(\\.vol\\d+\\+\\d+\\.par2"|\\.[A-Za-z0-9]{2,4})("|#34;)(.+?)yEnc$/i';

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();
        $this->registerSqliteFunction('regexp', static fn (string $pattern, string $value): int => preg_match('/'.str_replace('/', '\\/', $pattern).'/i', $value) === 1 ? 1 : 0, 2);
        $this->createPostingSchema();
        DB::table('usenet_groups')->where('id', 1)->update(['name' => 'alt.binaries.erotica']);
        $this->seed(CollectionRegexesTableSeeder::class);
        Cache::flush();
    }

    /** @return list<string> */
    protected function archiveFiles(string $family): array
    {
        if ($family === 'rar') {
            return ['Sample-A.sfv', 'Sample-A.nfo', 'Sample-A.par2',
                ...array_map(static fn (int $number): string => "Sample-A.part{$number}.rar", range(1, 8)),
                'Sample-A.vol0+193.par2'];
        }

        return [...array_map(static fn (int $number): string => sprintf('Sample-B.7z.%03d', $number), range(1, 11)),
            'Sample-B.par2', ...array_map(static fn (string $range): string => "Sample-B.vol{$range}.par2",
                ['000+01', '001+02', '003+04', '007+08', '015+16', '031+32', '063+37'])];
    }

    /** @return list<array<string, mixed>> */
    protected function archiveHeaders(string $family): array
    {
        $files = $this->archiveFiles($family);
        $stem = $family === 'rar' ? 'Sample-A' : 'Sample-B';
        $headers = [];
        foreach ($files as $index => $filename) {
            foreach ([1, 2] as $segment) {
                $number = 1000 + $index * 2 + $segment;
                $headers[] = [
                    'Number' => $number,
                    'Subject' => sprintf('%s [%02d/%d] - "%s" yEnc (%d/2)', $stem, $index + 1, count($files), $filename, $segment),
                    'From' => 'Synthetic Poster <poster@example.invalid>',
                    'Date' => '2026-09-01 12:00:00 +0000', 'Bytes' => 100,
                    'Message-ID' => "<{$family}-{$number}@example.invalid>",
                    'Xref' => "news.example.invalid alt.binaries.erotica:{$number}",
                ];
            }
        }

        return $headers;
    }

    /** @param list<array<string, mixed>> $headers */
    protected function ingest(array $headers): void
    {
        $parsed = (new HeaderParser(new NeverBlacklistedService))->parse($headers, 'alt.binaries.erotica');
        $this->assertCount(count($headers), $parsed['headers']);
        $result = (new HeaderStorageService(config: new BinariesConfig(echoCli: false, headerChunkSize: 1)))
            ->store($parsed['headers'], ['id' => 1, 'name' => 'alt.binaries.erotica'], false);
        $this->assertSame([], $result->uniqueFailedNumbers());
    }

    protected function migration(): Migration
    {
        return require database_path('migrations/2026_09_09_112013_fix_token_prefixed_archive_collection_regex.php');
    }

    protected function installPrevious(): void
    {
        DB::table('collection_regexes')->where('id', 284)->update(['regex' => self::PREVIOUS]);
        Cache::flush();
    }
}
