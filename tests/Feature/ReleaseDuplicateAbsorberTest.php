<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Models\Release;
use App\Services\Nzb\NzbService;
use App\Services\Releases\ReleaseDuplicateAbsorber;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

final class ReleaseDuplicateAbsorberTest extends TestCase
{
    use IsolatedSqliteDatabase;

    private string $nzbDirectory;

    protected function bootstrapSettings(): array
    {
        return ['nzbsplitlevel' => '1'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();

        $this->nzbDirectory = $this->makeTempDirectory('duplicate-absorb').DIRECTORY_SEPARATOR;
        config([
            'nntmux_settings.path_to_nzbs' => $this->nzbDirectory,
            'nntmux_settings.check_passworded_rars' => true,
            'nntmux_settings.unrar_path' => '/usr/bin/unrar',
        ]);

        Schema::create('releases', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('searchname');
            $table->string('searchname_normalized');
            $table->string('guid', 36)->unique();
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('totalpart');
            $table->unsignedInteger('declaredfiles')->nullable();
            $table->double('completion')->default(0);
            $table->integer('nfostatus')->default(0);
            $table->integer('passwordstatus')->default(1);
            $table->integer('haspreview')->default(0);
            $table->unsignedInteger('pp_timeout_count')->default(2);
            $table->unsignedInteger('proc_nfo')->default(1);
            $table->unsignedInteger('proc_files')->default(1);
            $table->unsignedInteger('proc_srr')->default(1);
            $table->unsignedInteger('proc_crc32')->default(1);
            $table->unsignedInteger('proc_uid')->default(1);
            $table->unsignedInteger('proc_hash16k')->default(1);
            $table->unsignedInteger('proc_par2')->default(1);
            $table->unsignedInteger('proc_srrdb')->default(1);
            $table->unsignedInteger('proc_xxx')->default(1);
            $table->unsignedInteger('proc_media_movie')->default(1);
        });
        Schema::create('video_data', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('releases_id');
        });
        Schema::create('audio_data', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('releases_id');
        });

    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_more_complete_incoming_nzb_upgrades_the_anchor_in_place_and_requeues_evidence(): void
    {
        Search::shouldReceive('updateRelease')->once()->with(1)->andReturnTrue();

        $anchor = $this->anchor();
        $nzb = app(NzbService::class);
        $this->writeStoredNzb($nzb, (string) $anchor->guid, $this->nzbXml('old@example.test', 1, 2));

        $absorbed = app(ReleaseDuplicateAbsorber::class)->absorbXml(
            $anchor,
            $this->nzbXml('new@example.test', 2, 2),
            incomingSize: 2_000,
            incomingDeclaredFiles: 1,
            incomingCompletion: 100.0,
        );

        $this->assertTrue($absorbed);
        $this->assertSame(1, DB::table('releases')->count());

        $stored = DB::table('releases')->first();
        $this->assertNotNull($stored);
        $this->assertSame(1, (int) $stored->id);
        $this->assertSame(str_repeat('a', 36), $stored->guid);
        $this->assertSame(2_000, (int) $stored->size);
        $this->assertSame(1, (int) $stored->totalpart);
        $this->assertSame(1, (int) $stored->declaredfiles);
        $this->assertSame(100.0, (float) $stored->completion);
        $this->assertSame(-1, (int) $stored->nfostatus);
        $this->assertSame(-1, (int) $stored->passwordstatus);
        $this->assertSame(-1, (int) $stored->haspreview);
        $this->assertSame(0, (int) $stored->pp_timeout_count);
        $this->assertSame(0, (int) $stored->proc_files);

        $contents = $nzb->readNzbContents((string) $anchor->guid);
        $this->assertIsString($contents);
        $this->assertStringContainsString('new@example.test', $contents);
        $this->assertStringNotContainsString('old@example.test', $contents);
    }

    public function test_equal_or_lower_completion_leaves_the_anchor_and_nzb_unchanged(): void
    {
        Search::shouldReceive('updateRelease')->never();

        foreach ([50.0, 40.0] as $incomingCompletion) {
            DB::table('releases')->delete();
            $anchor = $this->anchor();
            $nzb = app(NzbService::class);
            $oldXml = $this->nzbXml('old@example.test', 1, 2);
            $this->writeStoredNzb($nzb, (string) $anchor->guid, $oldXml);

            $absorbed = app(ReleaseDuplicateAbsorber::class)->absorbXml(
                $anchor,
                $this->nzbXml('new@example.test', 2, 2),
                incomingSize: 2_000,
                incomingDeclaredFiles: 1,
                incomingCompletion: $incomingCompletion,
            );

            $this->assertFalse($absorbed);
            $this->assertSame(1_000, (int) DB::table('releases')->value('size'));
            $this->assertSame(50.0, (float) DB::table('releases')->value('completion'));
            $this->assertSame($oldXml, $nzb->readNzbContents((string) $anchor->guid));
        }
    }

    private function anchor(): Release
    {
        DB::table('releases')->insert([
            'id' => 1,
            'name' => 'ReleaseName',
            'searchname' => 'ReleaseName',
            'searchname_normalized' => 'ReleaseName',
            'guid' => str_repeat('a', 36),
            'size' => 1_000,
            'totalpart' => 1,
            'declaredfiles' => 1,
            'completion' => 50.0,
        ]);

        return Release::query()->findOrFail(1);
    }

    private function writeStoredNzb(NzbService $nzb, string $guid, string $contents): void
    {
        file_put_contents($nzb->getNzbPath($guid, 0, true), gzencode($contents));
    }

    private function nzbXml(string $messageId, int $segments, int $declaredSegments): string
    {
        $segmentXml = '';
        for ($number = 1; $number <= $segments; $number++) {
            $segmentXml .= '<segment bytes="1000" number="'.$number.'">'.$messageId.'</segment>';
        }

        return '<nzb><file poster="poster@example.test" date="1700000000" subject="ReleaseName yEnc (1/'
            .$declaredSegments.')"><groups><group>alt.test</group></groups><segments>'.$segmentXml
            .'</segments></file></nzb>';
    }
}
