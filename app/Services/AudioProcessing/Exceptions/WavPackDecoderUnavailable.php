<?php

declare(strict_types=1);

namespace App\Services\AudioProcessing\Exceptions;

use RuntimeException;

final class WavPackDecoderUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('WavPack file requires wvunpack, which is not installed.');
    }
}
