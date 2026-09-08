<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class RecoveryComponents
{
    /** @return list<object> */
    public function forRun(object $seed): array
    {
        if (! $seed->active) {
            return [];
        }
        if ($seed->profile === RecoveryAlgorithm::Rar->value) {
            return [$seed];
        }
        if ($seed->profile !== RecoveryAlgorithm::Media->value) {
            throw new InvalidArgumentException('invalid_component_profile');
        }
        $first = (int) $seed->start_ms;
        $last = (int) $seed->end_ms;
        for ($pass = 0; $pass <= 256; $pass++) {
            $rows = DB::table('obfuscation_recovery_runs')->where('source_epoch', $seed->source_epoch)->where('groups_id', $seed->groups_id)
                ->where('capture_generation', $seed->capture_generation)->where('profile', $seed->profile)->where('active', true)
                ->whereBetween('start_ms', [max(0, $first - 21630000), $last + 30000])->where('end_ms', '>=', max(0, $first - 30000))
                ->orderBy('start_ms')->orderBy('id')->limit(257)->get();
            if ($rows->count() > 256) {
                throw new InvalidArgumentException('candidate_count_cap');
            }
            if ($rows->isEmpty()) {
                return [];
            }
            $nextFirst = min($first, (int) $rows->min('start_ms'));
            $nextLast = max($last, (int) $rows->max('end_ms'));
            if ($nextLast - $nextFirst > 21600000) {
                throw new InvalidArgumentException('candidate_span_cap');
            }
            if ($nextFirst === $first && $nextLast === $last) {
                return $rows->all();
            }
            $first = $nextFirst;
            $last = $nextLast;
        }

        throw new InvalidArgumentException('candidate_count_cap');
    }
}
