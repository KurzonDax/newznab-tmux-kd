<?php

declare(strict_types=1);

namespace App\Services\AudioProcessing\Contracts;

use App\Services\AudioProcessing\DTO\AudioProcessingBatchResult;
use App\Services\AudioProcessing\DTO\AudioProcessingResult;

interface AudioProcessingOrchestratorInterface
{
    /**
     * @param  null|callable(AudioProcessingResult): void  $onReleaseSettled
     */
    public function start(
        string $guidChar = '',
        string $workerToken = '',
        string $groupID = '',
        ?callable $onReleaseSettled = null,
    ): AudioProcessingBatchResult;

    public function finish(): void;
}
