<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\DecisionModel;

use JsonException;
use RuntimeException;

/**
 * The versioned criteria the album question is asked with, read from the
 * criteria file: the version logged with every decision and the instructions
 * sent to the model. The options are the matcher's own candidates, so the file
 * holds nothing else.
 */
final readonly class MusicAlbumCriteria
{
    private function __construct(
        public string $version,
        public string $instructions,
    ) {}

    public static function fromFile(string $path): self
    {
        $contents = is_file($path) ? file_get_contents($path) : false;
        if ($contents === false) {
            throw new RuntimeException("The music album criteria file [{$path}] cannot be read.");
        }

        try {
            $criteria = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("The music album criteria file [{$path}] is not valid JSON.", previous: $e);
        }

        return self::fromArray($criteria);
    }

    public static function fromArray(mixed $criteria): self
    {
        if (! is_array($criteria)
            || ! is_string($criteria['version'] ?? null) || $criteria['version'] === ''
            || ! is_string($criteria['instructions'] ?? null) || $criteria['instructions'] === '') {
            throw new RuntimeException('The music album criteria are missing a version or the instructions.');
        }

        return new self($criteria['version'], $criteria['instructions']);
    }
}
