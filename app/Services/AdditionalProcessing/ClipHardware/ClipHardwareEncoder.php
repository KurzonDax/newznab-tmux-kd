<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\ClipHardware;

interface ClipHardwareEncoder
{
    public function id(): string;

    public function build(string $device): ClipHardwareCommandArguments;
}
