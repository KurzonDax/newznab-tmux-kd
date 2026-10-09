<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\Enums;

enum IdentificationStatus: string
{
    case Pending = 'pending';
    case AcceptedEdition = 'accepted_edition';
    case AcceptedReleaseGroup = 'accepted_release_group';
    case AcceptedRecording = 'accepted_recording';
    case NeedsReview = 'needs_review';
    case Unresolved = 'unresolved';
    case Conflicted = 'conflicted';
    case RetryableError = 'retryable_error';

    /** @return list<self> the states that accept an identity (an album or a recording) */
    public static function accepted(): array
    {
        return [self::AcceptedEdition, self::AcceptedReleaseGroup, self::AcceptedRecording];
    }

    /** @return list<string> the values of the states that accept an album (a release group or an edition) */
    public static function albumValues(): array
    {
        return [self::AcceptedReleaseGroup->value, self::AcceptedEdition->value];
    }

    public function acceptsAlbum(): bool
    {
        return $this === self::AcceptedReleaseGroup || $this === self::AcceptedEdition;
    }

    public function isTerminal(): bool
    {
        return ! in_array($this, [self::Pending, self::RetryableError], true);
    }
}
