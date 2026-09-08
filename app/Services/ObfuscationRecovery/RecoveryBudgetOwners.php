<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

final class RecoveryBudgetOwners
{
    public function __construct(private readonly RecoveryIdentity $identity) {}

    public function locked(string $owner): string
    {
        $this->mutex();
        $digest = $this->identity->digest(['budget', $owner]);
        $this->ensure($digest);

        return DB::table('obfuscation_recovery_budget_owners')->where('owner_digest', $digest)->value('root_digest');
    }

    /** @param list<string> $owners */
    public function merge(array $owners): void
    {
        if (count($owners) < 1 || count($owners) > 256) {
            throw new InvalidArgumentException('invalid_budget_owner_merge');
        }
        DB::transaction(function () use ($owners): void {
            $this->mutex();
            $digests = [];
            foreach ($owners as $owner) {
                $digest = $this->identity->digest(['budget', $owner]);
                $this->ensure($digest);
                $digests[] = $digest;
            }
            $roots = DB::table('obfuscation_recovery_budget_owners')->whereIn('owner_digest', $digests)
                ->pluck('root_digest')->unique()->sort()->values()->all();
            DB::table('obfuscation_recovery_budget_owners')->whereIn('root_digest', $roots)
                ->update(['root_digest' => $roots[0], 'updated_at' => now()]);
        }, 1);
    }

    /** @return list<string> */
    public function members(string $owner): array
    {
        $digest = $this->identity->digest(['budget', $owner]);
        $root = DB::table('obfuscation_recovery_budget_owners')->where('owner_digest', $digest)->value('root_digest');
        if ($root === null) {
            return [$digest];
        }
        $members = DB::table('obfuscation_recovery_budget_owners')->where('root_digest', $root)->pluck('owner_digest')->all();

        return $members === [] ? [$digest] : $members;
    }

    private function ensure(string $digest): void
    {
        DB::table('obfuscation_recovery_budget_owners')->upsert([
            'owner_digest' => $digest, 'root_digest' => $digest, 'created_at' => now(), 'updated_at' => now(),
        ], ['owner_digest'], ['owner_digest']);
    }

    private function mutex(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('budget_ownership_requires_transaction');
        }
        DB::table('obfuscation_recovery_controls')->upsert([
            'scope' => 'budget-owners', 'fingerprint' => hash('sha256', 'budget-owners'), 'epoch' => (string) Str::uuid(),
            'generation' => 1, 'updated_at' => now(),
        ], ['scope'], ['scope']);
        DB::table('obfuscation_recovery_controls')->where('scope', 'budget-owners')->lockForUpdate()->first();
    }
}
