<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * The posting date of a forward scan position: provider 1's `usenet_groups.last_record_postdate`
 * and each secondary provider's `usenet_group_provider_cursors.last_record_postdate`.
 *
 * It is the newest posting date in the ranges the forward scan has published. A server that
 * lists old articles late puts old dates at the top of a range, so the date only moves forward.
 * Posting dates are sender-supplied, so a published date never moves it past the current time.
 */
final class FrontierPostdate
{
    /**
     * The later of the stored date and the newest published date capped at now. NULL inputs are
     * ignored; a stored date already later than now is kept.
     *
     * @param  list<string|null>  $published  Dates of the ranges one advance publishes
     */
    public static function advance(?string $stored, array $published): ?string
    {
        $timezone = config('app.timezone');
        $newest = null;
        foreach ($published as $date) {
            if ($date === null) {
                continue;
            }
            $candidate = Carbon::parse($date, $timezone);
            if ($newest === null || $candidate->gt($newest)) {
                $newest = $candidate;
            }
        }
        if ($newest === null) {
            return $stored;
        }

        $now = Carbon::now($timezone);
        if ($newest->gt($now)) {
            $newest = $now;
        }
        if ($stored !== null && ! $newest->gt(Carbon::parse($stored, $timezone))) {
            return $stored;
        }

        return $newest->setTimezone($timezone)->format('Y-m-d H:i:s');
    }
}
