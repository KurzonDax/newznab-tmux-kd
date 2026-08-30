<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\AdditionalProcessing\AdditionalCandidateQuery;
use Dotenv\Dotenv;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

final class AdditionalCandidateQueryMariaDbTest extends TestCase
{
    private string $tablePrefix;

    /**
     * @var array<string, string|false>
     */
    private array $originalEnvironment = [];

    public function createApplication(): Application
    {
        $this->originalEnvironment = [
            'APP_ENV' => getenv('APP_ENV'),
            'DB_URL' => getenv('DB_URL'),
            'DB_CONNECTION' => getenv('DB_CONNECTION'),
            'DB_DATABASE' => getenv('DB_DATABASE'),
        ];

        Dotenv::createMutable(dirname(__DIR__, 2))->safeLoad();

        $this->tablePrefix = 'phase4_'.getmypid().'_'.bin2hex(random_bytes(4)).'_';
        $database = (string) ($_ENV['DB_DATABASE'] ?? 'nntmux');

        $this->setEnvironmentValue('APP_ENV', 'testing');
        $this->setEnvironmentValue('DB_URL', null);
        $this->setEnvironmentValue('DB_CONNECTION', 'mariadb');
        $this->setEnvironmentValue('DB_DATABASE', $database);

        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $app->make('config')->set('database.connections.mariadb.prefix', $this->tablePrefix);
        $app->make('db')->purge('mariadb');

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'mariadb') {
            $this->markTestSkipped('MariaDB integration test.');
        }

        config([
            'nntmux_settings.check_passworded_rars' => true,
            'nntmux_settings.unrar_path' => '/usr/bin/unrar',
        ]);

