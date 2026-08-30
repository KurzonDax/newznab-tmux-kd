<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\ClipHardware;

final readonly class ClipHardwareCommandArguments
{
    /**
     * @param  list<string>  $inputArguments
     * @param  list<string>  $outputArguments
     */
    public function __construct(
        public array $inputArguments,
        public array $outputArguments,
    ) {}
}
