<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\Enums;

enum IdentificationStatus: string
{
    case AcceptedEdition = 'accepted_edition';
    case AcceptedReleaseGroup = 'accepted_release_group';
    case AcceptedRecording = 'accepted_recording';
    case NeedsReview = 'needs_review';
    case Unresolved = 'unresolved';
    case Conflicted = 'conflicted';
    case RetryableError = 'retryable_error';
}
