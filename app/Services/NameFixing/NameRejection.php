<?php

declare(strict_types=1);

namespace App\Services\NameFixing;

/** Why {@see NamePolicy} refused a rename candidate. */
enum NameRejection: string
{
    case RecoveryRefused = 'recovery_refused';
    case AlreadyRenamed = 'already_renamed';
    case Promotional = 'promotional';
    case DescriptiveMediaTitle = 'descriptive_media_title';
    case EpisodeOfLargerRelease = 'episode_of_larger_release';
    case Implausible = 'implausible';
    case LessInformative = 'less_informative';
    case SameName = 'same_name';
}
