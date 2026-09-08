<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use PDO;

final class RecoveryArtifactJournal
{
    private readonly PDO $database;

    public function __construct(string $root)
    {
        $this->database = new PDO('sqlite:'.$root.'/.journal.sqlite', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        chmod($root.'/.journal.sqlite', 0600);
        $this->database->exec('PRAGMA busy_timeout=1000');
        $this->database->exec('PRAGMA synchronous=FULL');
        $this->database->exec('CREATE TABLE IF NOT EXISTS objects (name TEXT PRIMARY KEY, checked_at INTEGER NOT NULL) WITHOUT ROWID');
        $this->database->exec('CREATE INDEX IF NOT EXISTS objects_due ON objects (checked_at, name)');
        $this->database->exec('CREATE TABLE IF NOT EXISTS blocks (artifact TEXT NOT NULL, offset INTEGER NOT NULL, digest TEXT NOT NULL, PRIMARY KEY(artifact, offset)) WITHOUT ROWID');
    }

    public function retain(string $name): void
    {
        $this->database->prepare('INSERT INTO objects(name, checked_at) VALUES (?, ?) ON CONFLICT(name) DO UPDATE SET checked_at=excluded.checked_at')
            ->execute([$name, now()->getTimestamp()]);
    }

    public function forget(string $name): void
    {
        $this->database->prepare('DELETE FROM blocks WHERE artifact=?')->execute([$name]);
        $this->database->prepare('DELETE FROM objects WHERE name=?')->execute([$name]);
    }

    /** @param list<string> $digests */
    public function blocks(string $artifact, array $digests): void
    {
        $this->database->beginTransaction();
        try {
            $insert = $this->database->prepare('INSERT OR IGNORE INTO blocks(artifact, offset, digest) VALUES (?, ?, ?)');
            foreach ($digests as $index => $digest) {
                $insert->execute([$artifact, $index * 65536, $digest]);
            }
            $this->database->commit();
        } catch (\Throwable $error) {
            $this->database->rollBack();
            throw $error;
        }
    }

    public function block(string $artifact, int $offset): ?string
    {
        $query = $this->database->prepare('SELECT digest FROM blocks WHERE artifact=? AND offset=?');
        $query->execute([$artifact, $offset]);
        $digest = $query->fetchColumn();

        return $digest === false ? null : $digest;
    }

    public function expired(string $name): bool
    {
        $query = $this->database->prepare('SELECT 1 FROM objects WHERE name=? AND checked_at<=?');
        $query->execute([$name, now()->subDays(RecoveryCompaction::DETAIL_DAYS)->getTimestamp()]);

        return $query->fetchColumn() !== false;
    }

    /** @return list<string> */
    public function due(int $limit): array
    {
        $query = $this->database->prepare('SELECT name FROM objects WHERE checked_at<=? ORDER BY checked_at, name LIMIT ?');
        $query->bindValue(1, now()->subDays(RecoveryCompaction::DETAIL_DAYS)->getTimestamp(), PDO::PARAM_INT);
        $query->bindValue(2, $limit, PDO::PARAM_INT);
        $query->execute();

        return $query->fetchAll(PDO::FETCH_COLUMN);
    }
}
