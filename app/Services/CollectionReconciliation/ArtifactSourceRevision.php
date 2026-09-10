<?php

declare(strict_types=1);

namespace App\Services\CollectionReconciliation;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Frozen whole-source identity, including headers that cannot be PostingFiles. */
final class ArtifactSourceRevision
{
    public static function capture(int $id): ?string
    {
        $source = DB::table('collections')->where('id', $id)->first();
        if ($source === null) {
            return null;
        }
        $values = (array) $source;
        unset($values['filecheck'], $values['releases_id'], $values['absorb_attempts']);
        ksort($values);
        $hash = hash_init('sha256');
        hash_update($hash, serialize($values));
        if (Schema::hasTable('binaries')) {
            foreach (DB::table('binaries')->where('collections_id', $id)->orderBy('id')->cursor() as $binary) {
                hash_update($hash, serialize($binary));
            }
        }
        if (Schema::hasTable('parts')) {
            foreach (DB::table('parts as p')->join('binaries as b', 'b.id', '=', 'p.binaries_id')
                ->where('b.collections_id', $id)->orderBy('p.binaries_id')->orderBy('p.partnumber')->select('p.*')->cursor() as $part) {
                hash_update($hash, serialize($part));
            }
        }

        return hash_final($hash);
    }
}
