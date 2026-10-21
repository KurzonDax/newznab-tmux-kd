<?php

declare(strict_types=1);

namespace App\Services\NameFixing;

/** Why {@see ReleaseNameWriter} did not write an accepted rename. */
enum NameWriteRefusal: string
{
    /** The obfuscation-recovery identity of the release does not allow renaming it. */
    case RecoveryIdentity = 'recovery_identity';

    /** The release is a reconciled bundle of independent videos, which no single title names. */
    case Bundle = 'bundle';
}
