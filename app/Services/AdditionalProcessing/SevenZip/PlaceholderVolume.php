<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\SevenZip;

/**
 * A volume of an obfuscated split set whose file is not known: only its
 * declared size. Nothing is fetched for it, and a read inside it fails.
 */
final readonly class PlaceholderVolume
{
    public function __construct(public int $size) {}
}
