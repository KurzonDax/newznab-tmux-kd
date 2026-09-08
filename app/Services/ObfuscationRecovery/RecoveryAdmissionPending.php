<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

final class RecoveryAdmissionPending extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('recovery_admission_pending');
    }
}
