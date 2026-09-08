<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\DB;

final class RecoveryConnection
{
    public static function open(): Connection
    {
        $config = DB::connection()->getConfig();
        $config['name'] = 'obfuscation_recovery_capture';
        if ($config['driver'] === 'sqlite') {
            $config['transaction_mode'] = 'IMMEDIATE';
        }
        $connection = DB::build($config);
        if (! $connection instanceof Connection) {
            throw new \RuntimeException('unsupported_recovery_connection');
        }
        if ($connection->getDriverName() === 'sqlite') {
            $connection->statement('PRAGMA busy_timeout=250');
        } else {
            $connection->statement('SET SESSION innodb_lock_wait_timeout=1');
            if ($connection instanceof MySqlConnection && $connection->isMaria()) {
                $connection->statement('SET SESSION max_statement_time=1');
            } else {
                $connection->statement('SET SESSION max_execution_time=1000');
            }
        }

        return $connection;
    }

    public static function close(?Connection $connection): void
    {
        if ($connection === null) {
            return;
        }
        try {
            $pdo = $connection->getRawPdo();
            if ($pdo instanceof \PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } finally {
            $connection->disconnect();
        }
    }
}
