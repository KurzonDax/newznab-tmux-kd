<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AdditionalProcessing\AdditionalCandidateQuery;
use App\Services\AdditionalProcessing\ReleaseClaimant;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

class AdditionalCandidateQueryTest extends TestCase
{
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();

        config([
            'nntmux_settings.check_passworded_rars' => true,
            'nntmux_settings.unrar_path' => '/usr/bin/unrar',
        ]);

        $this->createSchema();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_bucket_chars_preserve_alphabetic_guid_buckets(): void
    {
        DB::table('categories')->insert([
            ['id' => 1],
            ['id' => 2],
        ]);

        DB::table('releases')->insert([
            $this->releaseRow(1, '0'),
            $this->releaseRow(2, '9'),
            $this->releaseRow(3, 'a'),
            $this->releaseRow(4, 'a'),
            $this->releaseRow(5, 'f'),
            $this->releaseRow(6, 'b', categoriesId: 2),
        ]);

        $chars = AdditionalCandidateQuery::bucketChars();
        sort($chars);

        $this->assertSame(['0', '9', 'a', 'b', 'f'], $chars);
    }

    public function test_bucket_chars_skip_active_claims_but_include_stale_claims(): void
    {
        DB::table('categories')->insert([
            ['id' => 1],
        ]);

        DB::table('releases')->insert([
            $this->releaseRow(1, 'a', claimedAt: now()),
            $this->releaseRow(2, 'b', claimedAt: now()->subSeconds(301)),
            $this->releaseRow(3, 'c'),
        ]);

        $chars = AdditionalCandidateQuery::bucketChars();
        sort($chars);

        $this->assertSame(['b', 'c'], $chars);
    }

    public function test_monitor_builder_can_include_claimed_releases_while_available_builder_excludes_them(): void
    {
        DB::table('categories')->insert([
            ['id' => 1],
        ]);

        DB::table('releases')->insert([
            $this->releaseRow(1, 'a', claimedAt: now()),
            $this->releaseRow(2, 'b'),
            // Skipped by the per-root Preview Generation policy: never selected.
            $this->releaseRow(3, 'c', hasPreview: -2),
        ]);

        $this->assertSame(1, AdditionalCandidateQuery::baseBuilder()->count());
        $this->assertSame(2, AdditionalCandidateQuery::baseBuilder(includeClaimed: true)->count());
    }

    public function test_backlog_counts_are_aggregated_by_bucket_in_one_shape(): void
    {
        DB::table('categories')->insert(['id' => 1]);
        DB::table('releases')->insert([
            $this->releaseRow(1, 'a'),
            $this->releaseRow(2, 'a', claimedAt: now()),
            $this->releaseRow(3, 'b', claimedAt: now()->subSeconds(301)),
        ]);

        $this->assertSame([
            ['bucket' => 'a', 'total' => 2, 'available' => 1],
            ['bucket' => 'b', 'total' => 1, 'available' => 1],
        ], AdditionalCandidateQuery::bucketBacklog());
        $this->assertSame(['total' => 3, 'available' => 2], AdditionalCandidateQuery::backlogCounts());
        $this->assertSame([
            ['bucket' => 'a', 'count' => 1],
            ['bucket' => 'b', 'count' => 1],
        ], AdditionalCandidateQuery::availableBucketCounts());
    }

    public function test_claim_batch_excludes_active_claims_and_recovers_stale_claims(): void
    {
        DB::table('categories')->insert([
            ['id' => 1],
        ]);

        DB::table('releases')->insert([
            $this->releaseRow(1, 'a', postdate: '2026-07-12 10:00:00'),
            $this->releaseRow(2, 'a', postdate: '2026-07-12 09:00:00'),
        ]);

        $first = AdditionalCandidateQuery::claimBatch('a', 1, 'token-one', columns: ['id']);
        $this->assertSame([1], $first->pluck('id')->all());

        $second = AdditionalCandidateQuery::claimBatch('a', 10, 'token-two', columns: ['id']);
        $this->assertSame([2], $second->pluck('id')->all());

        DB::table('releases')
            ->where('id', 1)
            ->update(['additional_pp_claimed_at' => now()->subSeconds(301)]);

        $third = AdditionalCandidateQuery::claimBatch('a', 10, 'token-three', columns: ['id']);
        $this->assertSame([1], $third->pluck('id')->all());
    }

