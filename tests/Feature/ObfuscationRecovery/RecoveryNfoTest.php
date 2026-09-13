<?php

declare(strict_types=1);

namespace Tests\Feature\ObfuscationRecovery;

use App\Models\Release;
use App\Services\NfoService;
use App\Services\NNTP\NNTPService;
use App\Services\ObfuscationRecovery\RecoveryAlgorithm;
use App\Services\ObfuscationRecovery\RecoveryArticle;
use App\Services\ObfuscationRecovery\RecoveryEvidence;
use App\Services\ObfuscationRecovery\RecoveryEvidencePending;
use App\Services\ObfuscationRecovery\RecoveryFilePlan;
use App\Services\ObfuscationRecovery\RecoveryFileRole;
use App\Services\ObfuscationRecovery\RecoveryNfo;
use App\Services\ObfuscationRecovery\RecoveryPlan;
use App\Services\ObfuscationRecovery\RecoveryPublications;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ObfuscationRecovery\SyntheticPosting;
use Tests\TestCase;

final class RecoveryNfoTest extends TestCase
{
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->increments('id');
        });
        (require database_path('migrations/2026_09_07_172435_add_obfuscation_recovery_storage.php'))->up();
        (require database_path('migrations/2026_09_13_002751_add_recovery_frontier_evidence.php'))->up();
        Schema::create('releases', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('guid');
            $table->string('name')->default('fixture');
            $table->unsignedInteger('groups_id')->default(1);
            $table->unsignedBigInteger('size')->default(1024);
            $table->unsignedTinyInteger('nzbstatus')->default(1);
            $table->timestamp('postdate')->nullable();
            $table->string('leftguid', 1)->default('f');
            $table->integer('nfostatus')->default(-1);
            $table->timestamp('recovery_claimed_at')->nullable();
            $table->uuid('recovery_claim_token')->nullable();
            $table->timestamp('additional_pp_claimed_at')->nullable();
            $table->uuid('additional_pp_claim_token')->nullable();
        });
        Schema::create('release_nfos', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id')->primary();
            $table->binary('nfo');
        });
        Schema::create('release_files', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('releases_id');
            $table->string('name');
            $table->unsignedBigInteger('size');
        });
        config(['filesystems.disks.recovery.root' => $this->makeTempDirectory('nfo-evidence'), 'nntmux.tmp_unrar_path' => $this->makeTempDirectory('nfo-inspection')]);
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_cached_complete_stored_nfo_is_saved_under_one_claim_with_recovery_disabled(): void
    {
        [$publication, $content] = $this->fixture(true);
        $nfo = $this->validator();
        $this->assertSame($content, app(RecoveryNfo::class)->process($publication, $nfo));
        $this->assertSame($content, gzuncompress(substr(DB::table('release_nfos')->value('nfo'), 4)));
        $this->assertSame(NfoService::NFO_FOUND, (int) DB::table('releases')->value('nfostatus'));
        $this->assertNull(DB::table('releases')->value('recovery_claim_token'));
        $this->assertSame(0, DB::table('obfuscation_recovery_attempts')->count());
        $this->assertSame(0, DB::table('obfuscation_recovery_work')->count());
    }

    #[DataProvider('normalReaderStates')]
    public function test_normal_nfo_processing_uses_only_owned_cached_evidence(bool $complete, int $status): void
    {
        [$publication, $content] = $this->fixture($complete);
        DB::table('settings')->insert(['name' => 'lookupnfo', 'value' => 1]);
        DB::table('obfuscation_recovery_publications')->where('id', $publication->id)->update(['initialization_state' => 'complete']);
        DB::table('releases')->update(['nfostatus' => $status, 'postdate' => now()]);
        DB::table('release_files')->insert(['releases_id' => 1, 'name' => 'info.nfo', 'size' => 957]);
        config(['nntmux_settings.path_to_nzbs' => $this->makeTempDirectory('nfo-storage')]);
        $nntp = \Mockery::mock(NNTPService::class);
        $nntp->shouldNotReceive('connect', 'getMessages', 'getMessage', 'getBinary', 'getOverview');
        $this->assertSame($complete ? 1 : 0, app(NfoService::class)->processNfoFiles($nntp));
        if ($complete) {
            $this->assertSame($content, gzuncompress(substr(DB::table('release_nfos')->value('nfo'), 4)));
            $this->assertSame(NfoService::NFO_FOUND, (int) DB::table('releases')->value('nfostatus'));
        } else {
            $this->assertSame(0, DB::table('release_nfos')->count());
            $this->assertSame(NfoService::NFO_NONFO, (int) DB::table('releases')->value('nfostatus'));
        }
        $this->assertSame(0, DB::table('obfuscation_recovery_attempts')->count());
        $this->assertNull(DB::table('releases')->value('recovery_claim_token'));
    }

    /** @return array<string,array{bool,int}> */
    public static function normalReaderStates(): array
    {
        return ['main-cached' => [true, -1], 'archive-cached' => [true, -9], 'bounded-missing' => [false, -1]];
    }

    public function test_claim_replacement_during_nfo_validation_cannot_write_content_or_terminal_state(): void
    {
        [$publication] = $this->fixture(true);
        $nfo = \Mockery::mock(NfoService::class);
        $nfo->shouldReceive('isNFO')->once()->andReturnUsing(function (): bool {
            DB::table('releases')->update(['recovery_claim_token' => 'replacement']);

            return true;
        });
        try {
            app(RecoveryNfo::class)->process($publication, $nfo);
            $this->fail('A replaced inspector must not persist its result.');
        } catch (\RuntimeException $error) {
            $this->assertSame('recovery_inspection_claim_lost', $error->getMessage());
        }
        $this->assertSame(0, DB::table('release_nfos')->count());
        $this->assertSame(-1, (int) DB::table('releases')->value('nfostatus'));
        $this->assertNull(DB::table('obfuscation_recovery_publications')->value('nfo_outcome'));
        $this->assertSame('replacement', DB::table('releases')->value('recovery_claim_token'));
    }

    public function test_live_additional_claim_returns_pending_without_nfo_mutations_or_new_downloads(): void
    {
        [$publication] = $this->fixture(true);
        DB::table('releases')->update(['additional_pp_claimed_at' => now(), 'additional_pp_claim_token' => 'additional']);
        $this->assertInstanceOf(RecoveryEvidencePending::class, app(RecoveryNfo::class)->process($publication, $this->validator()));
        $this->assertSame(-1, (int) DB::table('releases')->value('nfostatus'));
        $this->assertSame(0, DB::table('release_nfos')->count());
        $this->assertSame(0, DB::table('obfuscation_recovery_work')->count());
    }

    public function test_incomplete_stored_nfo_cannot_be_saved_as_complete_content(): void
    {
        [$publication] = $this->fixture(false);
        $this->assertFalse(app(RecoveryNfo::class)->process($publication, $this->validator()));
        $this->assertSame('bounded_nfo_unavailable', DB::table('obfuscation_recovery_publications')->value('nfo_outcome'));
        $this->assertSame(0, DB::table('release_nfos')->count());
        $this->assertSame(0, DB::table('obfuscation_recovery_attempts')->count());
    }

    public function test_unscoped_legacy_nfo_writers_cannot_attach_content_to_an_archive_inventory(): void
    {
        [$publication, $content] = $this->fixture(true);
        $nfo = $this->validator();
        $this->assertFalse($nfo->storeNfoContent(1, $content));
        $this->assertFalse($nfo->addAlternateNfo($content, Release::query()->first(), \Mockery::mock(NNTPService::class)));
        $this->assertSame(0, DB::table('release_nfos')->count());
        $this->assertSame(-1, (int) DB::table('releases')->value('nfostatus'));
        $this->assertSame($content, app(RecoveryNfo::class)->process($publication, $nfo));
    }

    private function validator(): NfoService
    {
        return app(NfoService::class);
    }

    private function fixture(bool $complete): array
    {
        $content = str_pad("Synthetic release notes\nTitle: Local fixture\nFormat: text\n", 957, ' ');
        $archive = SyntheticPosting::rar([1024], 'info.nfo', $content);
        $files = [];
        $records = [];
        foreach (range(1, 4) as $i) {
            $file = new RecoveryFilePlan(md5('volume-'.$i), RecoveryFileRole::RarVolume, 1024, 1, 'archive.part0'.$i.'.rar', 'rar4');
            $files[] = $file;
            $records[$file->identity] = [['file' => $file->identity, 'role' => $file->role->value, 'ordinal' => 1,
                'group' => 'alt.fixture', 'message_id' => 'volume-'.$i.'@fixture']];
            if ($i === 1) {
                app(RecoveryEvidence::class)->store('volume-1@fixture', new RecoveryArticle('opaque', 1024, 1, 1, 1, 1024,
                    $complete ? $archive['volumes'][0] : substr($archive['volumes'][0], 0, 128), $complete, false, false, null));
            }
        }
        $index = new RecoveryFilePlan('index@fixture', RecoveryFileRole::Index, 4, 1, 'index.par2', 'par2');
        $files[] = $index;
        $records[$index->identity] = [['file' => $index->identity, 'role' => $index->role->value, 'ordinal' => 1,
            'group' => 'alt.fixture', 'message_id' => $index->identity]];
        $plan = new RecoveryPlan(RecoveryAlgorithm::Rar, 1, 1, 'alt.fixture', 'epoch', str_repeat('a', 32), $files, str_repeat('b', 64));
        $publication = app(RecoveryPublications::class)->register($plan);
        DB::table('obfuscation_recovery_publications')->where('id', $publication->id)->update([
            'releases_id' => 1, 'guid' => 'fixture', 'state' => 'published', 'head_membership' => json_encode($records, JSON_THROW_ON_ERROR),
        ]);
        DB::table('releases')->insert(['id' => 1, 'guid' => 'fixture']);

        return [DB::table('obfuscation_recovery_publications')->first(), $archive['content']];
    }
}
