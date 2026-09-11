<?php

declare(strict_types=1);

namespace Tests\Support\Reconciliation;

use App\Services\CollectionReconciliation\CollectionAdmission;
use App\Services\CollectionReconciliation\PopulationQuery;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;
use PDO;

/** Streaming counters: large runs never retain query logs or candidate payloads. */
final class AdmissionTelemetry
{
    private readonly Connection $connection;

    private bool $active = true;

    private int $queries = 0;

    private float $databaseSeconds = 0;

    private float $admissionSeconds = 0;

    private float $schemaSeconds = 0;

    /** @var array<string, float> */
    private array $aggregateSeconds = ['binaries' => 0, 'collections' => 0];

    /** @var array<string, bool> */
    private array $reportedPlans = [];

    private int $populationQueries = 0;

    private int $populationRows = 0;

    private int $populationBytes = 0;

    private int $populationReads = 0;

    private int $commits = 0;

    private float $transactionStart = 0;

    /** @var array<int, int> */
    private array $transactionMilliseconds = [];

    private float $start;

    private int $reads;

    public function __construct(private readonly bool $measureAdmissionReads = false, private readonly bool $explainAggregates = false)
    {
        $this->connection = DB::connection();
        $this->start = microtime(true);
        $this->reads = self::handlerReads();
        memory_reset_peak_usage();
        DB::connection()->getPdo()->setAttribute(PDO::ATTR_STATEMENT_CLASS, [AdmissionTelemetryStatement::class, [$this]]);
        app('events')->listen(QueryExecuted::class, function (QueryExecuted $event): void {
            if ($this->active && $event->connection === $this->connection) {
                $this->queries++;
                $this->databaseSeconds += $event->time / 1000;
                if ($this->isAdmissionQuery($event->sql)) {
                    $this->admissionSeconds += $event->time / 1000;
                } elseif (str_contains($event->sql, 'information_schema')) {
                    $this->schemaSeconds += $event->time / 1000;
                }
                foreach (['binaries', 'collections'] as $table) {
                    if (! str_starts_with($event->sql, 'UPDATE '.$table.' ')) {
                        continue;
                    }
                    $this->aggregateSeconds[$table] += $event->time / 1000;
                    if ($this->explainAggregates && ! isset($this->reportedPlans[$table])) {
                        $this->reportedPlans[$table] = true;
                        $plan = $this->connection->select('EXPLAIN '.$event->sql, $event->bindings);
                        fwrite(STDERR, 'ADMISSION_AGGREGATE_PLAN='.json_encode(['table' => $table, 'plan' => $plan], JSON_THROW_ON_ERROR).PHP_EOL);
                    }
                }
            }
        });
        app('events')->listen(TransactionBeginning::class, function ($event): void {
            if ($this->active && $event->connection === $this->connection && $event->connection->transactionLevel() === 1) {
                $this->transactionStart = microtime(true);
            }
        });
        app('events')->listen(TransactionCommitted::class, function ($event): void {
            if ($this->active && $event->connection === $this->connection && $event->connection->transactionLevel() === 0) {
                $this->commits++;
                $bucket = (int) ceil((microtime(true) - $this->transactionStart) * 1000);
                $this->transactionMilliseconds[$bucket] = ($this->transactionMilliseconds[$bucket] ?? 0) + 1;
            }
        });
    }

    /** @param array<mixed> $rows */
    public function fetched(string $sql, array $rows, ?int $readsBefore = null): void
    {
        if (! $this->isAdmissionQuery($sql)) {
            return;
        }
        if ($readsBefore !== null) {
            $this->populationReads += self::handlerReads() - $readsBefore;
        }
        $this->populationQueries++;
        $this->populationRows += count($rows);
        foreach ($rows as $row) {
            $this->populationBytes += strlen(serialize($row));
        }
    }

    public function starting(string $sql): ?int
    {
        return $this->measureAdmissionReads && $this->isAdmissionQuery($sql) ? self::handlerReads() : null;
    }

    private function isAdmissionQuery(string $sql): bool
    {
        if (! $this->active || ! str_contains($sql, 'from `collections`')) {
            return false;
        }
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 24) as $frame) {
            if (($frame['class'] ?? '') === PopulationQuery::class
                && $frame['function'] === 'admissionPopulations') {
                return true;
            }
            if (str_contains($sql, '`date` between') && ($frame['class'] ?? '') === CollectionAdmission::class
                && in_array($frame['function'], ['screen', 'lockAndScreen'], true)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, int|float> */
    public function finish(): array
    {
        $this->active = false;
        $seconds = microtime(true) - $this->start;
        ksort($this->transactionMilliseconds);
        $seen = 0;
        $p95 = 0;
        foreach ($this->transactionMilliseconds as $milliseconds => $count) {
            $seen += $count;
            $p95 = $milliseconds / 1000;
            if ($seen >= ceil($this->commits * 0.95)) {
                break;
            }
        }

        return ['seconds' => $seconds, 'queries' => $this->queries, 'admission_queries' => $this->populationQueries,
            'database_seconds' => $this->databaseSeconds, 'admission_database_seconds' => $this->admissionSeconds,
            'schema_database_seconds' => $this->schemaSeconds,
            'binary_aggregate_database_seconds' => $this->aggregateSeconds['binaries'],
            'collection_aggregate_database_seconds' => $this->aggregateSeconds['collections'],
            'admission_rows' => $this->populationRows, 'admission_bytes' => $this->populationBytes,
            'handler_reads' => self::handlerReads() - $this->reads, 'admission_handler_reads' => $this->populationReads, 'commits' => $this->commits,
            'transaction_p95_seconds' => $p95, 'peak_memory_bytes' => memory_get_peak_usage(true)];
    }

    public static function handlerReads(): int
    {
        return array_sum(array_map(static fn ($row): int => (int) $row->Value, DB::select("SHOW SESSION STATUS LIKE 'Handler_read%'")));
    }
}
