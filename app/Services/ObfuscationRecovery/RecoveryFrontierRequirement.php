<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;

final class RecoveryFrontierRequirement
{
    /** @param array{first_article:int,last_article:int,first_postdate:string,last_postdate:string,changed_at?:string} $envelope
     * @return array{left:?int,right:?int}
     */
    public function witnesses(Connection $connection, string $scope, array $envelope): array
    {
        $head = RecoveryFrontiers::witnesses($connection, $scope);
        $left = (clone $head)->where('article_number', '<', $envelope['first_article'])
            ->where('postdate', '<=', Carbon::parse($envelope['first_postdate'], 'UTC')->subMinutes(120)->format('Y-m-d H:i:s'))->max('article_number');
        $right = (clone $head)->where('article_number', '>', $envelope['last_article'])
            ->where('postdate', '>=', Carbon::parse($envelope['last_postdate'], 'UTC')->addMinutes(120)->format('Y-m-d H:i:s'))->min('article_number');

        return ['left' => $left === null ? null : (int) $left, 'right' => $right === null ? null : (int) $right];
    }

    public function legacy(Connection $connection, string $scope, int $first, int $last, ?int $left, ?int $right): bool
    {
        foreach ([[$first, $last], [$left, $left], [$right, $right]] as [$start, $end]) {
            if ($start !== null && RecoveryFrontierConflicts::overlapping($connection, $scope, $start, $end, ['unknown', 'ordering'])->exists()) {
                return true;
            }
        }

        return false;
    }

    /** @param array{first_article:int,last_article:int,first_postdate:string,last_postdate:string,changed_at:string} $envelope
     * @return list<array{int,int}>
     */
    public function intervals(Connection $connection, string $scope, int $first, int $last, array $envelope): array
    {
        $witnesses = $this->witnesses($connection, $scope, $envelope);
        $required = [];
        $middle = [max($first, $envelope['first_article']), min($last, $envelope['last_article'])];
        if ($middle[0] <= $middle[1] && ($witnesses['left'] === null || $witnesses['right'] === null
            || $this->legacy($connection, $scope, $middle[0], $middle[1], null, null))) {
            $required[] = $middle;
        }
        foreach ([['left', $first, min($last, $envelope['first_article'] - 1)],
            ['right', max($first, $envelope['last_article'] + 1), $last]] as [$side, $start, $end]) {
            if ($start > $end) {
                continue;
            }
            $witness = $witnesses[$side];
            if ($witness === null) {
                $required[] = [$start, $end];
            } elseif ($witness >= $start && $witness <= $end
                && $this->legacy($connection, $scope, $witness, $witness, null, null)) {
                $required[] = [$witness, $witness];
            }
        }

        return $required;
    }
}
