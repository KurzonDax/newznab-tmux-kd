<?php

declare(strict_types=1);

namespace App\Services\Releases;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/** The scope lock precedes release/collection locks and remains held through each small mutation. */
final readonly class CollectionSweepLease
{
    public function __construct(public string $scope, public string $token) {}

    /**
     * Lease the scope when no live lease holds it and $claim accepts its cursor row. $claim may
     * adjust the row and returns the cursor values to write with the lease, or null to leave the
     * scope untouched.
     *
     * @param  callable(object): (array<string, mixed>|null)  $claim
     */
    public static function acquire(string $scope, callable $claim): ?self
    {
        $token = (string) Str::uuid();

        return DB::transaction(static function () use ($scope, $token, $claim): ?self {
            DB::table('collection_sweep_cursors')->insertOrIgnore(['scope' => $scope, 'created_at' => now(), 'updated_at' => now()]);
            $row = DB::table('collection_sweep_cursors')->where('scope', $scope)->lockForUpdate()->first();
            if ($row->lease_token !== null && $row->lease_expires_at !== null
                && CarbonImmutable::parse($row->lease_expires_at, config('app.timezone'))->greaterThan(now())) {
                return null;
            }
            $values = $claim($row);
            if ($values === null) {
                return null;
            }
            DB::table('collection_sweep_cursors')->where('scope', $scope)->update([...$values,
                'lease_token' => $token, 'lease_expires_at' => now()->addSeconds(60), 'updated_at' => now()]);

            return new self($scope, $token);
        }, 3);
    }

    public function assertOwned(): object
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A sweep fence requires a transaction.');
        }
        $row = DB::table('collection_sweep_cursors')->where('scope', $this->scope)->lockForUpdate()->first();
        if ($row === null || $row->lease_token !== $this->token || $row->lease_expires_at === null
            || CarbonImmutable::parse($row->lease_expires_at, config('app.timezone'))->lessThanOrEqualTo(now())) {
            throw new CollectionSweepLeaseLost;
        }

        return $row;
    }

    public function renew(): void
    {
        DB::transaction(function (): void {
            $row = $this->assertOwned();
            if (CarbonImmutable::parse($row->lease_expires_at, config('app.timezone'))->lessThanOrEqualTo(now()->addSeconds(30))) {
                DB::table('collection_sweep_cursors')->where('scope', $this->scope)->where('lease_token', $this->token)
                    ->update(['lease_expires_at' => now()->addSeconds(60), 'updated_at' => now()]);
            }
        }, 3);
    }

    public function advance(int $lastId, bool $finished): void
    {
        $this->record($finished ? ['last_id' => 0, 'high_water_id' => 0] : ['last_id' => $lastId]);
    }

    /** @param array<string, mixed> $values */
    public function record(array $values): void
    {
        DB::transaction(function () use ($values): void {
            $this->assertOwned();
            DB::table('collection_sweep_cursors')->where('scope', $this->scope)->where('lease_token', $this->token)
                ->update([...$values, 'updated_at' => now()]);
        }, 3);
    }

    public function release(): void
    {
        DB::table('collection_sweep_cursors')->where('scope', $this->scope)->where('lease_token', $this->token)
            ->update(['lease_token' => null, 'lease_expires_at' => null, 'updated_at' => now()]);
    }
}
