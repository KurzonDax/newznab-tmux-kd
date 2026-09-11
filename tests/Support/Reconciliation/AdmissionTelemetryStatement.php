<?php

declare(strict_types=1);

namespace Tests\Support\Reconciliation;

use PDO;
use PDOStatement;

/** Observe returned rows without substituting the real database or executing queries twice. */
final class AdmissionTelemetryStatement extends PDOStatement
{
    private ?int $readsBefore = null;

    protected function __construct(private readonly AdmissionTelemetry $telemetry) {}

    public function execute(?array $params = null): bool
    {
        $this->readsBefore = $this->telemetry->starting($this->queryString);

        return parent::execute($params);
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $rows = parent::fetchAll($mode, ...$args);
        $this->telemetry->fetched($this->queryString, $rows, $this->readsBefore);

        return $rows;
    }
}