        $this->createSchema();
    }

    protected function tearDown(): void
    {
        if (isset($this->tablePrefix) && preg_match('/^phase4_\d+_[a-f0-9]{8}_$/', $this->tablePrefix) === 1) {
            DB::statement('DROP TABLE IF EXISTS `'.$this->tableName('releases').'`');
            DB::statement('DROP TABLE IF EXISTS `'.$this->tableName('categories').'`');
            DB::statement('DROP TABLE IF EXISTS `'.$this->tableName('settings').'`');
        }

        DB::disconnect();
        parent::tearDown();

        foreach ($this->originalEnvironment as $key => $value) {
            $this->setEnvironmentValue($key, $value === false ? null : $value);
        }
    }

    #[Test]
    public function current_indexes_bound_examined_rows_for_per_status_claim_and_backlog_plans(): void
    {
        $releasesTable = $this->tableName('releases');

        DB::statement(<<<SQL
            INSERT INTO `{$releasesTable}` (
                id, guid, leftguid, passwordstatus, haspreview, nzbstatus,
                categories_id, size, postdate, additional_pp_claimed_at
            )
            SELECT
                seq,
                CONCAT(SUBSTRING('0123456789abcdef', MOD(seq, 16) + 1, 1), '-guid-', seq),
                SUBSTRING('0123456789abcdef', MOD(seq, 16) + 1, 1),
                IF(MOD(seq, 97) = 0, -1, 0),
                IF(MOD(seq, 97) = 0, -1, 0),
                1,
                1,
                2097152,
                DATE_SUB(NOW(), INTERVAL seq SECOND),
                IF(MOD(seq, 679) = 0, NOW(), NULL)
            FROM seq_1_to_50000
            SQL);

        DB::statement("ANALYZE TABLE `{$releasesTable}`");

        $claimPlans = [];
        foreach ([-1, 0] as $passwordStatus) {
            $claimPlans[] = $this->analyze(<<<SQL
                SELECT r.id, r.postdate
                FROM `{$releasesTable}` r
                WHERE r.passwordstatus = {$passwordStatus}
                  AND r.haspreview = -1
                  AND r.nzbstatus = 1
                  AND r.size > 1048576
                  AND r.size < 107374182400
                  AND r.leftguid = 'a'
                  AND (r.additional_pp_claimed_at IS NULL OR r.additional_pp_claimed_at < DATE_SUB(NOW(), INTERVAL 300 SECOND))
                ORDER BY r.postdate DESC, r.id ASC
                LIMIT 25
                SQL);
        }
        $bucketPlan = $this->analyze(<<<SQL
            SELECT r.leftguid, COUNT(*) AS total_count,
                   SUM(CASE WHEN r.additional_pp_claimed_at IS NULL OR r.additional_pp_claimed_at < DATE_SUB(NOW(), INTERVAL 300 SECOND) THEN 1 ELSE 0 END) AS available_count
            FROM `{$releasesTable}` r
            WHERE r.passwordstatus = -1
              AND r.haspreview = -1
              AND r.nzbstatus = 1
              AND r.size > 1048576
              AND r.size < 107374182400
            GROUP BY r.leftguid
            ORDER BY r.leftguid
            SQL);
        $backlogPlan = $this->analyze(<<<SQL
            SELECT COUNT(*) AS total_count,
                   SUM(CASE WHEN r.additional_pp_claimed_at IS NULL OR r.additional_pp_claimed_at < DATE_SUB(NOW(), INTERVAL 300 SECOND) THEN 1 ELSE 0 END) AS available_count
            FROM `{$releasesTable}` r
            WHERE r.passwordstatus = -1
              AND r.haspreview = -1
              AND r.nzbstatus = 1
              AND r.size > 1048576
              AND r.size < 107374182400
            SQL);

        $this->assertStringContainsString('ix_releases_add_pp_claim_queue', $claimPlans[0]);
        $this->assertStringNotContainsString('filesort', $claimPlans[0]);
        foreach ($claimPlans as $claimPlan) {
            $this->assertLessThan(5_000, $this->examinedRowsFor($claimPlan, 'r'));
        }
        $this->assertLessThanOrEqual(2_000, $this->examinedRowsFor($bucketPlan, 'r'));
        $this->assertLessThanOrEqual(2_000, $this->examinedRowsFor($backlogPlan, 'r'));
    }

    #[Test]
    public function claims_exclude_fresh_rows_recover_stale_rows_and_preserve_ordering(): void
    {
        DB::table('releases')->insert([
            $this->releaseRow(1, '2026-08-10 12:00:00', now()),
            $this->releaseRow(2, '2026-08-10 11:00:00'),
            $this->releaseRow(3, '2026-08-10 11:00:00'),
            $this->releaseRow(4, '2026-08-10 10:00:00', now()->subSeconds(301)),
        ]);

        $firstClaim = AdditionalCandidateQuery::claimBatch('a', 3, 'worker-one', columns: ['id']);
        $secondClaim = AdditionalCandidateQuery::claimBatch('a', 3, 'worker-two', columns: ['id']);

        $this->assertSame([2, 3, 4], $firstClaim->pluck('id')->all());
        $this->assertSame([], $secondClaim->pluck('id')->all());
        $this->assertSame('worker-one', DB::table('releases')->where('id', 4)->value('additional_pp_claim_token'));
        $this->assertSame('claimed', DB::table('releases')->where('id', 1)->value('additional_pp_claim_token'));
    }

    #[Test]
    public function concurrent_workers_do_not_claim_the_same_hot_bucket_rows(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('The pcntl extension is required for the MariaDB contention test.');
        }

        DB::table('releases')->insert(array_map(
            fn (int $id): array => $this->releaseRow($id, now()->subSeconds($id)->format('Y-m-d H:i:s')),
            range(1, 20),
        ));

        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($sockets === false) {
            throw new RuntimeException('Unable to create an IPC socket pair.');
        }

        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Unable to fork the competing claim worker.');
        }

        if ($pid === 0) {
            fclose($sockets[0]);

            try {
                DB::purge();
                DB::reconnect();
                fwrite($sockets[1], "ready\n");
                fgets($sockets[1]);

                $claimedIds = AdditionalCandidateQuery::claimBatch('a', 5, 'worker-two', columns: ['id'])
                    ->pluck('id')
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->all();

                fwrite($sockets[1], json_encode(['ids' => $claimedIds], JSON_THROW_ON_ERROR));
                fclose($sockets[1]);
                exit(0);
            } catch (\Throwable $exception) {
                fwrite($sockets[1], json_encode(['error' => $exception->getMessage()], JSON_THROW_ON_ERROR));
                fclose($sockets[1]);
                exit(1);
            }
        }

        fclose($sockets[1]);
        $this->assertSame("ready\n", fgets($sockets[0]));
        fwrite($sockets[0], "claim\n");

        $firstWorkerIds = AdditionalCandidateQuery::claimBatch('a', 5, 'worker-one', columns: ['id'])
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $childPayload = stream_get_contents($sockets[0]);
        fclose($sockets[0]);
        pcntl_waitpid($pid, $status);
        DB::purge();
        DB::reconnect();

        /** @var array{ids?: list<int>, error?: string} $childResult */
        $childResult = json_decode($childPayload, true, flags: JSON_THROW_ON_ERROR);

        $this->assertArrayNotHasKey('error', $childResult, $childResult['error'] ?? 'Competing worker failed.');
        $secondWorkerIds = $childResult['ids'] ?? [];
        $this->assertSame([], array_intersect($firstWorkerIds, $secondWorkerIds));
        $this->assertGreaterThanOrEqual(5, count($firstWorkerIds) + count($secondWorkerIds));
        $this->assertLessThanOrEqual(10, count($firstWorkerIds) + count($secondWorkerIds));
        $this->assertSame(
            array_fill(0, count($firstWorkerIds), 'worker-one'),
            DB::table('releases')->whereIn('id', $firstWorkerIds)->orderBy('id')->pluck('additional_pp_claim_token')->all(),
        );
        $this->assertSame(
            array_fill(0, count($secondWorkerIds), 'worker-two'),
            DB::table('releases')->whereIn('id', $secondWorkerIds)->orderBy('id')->pluck('additional_pp_claim_token')->all(),
        );
        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(0, pcntl_wexitstatus($status));
    }

    private function createSchema(): void
    {
        $settingsTable = $this->tableName('settings');
        $categoriesTable = $this->tableName('categories');
        $releasesTable = $this->tableName('releases');

        DB::statement("CREATE TABLE `{$settingsTable}` (`name` VARCHAR(255) PRIMARY KEY, `value` TEXT NULL) ENGINE=InnoDB");
        DB::statement("CREATE TABLE `{$categoriesTable}` (id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB");
        DB::statement(<<<SQL
            CREATE TABLE `{$releasesTable}` (
                id INT UNSIGNED PRIMARY KEY,
                guid VARCHAR(255) NOT NULL,
                leftguid CHAR(1) NOT NULL,
                passwordstatus INT NOT NULL,
                haspreview INT NOT NULL,
                nzbstatus INT NOT NULL,
                categories_id INT UNSIGNED NOT NULL,
                size BIGINT UNSIGNED NOT NULL,
                postdate DATETIME NULL,
                additional_pp_claimed_at TIMESTAMP NULL,
                additional_pp_claim_token VARCHAR(64) NULL,
                KEY ix_releases_haspreview_passwordstatus (haspreview, passwordstatus),
                KEY ix_releases_add_pp_claim_queue (passwordstatus, haspreview, nzbstatus, leftguid, postdate DESC, id, additional_pp_claimed_at)
            ) ENGINE=InnoDB
            SQL);
        DB::table('settings')->insert([
            ['name' => 'categorizeforeign', 'value' => '0'],
            ['name' => 'catwebdl', 'value' => '0'],
            ['name' => 'releaseprocessingtimeout', 'value' => '120'],
        ]);
        DB::table('categories')->insert(['id' => 1]);
    }

    /** @return array<string, mixed> */
    private function releaseRow(int $id, string $postdate, ?\DateTimeInterface $claimedAt = null): array
    {
        return [
            'id' => $id,
            'guid' => 'a-guid-'.$id,
            'leftguid' => 'a',
            'passwordstatus' => -1,
            'haspreview' => -1,
            'nzbstatus' => 1,
            'categories_id' => 1,
            'size' => 2 * 1048576,
            'postdate' => $postdate,
            'additional_pp_claimed_at' => $claimedAt?->format('Y-m-d H:i:s'),
            'additional_pp_claim_token' => $claimedAt === null ? null : 'claimed',
        ];
    }

    private function analyze(string $sql): string
    {
        $row = DB::selectOne('ANALYZE FORMAT=JSON '.$sql);
        $values = (array) $row;

        return (string) reset($values);
    }

    private function examinedRowsFor(string $plan, string $tableName): float
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($plan, true, flags: JSON_THROW_ON_ERROR);
        $examinedRows = $this->findExaminedRows($decoded, $tableName);

        if ($examinedRows === null) {
            throw new RuntimeException("Unable to find runtime row count for table [{$tableName}].");
        }

        return $examinedRows;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function findExaminedRows(array $node, string $tableName): ?float
    {
        if (($node['table_name'] ?? null) === $tableName && isset($node['r_rows'])) {
            return (float) $node['r_rows'];
        }

        foreach ($node as $value) {
            if (! is_array($value)) {
                continue;
            }

            $rows = $this->findExaminedRows($value, $tableName);
            if ($rows !== null) {
                return $rows;
            }
        }

        return null;
    }

    private function tableName(string $table): string
    {
        return $this->tablePrefix.$table;
    }

    private function setEnvironmentValue(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            return;
        }

        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