    public function test_claim_batch_returns_only_candidates_won_after_a_competing_stamp(): void
    {
        DB::table('categories')->insert(['id' => 1]);
        DB::table('releases')->insert([
            $this->releaseRow(1, 'a', postdate: '2026-07-12 10:00:00'),
            $this->releaseRow(2, 'a', postdate: '2026-07-12 09:00:00'),
        ]);

        $competitorHasStamped = false;
        DB::listen(function (QueryExecuted $query) use (&$competitorHasStamped): void {
            if ($competitorHasStamped || ! str_starts_with($query->sql, 'select "r"."id"')) {
                return;
            }

            $competitorHasStamped = true;
            DB::table('releases')->where('id', 1)->update([
                'additional_pp_claimed_at' => now(),
                'additional_pp_claim_token' => 'competing-worker',
            ]);
        });

        $claimed = AdditionalCandidateQuery::claimBatch('a', 2, 'worker-token', columns: ['id']);

        $this->assertTrue($competitorHasStamped);
        $this->assertSame([2], $claimed->pluck('id')->all());
        $this->assertSame('competing-worker', DB::table('releases')->where('id', 1)->value('additional_pp_claim_token'));
        $this->assertSame('worker-token', DB::table('releases')->where('id', 2)->value('additional_pp_claim_token'));
    }

    public function test_claim_does_not_mutate_a_reused_base_builder(): void
    {
        DB::table('categories')->insert(['id' => 1]);
        DB::table('releases')->insert([
            $this->releaseRow(1, 'a', postdate: '2026-07-12 10:00:00'),
            $this->releaseRow(2, 'a', postdate: '2026-07-12 09:00:00'),
        ]);

        $base = AdditionalCandidateQuery::baseBuilder(guidChar: 'a', includePasswordStatuses: false);
        $sql = $base->toSql();
        $bindings = $base->getBindings();

        ReleaseClaimant::claim($base, 'worker-one', 1, ['id'], [999]);
        $this->assertSame($sql, $base->toSql());
        $this->assertSame($bindings, $base->getBindings());

        ReleaseClaimant::claim($base, 'worker-two', 1, ['id'], [999]);
        $this->assertSame($sql, $base->toSql());
        $this->assertSame($bindings, $base->getBindings());
    }

    public function test_claim_rejects_a_base_builder_with_a_password_status_predicate(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Claim base builders must not include a passwordstatus predicate.');

        ReleaseClaimant::claim(
            AdditionalCandidateQuery::baseBuilder(guidChar: 'a'),
            'worker-token',
            1,
            ['id'],
        );
    }

    public function test_claim_allows_a_non_predicate_password_status_reference(): void
    {
        DB::table('categories')->insert(['id' => 1]);
        DB::table('releases')->insert($this->releaseRow(1, 'a'));

        $base = AdditionalCandidateQuery::baseBuilder(guidChar: 'a', includePasswordStatuses: false)
            ->addSelect('r.passwordstatus');

        $claimed = ReleaseClaimant::claim($base, 'worker-token', 1, ['id']);

        $this->assertSame([1], $claimed->pluck('id')->all());
    }

    public function test_claim_reads_each_pending_password_state_by_equality_and_merges_newest_first(): void
    {
        DB::table('categories')->insert(['id' => 1]);
        DB::table('releases')->insert([
            $this->releaseRow(1, 'a', postdate: '2026-07-12 12:00:00', passwordStatus: 0),
            $this->releaseRow(2, 'a', postdate: '2026-07-12 11:00:00', passwordStatus: -1),
            $this->releaseRow(3, 'a', postdate: '2026-07-12 10:00:00', passwordStatus: 0),
            $this->releaseRow(4, 'a', postdate: '2026-07-12 09:00:00', passwordStatus: -1),
        ]);

        $candidateQueries = [];
        DB::listen(static function (QueryExecuted $query) use (&$candidateQueries): void {
            if (str_starts_with($query->sql, 'select "r"."id", "r"."postdate"')) {
                $candidateQueries[] = $query->sql;
            }
        });

        $claimed = AdditionalCandidateQuery::claimBatch('a', 3, 'worker-token', columns: ['id']);

        $this->assertSame([1, 2, 3], $claimed->pluck('id')->all());
        $this->assertCount(2, $candidateQueries);
        foreach ($candidateQueries as $candidateQuery) {
            $this->assertStringContainsString('"r"."passwordstatus" = ?', $candidateQuery);
            $this->assertStringNotContainsString('"r"."passwordstatus" in', $candidateQuery);
        }
    }

    public function test_claim_excludes_requested_release_ids_before_applying_the_limit(): void
    {
        DB::table('categories')->insert(['id' => 1]);
        DB::table('releases')->insert([
            $this->releaseRow(1, 'a', postdate: '2026-07-12 12:00:00', passwordStatus: 0),
            $this->releaseRow(2, 'a', postdate: '2026-07-12 11:00:00', passwordStatus: -1),
            $this->releaseRow(3, 'a', postdate: '2026-07-12 10:00:00', passwordStatus: 0),
        ]);

        $claimed = AdditionalCandidateQuery::claimBatch(
            'a',
            2,
            'worker-token',
            columns: ['id'],
            excludedReleaseIds: [1],
        );

        $this->assertSame([2, 3], $claimed->pluck('id')->all());
    }

