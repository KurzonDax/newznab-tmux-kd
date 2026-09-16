<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Http\Controllers\CartController;
use App\Http\Controllers\GetNzbController;
use App\Models\UserDownload;
use App\Services\Nzb\NzbArchiveStream;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\Process;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\InteractsWithReleaseBrowser;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;
use ZipArchive;

final class NzbArchiveDownloadTest extends TestCase
{
    use InteractsWithAdminListPages;
    use InteractsWithReleaseBrowser;
    use IsolatedSqliteDatabase;

    private string $nzbDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->bootAdminListPage();
        $this->createReleaseSchema();
        Schema::table('users', function (Blueprint $table): void {
            $table->integer('grabs')->default(0);
            $table->timestamp('lastdownload')->nullable();
        });
        Schema::table('roles', function (Blueprint $table): void {
            $table->integer('downloadrequests')->default(1000);
        });
        Schema::create('user_downloads', function (Blueprint $table): void {
            $table->id();
            $table->integer('users_id');
            $table->integer('releases_id');
            $table->timestamp('timestamp');
        });
        DB::table('settings')->insert([
            ['name' => 'nzbsplitlevel', 'value' => '0'],
            ['name' => 'grabstatus', 'value' => '1'],
        ]);
        $this->nzbDirectory = $this->makeTempDirectory('archive-nzbs');
        config(['nntmux_settings.path_to_nzbs' => $this->nzbDirectory.'/']);
        Search::shouldReceive('updateRelease')->andReturnNull();
    }

    protected function tearDown(): void
    {
        $this->resetGlobalComposerState();
        $this->tearDownAdminListPage();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_download_accounts_only_for_archivable_releases_and_removes_only_those_from_basket(): void
    {
        $user = $this->browserUser();
        $user->role->forceFill(['downloadrequests' => 3])->save();
        $this->assertSame(3, (int) $user->role->fresh()->downloadrequests);
        $ids = [];
        $guids = [];
        foreach (range(1, 4) as $index) {
            $guid = md5('archive-'.$index);
            $ids[] = $this->release('Release '.$index, ['guid' => $guid]);
            $guids[] = $guid;
            DB::table('users_releases')->insert(['users_id' => $user->id, 'releases_id' => end($ids)]);
            if ($index <= 3) {
                file_put_contents($this->nzbDirectory.'/'.$guid.'.nzb.gz', gzencode('<nzb>Release '.$index.'</nzb>'));
            }
        }
        $guids[] = md5('unknown');
        $request = Request::create('/getnzb', 'GET', ['id' => implode(',', $guids), 'zip' => '1', 'del' => '1']);
        $request->attributes->set(GetNzbController::REQUEST_USER_ATTRIBUTE, $user);
        $response = app(GetNzbController::class)->getNzb($request);
        $this->assertInstanceOf(StreamedResponse::class, $response);
        $entries = $this->archiveEntries($response);
        $this->assertCount(3, $entries);
        $this->assertEqualsCanonicalizing(['<nzb>Release 1</nzb>', '<nzb>Release 2</nzb>', '<nzb>Release 3</nzb>'], array_values($entries));
        $this->assertSame(3, $user->fresh()->grabs);
        $this->assertSame(3, UserDownload::getDownloadRequests($user->id));
        $this->assertEqualsCanonicalizing(array_slice($ids, 0, 3), UserDownload::getDownloadRequestsForUser($user->id)->pluck('releases_id')->all());
        $this->assertSame([0, 1, 1, 1], DB::table('releases')->orderBy('grabs')->pluck('grabs')->all());
        $this->assertSame([$ids[3]], DB::table('users_releases')->pluck('releases_id')->all());
    }

    public function test_archive_preserves_colliding_names_and_sanitizes_paths(): void
    {
        $guids = [];
        foreach (['Same.Name', 'Same.Name', 'same.name', "../unsafe/path\x01"] as $index => $name) {
            $guid = md5('name-'.$index);
            $guids[] = $guid;
            $this->release($name, ['guid' => $guid]);
            file_put_contents($this->nzbDirectory.'/'.$guid.'.nzb.gz', gzencode('<nzb>'.$index.'</nzb>'));
        }
        $archive = app(NzbArchiveStream::class)->prepare([...$guids, $guids[0]]);
        $entries = $this->archiveEntries($archive['response']);
        $this->assertCount(4, $entries);
        $this->assertCount(4, array_unique(array_map('strtolower', array_keys($entries))));
        $this->assertEqualsCanonicalizing(['<nzb>0</nzb>', '<nzb>1</nzb>', '<nzb>2</nzb>', '<nzb>3</nzb>'], array_values($entries));
        foreach (array_keys($entries) as $name) {
            $this->assertDoesNotMatchRegularExpression('/[\\\\\/\x00-\x1f\x7f]/', $name);
            $this->assertFalse(str_starts_with($name, '.'));
            $this->assertStringEndsWith('.nzb', $name);
        }
    }

    public function test_empty_archive_returns_not_found_and_quota_rejection_does_not_charge(): void
    {
        $user = $this->browserUser();
        $user->role->forceFill(['downloadrequests' => 0])->save();
        $this->assertSame(0, (int) $user->role->fresh()->downloadrequests);
        $guid = md5('not-on-disk');
        $this->release('Missing', ['guid' => $guid]);
        $request = Request::create('/getnzb', 'GET', ['id' => $guid, 'zip' => '1']);
        $request->attributes->set(GetNzbController::REQUEST_USER_ATTRIBUTE, $user);
        $response = app(GetNzbController::class)->getNzb($request);
        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('Unable to create .zip file', $response->getContent());

        file_put_contents($this->nzbDirectory.'/'.$guid.'.nzb.gz', gzencode('<nzb/>'));
        $response = app(GetNzbController::class)->getNzb($request);
        $this->assertStringContainsString('code="501"', $response->getContent());
        $this->assertSame(0, $user->fresh()->grabs);
        $this->assertSame(0, UserDownload::getDownloadRequests($user->id));
    }

    public function test_basket_download_contains_all_250_entries_across_pages(): void
    {
        $user = $this->browserUser();
        DB::table('root_categories')->insert(['id' => 2000, 'title' => 'Movies']);
        DB::table('categories')->insert(['id' => 2030, 'title' => 'HD', 'root_categories_id' => 2000]);
        foreach (range(1, 250) as $index) {
            $guid = md5('basket-'.$index);
            $id = $this->release('Basket '.$index, ['guid' => $guid]);
            DB::table('users_releases')->insert(['users_id' => $user->id, 'releases_id' => $id]);
            file_put_contents($this->nzbDirectory.'/'.$guid.'.nzb.gz', gzencode('<nzb>'.$index.'</nzb>'));
        }
        $request = Request::create('/basket/download', 'POST', ['per' => 24, 'page' => 2]);
        $request->setUserResolver(fn () => $user);
        $controller = app(CartController::class);
        $controller->userdata = $user;
        $response = $controller->download($request, app(GetNzbController::class));
        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertCount(250, $this->archiveEntries($response));
        $this->assertSame(250, UserDownload::getDownloadRequests($user->id));
        $this->assertSame(250, DB::table('users_releases')->count());
    }

    public function test_release_resolution_queries_are_bounded_to_500_guids(): void
    {
        $guids = array_map(fn (int $index): string => md5('batch-'.$index), range(1, 1001));
        $this->release('First', ['guid' => $guids[0]]);
        $this->release('Last', ['guid' => $guids[1000]]);
        foreach ([$guids[0], $guids[1000]] as $guid) {
            file_put_contents($this->nzbDirectory.'/'.$guid.'.nzb.gz', gzencode('<nzb/>'));
        }
        $batchSizes = [];
        DB::listen(static function (QueryExecuted $query) use (&$batchSizes): void {
            if (str_starts_with($query->sql, 'select') && str_contains($query->sql, '"releases"')) {
                $batchSizes[] = count($query->bindings);
            }
        });
        $archive = app(NzbArchiveStream::class)->prepare($guids);
        $this->assertCount(2, $this->archiveEntries($archive['response']));
        $this->assertSame([500, 500, 1], $batchSizes);
    }

    public function test_400_half_megabyte_nzbs_stream_under_128m_with_bounded_memory_growth(): void
    {
        $body = '<nzb>'.str_repeat('x', 512 * 1024 - 11).'</nzb>';
        $compressed = gzencode($body);
        foreach (range(1, 400) as $index) {
            $guid = md5('memory-'.$index);
            DB::table('releases')->insert(['guid' => $guid, 'searchname' => 'Memory '.$index]);
            file_put_contents($this->nzbDirectory.'/'.$guid.'.nzb.gz', $compressed);
        }
        $small = $this->measureArchivePeak(40);
        $large = $this->measureArchivePeak(400);
        $this->assertLessThanOrEqual(8 * 1024 * 1024, $large - $small, "40 files: {$small}; 400 files: {$large}");
    }

    private function measureArchivePeak(int $count): int
    {
        $code = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['nntmux_settings.path_to_nzbs' => $argv[1].'/']);
$guids = App\Models\Release::query()->orderBy('id')->limit((int) $argv[2])->pluck('guid')->all();
$archive = app(App\Services\Nzb\NzbArchiveStream::class)->prepare($guids);
if (count($archive['releaseIds']) !== (int) $argv[2]) { throw new RuntimeException('Incomplete archive'); }
$bytes = 0;
ob_start(static function (string $chunk) use (&$bytes): string { $bytes += strlen($chunk); return ''; }, 8192);
$archive['response']->sendContent();
ob_end_clean();
if ($bytes < (int) $argv[2] * 512 * 1024) { throw new RuntimeException('Incomplete stream'); }
echo memory_get_peak_usage(true);
PHP;
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=128M', '-r', $code, $this->nzbDirectory, (string) $count], base_path(), [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => config('database.connections.sqlite.database'),
            'ZIPSTREAM_COMPRESSION_METHOD' => 'store',
        ]);
        $process->setTimeout(30);
        $process->mustRun();
        $this->assertMatchesRegularExpression('/^\d+$/', $process->getOutput());

        return (int) $process->getOutput();
    }

    /** @return array<string, string> */
    private function archiveEntries(StreamedResponse $response): array
    {
        ob_start();
        try {
            $response->sendContent();
            $contents = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $path = $this->makeTempPath('download', '.zip');
        file_put_contents($path, $contents);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $entries = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entries[$zip->getNameIndex($index)] = $zip->getFromIndex($index);
        }
        $zip->close();

        return $entries;
    }
}
