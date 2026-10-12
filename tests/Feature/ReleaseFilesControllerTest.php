<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Nzb\NzbService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

class ReleaseFilesControllerTest extends TestCase
{
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->withoutMiddleware();
        Schema::create('releases', function (Blueprint $table): void {
            $table->id();
            $table->string('guid')->unique();
            $table->string('searchname');
        });
        DB::table('releases')->insert(['guid' => 'abc', 'searchname' => 'Example']);
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_returns_bounded_json_with_only_summary_fields(): void
    {
        $this->nzb('<nzb>'.str_repeat('<file subject="file"><segments><segment bytes="1000">private-id</segment></segments></file>', 25).'</nzb>');
        $this->getJson('/release/abc/files?page=2&per=24')->assertOk()->assertExactJson([
            'release' => ['guid' => 'abc', 'searchname' => 'Example'],
            'files' => [['index' => 24, 'title' => 'file', 'size' => 1000]],
            'total' => 25, 'page' => 2, 'per' => 24, 'last_page' => 2,
        ]);
    }

    public function test_route_requires_authentication_and_verification(): void
    {
        $middleware = Route::getRoutes()->getByName('release.files')->gatherMiddleware();
        self::assertContains('auth', $middleware);
        self::assertContains('isVerified', $middleware);
    }

    public function test_missing_release_and_missing_file_return_404(): void
    {
        $this->getJson('/release/missing/files')->assertNotFound();
        $this->mock(NzbService::class)->shouldReceive('nzbPath')->with('abc')->andReturn(false);
        $this->getJson('/release/abc/files')->assertNotFound();
    }

    public function test_invalid_paging_and_malformed_tail_return_422_without_files(): void
    {
        $this->nzb('<nzb><file subject="partial"/>');
        $this->getJson('/release/abc/files')->assertUnprocessable()->assertJsonMissingPath('files');
        foreach (['page=0', 'per=25', 'page[]=1', 'page=999999999999999999999999'] as $query) {
            $this->getJson('/release/abc/files?'.$query)->assertUnprocessable()->assertJsonMissingPath('files');
        }
    }

    public function test_a_request_that_does_not_ask_for_json_gets_the_same_json_body(): void
    {
        // issue #1056 section 8: the standalone file-list page is gone, every request gets the JSON
        $this->nzb('<nzb>'.str_repeat('<file subject="file"><segments><segment bytes="1000">private-id</segment></segments></file>', 25).'</nzb>');
        $asked = $this->getJson('/release/abc/files?page=2&per=24')->assertOk();
        $plain = $this->get('/release/abc/files?page=2&per=24')->assertOk();

        $plain->assertHeader('Content-Type', 'application/json')->assertExactJson([
            'release' => ['guid' => 'abc', 'searchname' => 'Example'],
            'files' => [['index' => 24, 'title' => 'file', 'size' => 1000]],
            'total' => 25, 'page' => 2, 'per' => 24, 'last_page' => 2,
        ]);
        $this->assertSame($asked->getContent(), $plain->getContent());
    }

    private function nzb(string $xml): void
    {
        $path = $this->makeTempPath('summary', '.gz');
        file_put_contents($path, gzencode($xml));
        $this->mock(NzbService::class)->shouldReceive('nzbPath')->with('abc')->andReturn($path);
    }
}
