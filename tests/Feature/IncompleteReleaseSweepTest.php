<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Release;
use App\Services\NNTP\NntpProviderPool;
use App\Services\Nzb\NzbCreationCandidateQuery;
use App\Services\Releases\IncompleteReleaseSweepQuery;
use App\Support\ReleaseRepairingContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithSecondaryProviders;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/**
 * Which incomplete releases the completion sweep may delete: measured below the threshold, past
 * the late-header grace, with no late collection waiting to merge, and not held by a secondary
 * provider that may still be reading the post.
 */
class IncompleteReleaseSweepTest extends TestCase
{
    use InteractsWithSecondaryProviders;
    use IsolatedSqliteDatabase;

    private const int GROUP = 1;

    private const string POSTED = '2026-10-01 10:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        config(['app.timezone' => 'UTC']);
        $this->createReleasesTable();
        $tables = ProductionTables::fromAuthority();
        $tables->create('collections', ['id', 'collectionhash', 'releases_id']);
        $tables->create('usenet_groups', ['id', 'name', 'active']);
        $tables->create('usenet_group_provider_cursors');
        DB::table('usenet_groups')->insert(['id' => self::GROUP, 'name' => 'alt.binaries.active', 'active' => 1]);
        DB::table('settings')->insert(['name' => 'delaytime', 'value' => '12']);
        NzbCreationCandidateQuery::flushCapabilityCache();
    }

    protected function tearDown(): void
    {
        NzbCreationCandidateQuery::flushCapabilityCache();
        NntpProviderPool::forgetConfiguredProviders();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    #[Test]
    public function only_a_measured_release_below_the_threshold_is_selected(): void
    {
        $this->insertRelease(1, completion: 80.0);
        $this->insertRelease(2, completion: 0.0);
        $this->insertRelease(3, completion: 95.0);
        $this->insertRelease(4, completion: 99.0);

        $this->assertSame([1], $this->sweptIds());
    }

    #[Test]
    public function a_live_processing_claim_holds_a_release_and_a_stale_one_does_not(): void
    {
        $live = Carbon::now()->toDateTimeString();
        $stale = Carbon::now()->subHour()->toDateTimeString();

        $this->insertRelease(1, additionalClaimedAt: $live);
        $this->insertRelease(2, recoveryClaimedAt: $live);
        $this->insertRelease(3, additionalClaimedAt: $stale);
        $this->insertRelease(4, recoveryClaimedAt: $stale);

        $this->assertSame([3, 4], $this->sweptIds());
    }

    #[Test]
    public function a_release_without_an_nzb_is_not_selected(): void
    {
        $this->insertRelease(1, nzbstatus: 0);

        $this->assertSame([], $this->sweptIds());
    }

    #[Test]
    public function a_release_is_selected_only_once_the_late_header_grace_has_passed(): void
    {
        $this->insertRelease(1, addedHoursAgo: 71);
        $this->assertSame([], $this->sweptIds());

        DB::table('releases')->where('id', 1)->update(['adddate' => now()->subHours(73)]);
        $this->assertSame([1], $this->sweptIds());
    }

    #[Test]
    public function a_late_collection_waiting_to_merge_holds_its_release(): void
    {
        $hash = sha1('late-collection', true);
        $this->insertRelease(1, collectionhash: $hash);
        $this->insertRelease(2);
        DB::table('collections')->insert(['id' => 10, 'collectionhash' => $hash, 'releases_id' => null]);

        $this->assertSame([2], $this->sweptIds(), 'A null collectionhash has no late collection to wait for.');

        DB::table('collections')->where('id', 10)->delete();
        $this->assertSame([1, 2], $this->sweptIds());
    }

    #[Test]
    public function the_configured_wait_replaces_the_default_grace(): void
    {
        $this->insertRelease(1, addedHoursAgo: 23);
        $this->insertRelease(2, addedHoursAgo: 25);

        $this->assertSame([2], $this->sweptIds(graceHours: 24));
    }

    #[Test]
    public function a_one_hour_wait_still_holds_a_release_with_a_late_collection_waiting(): void
    {
        $hash = sha1('late-collection', true);
        $this->insertRelease(1, addedMinutesAgo: 59);
        $this->insertRelease(2, addedMinutesAgo: 61, collectionhash: $hash);

        $this->assertSame([2], $this->sweptIds(graceHours: 1));

        DB::table('collections')->insert(['id' => 10, 'collectionhash' => $hash, 'releases_id' => null]);
        $this->assertSame([], $this->sweptIds(graceHours: 1));
    }

    #[Test]
    public function a_late_collection_is_waiting_only_for_the_release_whose_hash_it_shares(): void
    {
        $hash = sha1('late-collection', true);
        $this->insertRelease(1, collectionhash: $hash);
        $this->insertRelease(2, collectionhash: sha1('no-collection', true));
        $this->insertRelease(3);
        DB::table('collections')->insert(['id' => 10, 'collectionhash' => $hash, 'releases_id' => null]);

        $this->assertTrue(IncompleteReleaseSweepQuery::lateCollectionWaiting(Release::query()->findOrFail(1)));
        $this->assertFalse(IncompleteReleaseSweepQuery::lateCollectionWaiting(Release::query()->findOrFail(2)));
        $this->assertFalse(IncompleteReleaseSweepQuery::lateCollectionWaiting(Release::query()->findOrFail(3)), 'A null collectionhash has no late collection to wait for.');
    }

    #[Test]
    public function without_a_secondary_provider_no_selected_release_is_held(): void
    {
        $this->configureProviders([['position' => 1, 'name' => 'primary', 'host' => 'news.example.invalid']]);
        $this->secondaryPosition(self::GROUP, '2026-10-01 11:00:00');
        $this->insertRelease(1);

        $this->assertFalse(IncompleteReleaseSweepQuery::lateHeadersPending(Release::query()->findOrFail(1), new ReleaseRepairingContext));
    }

    #[Test]
    public function a_secondary_provider_still_reading_the_post_holds_the_release_until_its_position_passes_it(): void
    {
        $this->configureSecondaryProvider();
        $this->insertRelease(1);
        $release = Release::query()->findOrFail(1);

        // delaytime is 12, so the window closes at 22:00 on the post's day.
        $this->secondaryPosition(self::GROUP, '2026-10-01 21:00:00');
        $this->assertTrue(IncompleteReleaseSweepQuery::lateHeadersPending($release, new ReleaseRepairingContext));

        $this->secondaryPosition(self::GROUP, '2026-10-01 23:00:00');
        $this->assertFalse(IncompleteReleaseSweepQuery::lateHeadersPending($release, new ReleaseRepairingContext));
    }

    /**
     * @return list<int>
     */
    private function sweptIds(float $threshold = 95.0, int $graceHours = IncompleteReleaseSweepQuery::DEFAULT_LATE_HEADER_GRACE_HOURS): array
    {
        return IncompleteReleaseSweepQuery::builder($threshold, $graceHours)
            ->orderBy('id')
            ->pluck('id')
            ->map(intval(...))
            ->all();
    }

    private function insertRelease(
        int $id,
        float $completion = 80.0,
        int $nzbstatus = 1,
        int $addedHoursAgo = 73,
        ?int $addedMinutesAgo = null,
        ?string $collectionhash = null,
        ?string $additionalClaimedAt = null,
        ?string $recoveryClaimedAt = null,
    ): void {
        DB::table('releases')->insert([
            'id' => $id,
            'guid' => sprintf('%032x', $id),
            'nzbstatus' => $nzbstatus,
            'completion' => $completion,
            'adddate' => ($addedMinutesAgo === null ? now()->subHours($addedHoursAgo) : now()->subMinutes($addedMinutesAgo))->toDateTimeString(),
            'collectionhash' => $collectionhash,
            'additional_pp_claimed_at' => $additionalClaimedAt,
            'recovery_claimed_at' => $recoveryClaimedAt,
            'groups_id' => self::GROUP,
            'postdate' => self::POSTED,
        ]);
    }

    private function createReleasesTable(): void
    {
        DB::statement('DROP TABLE IF EXISTS releases');
        DB::statement('CREATE TABLE releases (
            id INTEGER PRIMARY KEY,
            guid VARCHAR(64) UNIQUE,
            nzbstatus INTEGER NOT NULL DEFAULT 0,
            completion DOUBLE NOT NULL DEFAULT 0,
            additional_pp_claimed_at DATETIME NULL,
            recovery_claimed_at DATETIME NULL,
            declaredfiles INTEGER NULL,
            totalpart INTEGER NOT NULL DEFAULT 0,
            groups_id INTEGER NULL,
            firstarticle INTEGER NULL,
            lastarticle INTEGER NULL,
            postdate DATETIME NULL,
            adddate DATETIME NULL,
            collectionhash BLOB NULL UNIQUE,
            haspreview INTEGER NOT NULL DEFAULT -1,
            passwordstatus INTEGER NOT NULL DEFAULT -1
        )');
    }
}
