<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Binaries\BinariesConfig;
use App\Services\Binaries\HeaderParser;
use App\Services\Binaries\HeaderStorageService;
use App\Services\NNTPService;
use Database\Seeders\CollectionRegexesTableSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\Reconciliation\CreatesPostingSchema;
use Tests\TestCase;

abstract class NumberedMkvArchiveTestCase extends TestCase
{
    use CreatesPostingSchema;

    public const string PREVIOUS = '/^\\[\\d+\\/\\d+\\] - "(?P<match0>.+?)(?:\\.(?:bin|mkv)(?:\\.vol\\d+\\+\\d+)?\\.par2|\\.tar\\.zst(?:\\.vol\\d+\\+\\d+\\.par2|\\.par2)?|([\-_](proof|sample|thumbs?))*(\\.part\\d*(\\.rar)?|\\.rar|\\.7z)?(?:\\d{1,3}\\.rev|\\.vol\\d+\\+\\d+\\.par2|\\.[A-Za-z0-9]{2,4})?)"[\-_\\s]{0,3}yEnc$/ui';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-01-01 12:00:00', 'UTC'));
        $this->instance(NNTPService::class, \Mockery::mock(NNTPService::class));
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();
        $this->registerSqliteFunction('regexp', static fn (string $pattern, string $value): int => preg_match('/'.str_replace('/', '\\/', $pattern).'/i', $value) === 1 ? 1 : 0, 2);
        $this->createPostingSchema();
        DB::table('usenet_groups')->where('id', 1)->update(['name' => 'alt.binaries.boneless']);
        $this->seed(CollectionRegexesTableSeeder::class);
        Cache::flush();
    }

    /** @return list<string> */
    protected function archiveFiles(): array
    {
        return ['Example.mkv.par2', 'Example.mkv.part01.rar', 'Example.mkv.part02.rar',
            'Example.mkv.vol000+001.par2', 'Example.mkv.vol001+002.par2'];
    }

    /** @return list<array<string, mixed>> */
    protected function archiveHeaders(): array
    {
        $headers = [];
        foreach ($this->archiveFiles() as $index => $filename) {
            foreach ([1, 2] as $segment) {
                $number = 100000 + 2 * $index + $segment;
                $headers[] = [
                    'Number' => $number,
                    'Subject' => sprintf('[%02d/05] - "%s" yEnc (%d/2)', $index + 1, $filename, $segment),
                    'From' => 'Synthetic Poster',
                    'Date' => '2026-01-01 12:00:00 +0000',
                    'Bytes' => 100,
                    'Message-ID' => "<fixture-{$number}@example.invalid>",
                    'Xref' => "news.example.invalid alt.binaries.boneless:{$number}",
                ];
            }
        }

        return $headers;
    }

    /** @param list<array<string, mixed>> $headers */
    protected function ingest(array $headers): void
    {
        $parsed = (new HeaderParser(new NeverBlacklistedService))->parse($headers, 'alt.binaries.boneless');
        $this->assertCount(count($headers), $parsed['headers']);
        $result = (new HeaderStorageService(config: new BinariesConfig(echoCli: false, headerChunkSize: 1)))
            ->store($parsed['headers'], ['id' => 1, 'name' => 'alt.binaries.boneless'], false);
        $this->assertSame([], $result->uniqueFailedNumbers());
    }

    protected function migration(): Migration
    {
        return require database_path('migrations/2026_09_13_120000_fix_numbered_mkv_archive_collection_regex.php');
    }

    protected function installPrevious(): void
    {
        DB::table('collection_regexes')->where('id', 113)->update(['regex' => self::PREVIOUS]);
        Cache::flush();
    }
}
