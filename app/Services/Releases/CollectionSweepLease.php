<?php

declare(strict_types=1);

namespace App\Services\Releases;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/** The scope lock precedes release/collection locks and remains held through each small mutation. */
final readonly class CollectionSweepLease
{
    public function __construct(public string $scope, public string $token) {}

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
        DB::transaction(function () use ($lastId, $finished): void {
            $this->assertOwned();
            $values = ['last_id' => $finished ? 0 : $lastId, 'updated_at' => now()];
            if ($finished) {
                $values['high_water_id'] = 0;
            }
            DB::table('collection_sweep_cursors')->where('scope', $this->scope)->where('lease_token', $this->token)->update($values);
        }, 3);
    }

    public function release(): void
    {
        DB::table('collection_sweep_cursors')->where('scope', $this->scope)->where('lease_token', $this->token)
            ->update(['lease_token' => null, 'lease_expires_at' => null, 'updated_at' => now()]);
    }
}
