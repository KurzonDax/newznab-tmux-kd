<?php

declare(strict_types=1);

namespace App\Enums;

/** The stored `releases.resolution` value: measured video size, else the name. */
enum ReleaseResolution: int
{
    /** Measured cut-offs; the UHD and HD ones are shared with MediaInfoRefinementService. */
    public const UHD_MIN_WIDTH = 3800;

    public const UHD_MIN_HEIGHT = 2100;

    public const FULL_HD_MIN_WIDTH = 1900;

    public const FULL_HD_MIN_HEIGHT = 1000;

    public const HD_MIN_WIDTH = 1280;

    public const HD_MIN_HEIGHT = 720;

    /** First three characters of a ReleaseQuality::RESOLUTION_PATTERN token. */
    public const NAME_TOKEN_PREFIXES = ['216' => self::Uhd, '108' => self::FullHd, '720' => self::Hd, '576' => self::Sd, '480' => self::Sd];

    case Unknown = 0;
    case Uhd = 1;
    case FullHd = 2;
    case Hd = 3;
    case Sd = 4;

    /** Null when neither dimension was measured. */
    public static function fromMeasured(int $width, int $height): ?self
    {
        return match (true) {
            $width <= 0 && $height <= 0 => null,
            $width >= self::UHD_MIN_WIDTH || $height >= self::UHD_MIN_HEIGHT => self::Uhd,
            $width >= self::FULL_HD_MIN_WIDTH || $height >= self::FULL_HD_MIN_HEIGHT => self::FullHd,
            $width >= self::HD_MIN_WIDTH || $height >= self::HD_MIN_HEIGHT => self::Hd,
            default => self::Sd,
        };
    }

    /** Maps a name token such as `1080p` to a value; anything else is unknown. */
    public static function fromNameToken(string $token): self
    {
        return self::NAME_TOKEN_PREFIXES[substr($token, 0, 3)] ?? self::Unknown;
    }

    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'Unknown',
            self::Uhd => '4K',
            self::FullHd => '1080p',
            self::Hd => '720p',
            self::Sd => 'SD',
        };
    }
}
