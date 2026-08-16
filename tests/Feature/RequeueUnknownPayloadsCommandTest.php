<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Release;
use App\Services\AdditionalProcessing\Config\PasswordInspectionMode;
use App\Services\Nzb\NzbService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RequeueUnknownPayloadsCommandTest extends TestCase
{
    /**
     * @var array<string, string>
     */
    private array $nzbPaths = [];

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('release_files');
        Schema::dropIfExists('releases');
        Schema::dropIfExists('settings');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->text('value')->nullable();
        });
        Schema::create('releases', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('searchname');
            $table->string('fromname');
            $table->dateTime('postdate');
            $table->dateTime('adddate');
            $table->string('guid');
            $table->char('leftguid', 1);
            $table->unsignedInteger('categories_id');
            $table->unsignedBigInteger('size');
            $table->integer('nzbstatus');
            $table->integer('haspreview');
            $table->integer('passwordstatus');
            $table->integer('isrenamed');
        });
        Schema::create('release_files', function (Blueprint $table): void {
            $table->unsignedBigInteger('releases_id');
            $table->string('name');
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamps();
        });

        DB::table('settings')->insert([
            ['name' => 'minsizetopostprocess', 'value' => '100'],
            ['name' => 'maxsizetopostprocess', 'value' => '0'],
            ['name' => 'checkpasswordedrar', 'value' => '1'],
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_dry_runs_then_requeues_only_processed_unknown_payload_releases(): void
    {
        $eligible = $this->release(1, 'eligible-bin', 'opaque.bin', size: 200);
        $normal = $this->release(2, 'normal-media', 'movie.mkv', size: 200);
        $pendingPassword = $this->release(3, 'password-pending', 'opaque', size: 200, passwordStatus: 1);
        $tooSmall = $this->release(4, 'too-small', 'opaque.bin', size: 100);
        $alreadyHasFiles = $this->release(5, 'has-files', 'opaque.bin', size: 200);
        $otherNonInformative = $this->release(6, 'not-targeted-dat', 'opaque.dat', size: 200);
        DB::table('release_files')->insert([
            'releases_id' => $alreadyHasFiles->id,
            'name' => 'known.file',
            'size' => 1,
        ]);
        $this->bindNzbService();
        $beforeHashes = array_map('sha1_file', $this->nzbPaths);

        $this->artisan('releases:requeue-unknown-payloads')
            ->expectsOutput('Dry run: scanned 3 releases; 1 contained extensionless or .bin payloads; 0 updated.')
            ->assertSuccessful();

        $this->assertSame(0, $eligible->fresh()?->haspreview);
        $this->assertSame($beforeHashes, array_map('sha1_file', $this->nzbPaths));

        $this->artisan('releases:requeue-unknown-payloads', ['--apply' => true, '--category' => 2000, '--limit' => 1])
            ->expectsOutput('Applied: scanned 1 releases; 1 contained extensionless or .bin payloads; 1 updated.')
            ->assertSuccessful();

        $this->assertSame(-1, $eligible->fresh()?->haspreview);
        $this->assertSame(PasswordInspectionMode::pendingReleaseStatus(), $eligible->fresh()?->passwordstatus);
        $this->assertSame(0, $normal->fresh()?->haspreview);
        $this->assertSame(1, $pendingPassword->fresh()?->passwordstatus);
        $this->assertSame(0, $tooSmall->fresh()?->haspreview);
        $this->assertSame(0, $alreadyHasFiles->fresh()?->haspreview);
        $this->assertSame(0, $otherNonInformative->fresh()?->haspreview);
        $this->assertSame($beforeHashes, array_map('sha1_file', $this->nzbPaths));
    }

    private function release(
        int $id,
        string $guid,
        string $subject,
        int $size,
        int $passwordStatus = 0,
    ): Release {
        $release = Release::withoutEvents(fn (): Release => Release::factory()->create([
            'id' => $id,
            'guid' => $guid,
            'leftguid' => $guid[0],
            'categories_id' => 2000,
            'size' => $size,
            'haspreview' => 0,
            'passwordstatus' => $passwordStatus,
        ]));
        $path = $this->makeTempPath($guid, '.nzb.gz');
        File::put($path, gzencode($this->nzb($subject)));
        $this->nzbPaths[$guid] = $path;

        return $release;
    }

    private function bindNzbService(): void
    {
        $nzbService = Mockery::mock(NzbService::class);
        foreach ($this->nzbPaths as $guid => $path) {
            $nzbService->shouldReceive('nzbPath')->with($guid)->andReturn($path);
        }
        $this->app->instance(NzbService::class, $nzbService);
    }

    private function nzb(string $subject): string
    {
        return '<?xml version="1.0"?><nzb><file subject="'.$subject.'"><groups><group>alt.binaries.test</group></groups><segments><segment bytes="200" number="1">message-id</segment></segments></file></nzb>';
    }
}
