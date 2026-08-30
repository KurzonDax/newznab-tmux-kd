<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MusicInfo;
use App\Services\MusicService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

final class MusicIdentityRetirementTest extends TestCase
{
    use IsolatedSqliteDatabase;

    /**
     * @return array<string, string>
     */
    protected function bootstrapSettings(): array
    {
        return [
            'amazonpubkey' => '',
            'amazonprivkey' => '',
            'amazonassociatetag' => '',
            'maxmusicprocessed' => '25',
            'amazonsleep' => '0',
            'categorizeforeign' => '0',
            'catwebdl' => '0',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();

        Schema::create('releases', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('searchname');
            $table->unsignedInteger('groups_id')->default(1);
            $table->dateTime('postdate');
            $table->char('leftguid', 1)->default('a');
            $table->integer('categories_id');
            $table->integer('musicinfo_id')->nullable();
            $table->tinyInteger('isrenamed')->default(1);
        });
    }

    protected function tearDown(): void
    {
        DB::disconnect();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_legacy_worker_does_not_attach_identity_from_name_search(): void
    {
        $releaseId = $this->createRelease('Artist - Album 2018 FLAC');
        $service = new MusicIdentityWorkerTestDouble;

        $service->processMusicReleases(false, lookupMode: 1);

        $this->assertNull(DB::table('releases')->where('id', $releaseId)->value('musicinfo_id'));
        $this->assertSame(0, $service->nameLookupAttempts);
        $this->assertSame(0, $service->identityAttachmentAttempts);
    }

    public function test_retryable_unresolved_release_is_distinct_from_genuine_no_match(): void
    {
        $retryableReleaseId = $this->createRelease('Artist - Album 2018 FLAC');
        $noMatchReleaseId = $this->createRelease('unparseable-release-without-a-year');

        (new MusicIdentityWorkerTestDouble)->processMusicReleases(false, lookupMode: 1);

        $this->assertNull(DB::table('releases')->where('id', $retryableReleaseId)->value('musicinfo_id'));
        $this->assertSame(-2, DB::table('releases')->where('id', $noMatchReleaseId)->value('musicinfo_id'));
    }

    public function test_update_music_info_does_not_implicitly_search_itunes_by_name(): void
    {
        $service = new MusicInfoUpdateTestDouble;

        $this->assertFalse($service->updateMusicInfo('Artist - Album', '2018'));
        $this->assertSame(0, $service->itunesSearchAttempts);
    }

    private function createRelease(string $searchName): int
    {
        return (int) DB::table('releases')->insertGetId([
            'searchname' => $searchName,
            'postdate' => now(),
            'categories_id' => Category::MUSIC_MP3,
        ]);
    }
}

final class MusicIdentityWorkerTestDouble extends MusicService
{
    public int $nameLookupAttempts = 0;

    public int $identityAttachmentAttempts = 0;

    public function getMusicInfoByName(string $artist, string $album): ?MusicInfo
    {
        $this->nameLookupAttempts++;

        return null;
    }

    public function updateMusicInfo(string $title, string $year, ?array $amazdata = null): int|false
    {
        $this->identityAttachmentAttempts++;

        return false;
    }
}

final class MusicInfoUpdateTestDouble extends MusicService
{
    public int $itunesSearchAttempts = 0;

    protected function fetchItunesMusicProperties(string $title): array|false
    {
        $this->itunesSearchAttempts++;

        return false;
    }
}
