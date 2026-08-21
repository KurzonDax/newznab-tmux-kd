<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\Enums;

enum ProcessingOutcome: string
{
    case Completed = 'completed';
    case NoUsefulArtifacts = 'no-useful-artifacts';
    case Passworded = 'passworded';
    case GroupUnavailable = 'group-unavailable';
    case TemporaryWorkspaceUnavailable = 'temporary-workspace-unavailable';
    case TimedOut = 'timed-out';
    case DeletedAfterTimeout = 'deleted-after-timeout';
    case DeletedBrokenNzb = 'deleted-broken-nzb';
    case Discarded = 'discarded';

    /**
     * The audio path probed the release, found video (or no audio at all), and
     * handed it to the shared path. Successful: the probe did its job, and the
     * release stays pending for the worker that should have had it.
     */
    case DeclinedToVideoPath = 'declined-to-video-path';
    case NotFound = 'not-found';
    case Failed = 'failed';

    public function isSuccessful(): bool
    {
        return match ($this) {
            self::Completed, self::NoUsefulArtifacts, self::Passworded, self::DeclinedToVideoPath => true,
            default => false,
        };
    }

    public function isDeleted(): bool
    {
        return match ($this) {
            self::DeletedAfterTimeout, self::DeletedBrokenNzb, self::Discarded => true,
            default => false,
        };
    }
}
