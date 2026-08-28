<?php

declare(strict_types=1);

namespace App\Support\Data;

use App\Enums\ImageAssetProfile;

/**
 * One stored output of a single decoded source image.
 *
 * Release imagery is written twice from one decode -- the display thumb and
 * the Full-size copy -- so the bounds and quality that vary between the two
 * travel together rather than as parallel argument lists.
 */
final readonly class ImageRendition
{
    public function __construct(
        public string $basename,
        public ?int $maxWidth,
        public ?int $maxHeight,
        public ?int $quality = null,
    ) {}

    public static function fromProfile(string $basename, ImageAssetProfile $profile): self
    {
        return new self(
            $basename,
            $profile->maxWidth(),
            $profile->maxHeight(),
            $profile->quality(),
        );
    }
}