    public function test_password_inspection_enabled_selects_both_pending_sentinels(): void
    {
        config([
            'nntmux_settings.check_passworded_rars' => true,
            'nntmux_settings.unrar_path' => '/usr/bin/unrar',
        ]);

        DB::table('categories')->insert(['id' => 1]);
        DB::table('releases')->insert([
            $this->releaseRow(1, 'a', passwordStatus: -1),
            $this->releaseRow(2, 'b', passwordStatus: 0),
        ]);

        $this->assertSame([1, 2], AdditionalCandidateQuery::baseBuilder()->pluck('r.id')->all());
    }

    public function test_password_inspection_disabled_selects_both_pending_sentinels(): void
    {
        config([
            'nntmux_settings.check_passworded_rars' => false,
            'nntmux_settings.unrar_path' => false,
        ]);

        DB::table('categories')->insert(['id' => 1]);
        DB::table('releases')->insert([
            $this->releaseRow(1, 'a', passwordStatus: 0),
            $this->releaseRow(2, 'b', passwordStatus: -1),
            $this->releaseRow(3, 'c', passwordStatus: 0, hasPreview: 0),
        ]);

        $this->assertSame([1, 2], AdditionalCandidateQuery::baseBuilder()->pluck('r.id')->all());
        $this->assertSame([
            ['bucket' => 'a', 'total' => 1, 'available' => 1],
            ['bucket' => 'b', 'total' => 1, 'available' => 1],
        ], AdditionalCandidateQuery::bucketBacklog());
        $this->assertSame([1], AdditionalCandidateQuery::claimBatch('a', 25, 'worker', columns: ['id'])->pluck('id')->all());
    }

    public function test_password_inspection_without_usable_unrar_selects_no_password_state(): void
    {
        config([
            'nntmux_settings.check_passworded_rars' => true,
            'nntmux_settings.unrar_path' => '',
        ]);

        DB::table('categories')->insert(['id' => 1]);
        DB::table('releases')->insert($this->releaseRow(1, 'a', passwordStatus: 0));

        $this->assertSame([1], AdditionalCandidateQuery::baseBuilder()->pluck('r.id')->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function releaseRow(
        int $id,
        string $leftguid,
        int $categoriesId = 1,
        ?\DateTimeInterface $claimedAt = null,
        string $postdate = '2026-07-12 00:00:00',
        int $passwordStatus = -1,
        int $hasPreview = -1,
    ): array {
        return [
            'id' => $id,
            'guid' => $leftguid.'-guid-'.$id,
            'leftguid' => $leftguid,
            'passwordstatus' => $passwordStatus,
            'haspreview' => $hasPreview,
            'nzbstatus' => 1,
            'categories_id' => $categoriesId,
            'size' => 2 * 1048576,
            'postdate' => $postdate,
            'additional_pp_claimed_at' => $claimedAt?->format('Y-m-d H:i:s'),
            'additional_pp_claim_token' => $claimedAt === null ? null : 'claimed',
        ];
    }

    private function createSchema(): void
    {
        if (! Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table): void {
                $table->string('name')->primary();
                $table->text('value')->nullable();
            });
        }

        DB::table('settings')->upsert([
            ['name' => 'categorizeforeign', 'value' => '0'],
            ['name' => 'catwebdl', 'value' => '0'],
            ['name' => 'releaseprocessingtimeout', 'value' => '120'],
        ], ['name'], ['value']);

        Schema::dropIfExists('releases');
        Schema::dropIfExists('releases_groups');
        Schema::dropIfExists('categories');

        Schema::create('categories', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
        });

        // The candidate queries partition the pending set by audio routing, which
        // reaches into usenet_groups for the forced-root override.
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('name')->default('');
            $table->unsignedInteger('forced_root_categories_id')->nullable();
        });

        Schema::create('releases_groups', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id');
            $table->unsignedInteger('groups_id');
        });

        Schema::create('releases', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('guid');
            $table->char('leftguid', 1);
            $table->integer('passwordstatus');
            $table->integer('haspreview');
            $table->integer('nzbstatus');
            $table->unsignedInteger('categories_id');
            $table->unsignedInteger('groups_id')->default(0);
            $table->unsignedBigInteger('size');
            $table->dateTime('postdate')->nullable();
            $table->timestamp('additional_pp_claimed_at')->nullable();
            $table->string('additional_pp_claim_token', 64)->nullable();
        });
    }
}
