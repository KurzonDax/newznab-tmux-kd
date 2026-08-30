<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing;

use App\Enums\ClipGenerationDeclineReason;
use Illuminate\Support\Facades\Log;

final class ClipGenerationLog
{
    /**
     * @param  array<string, int|string>  $context
     */
    public static function declined(
        string $releaseGuid,
        ClipGenerationDeclineReason $reason,
        array $context = [],
    ): void {
        Log::debug('Clip generation declined', [
            'release_guid' => $releaseGuid,
            'reason' => $reason->value,
            ...$context,
        ]);
    }
}
