<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Enums\BackupKind;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class BackupTableClassifier
{
    /**
     * @return array{tables: list<string>, tiers: list<string>}
     */
    public function tablesFor(BackupKind $kind, bool $includeWorking): array
    {
        $tables = [];
        $tiers = ['important'];

        if ($kind === BackupKind::Full && $includeWorking) {
            $tiers[] = 'working';
        }

        $schema = Schema::getCurrentSchemaName();

        if ($schema === null || trim($schema) === '') {
            throw new RuntimeException('Unable to determine the database schema for backup.');
        }

        foreach (Schema::getTableListing(schema: [$schema], schemaQualified: false) as $table) {
            $tier = $this->tierFor($table);

            if ($tier === 'throwaway') {
                continue;
            }

            if ($tier === 'working' && ($kind !== BackupKind::Full || ! $includeWorking)) {
                continue;
            }

            $tables[] = $table;
        }

        sort($tables);

        return ['tables' => $tables, 'tiers' => $tiers];
    }

    public function tierFor(string $table): string
    {
        if (in_array($table, config('nntmux-backup.throwaway_tables', []), true)
            || $this->matches($table, config('nntmux-backup.throwaway_patterns', []))) {
            return 'throwaway';
        }

        if (in_array($table, config('nntmux-backup.working_tables', []), true)
            || $this->matches($table, config('nntmux-backup.working_patterns', []))) {
            return 'working';
        }

        return 'important';
    }

    /**
     * @param  list<string>  $patterns
     */
    private function matches(string $table, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $table) === 1) {
                return true;
            }
        }

        return false;
    }
}
