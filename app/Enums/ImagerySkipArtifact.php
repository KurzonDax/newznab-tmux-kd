<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The imagery artifacts a Free-disk guard skip can suppress for one release
 * (see ADR 0013). Recorded on the Imagery disk skip ledger row so an operator
 * can tell what the squeeze cost before requeueing.
 */
enum ImagerySkipArtifact: string
{
    /** The Extracted Sample Image, stored under covers/sample/. */
    case Sample = 'sample';

    /** The Generated Preview image, stored under covers/preview/. */
    case Preview = 'preview';

    /**
     * Parse a stored ledger value back into artifacts, ignoring tokens written
     * by a newer version of the pipeline.
     *
     * @return list<self>
     */
    public static function fromList(string $suppressed): array
    {
        $artifacts = [];
        foreach (explode(',', $suppressed) as $token) {
            $artifact = self::tryFrom(trim($token));
            if ($artifact !== null && ! in_array($artifact, $artifacts, true)) {
                $artifacts[] = $artifact;
            }
        }

        return $artifacts;
    }

    /**
     * Render artifacts as the canonical stored value: declaration order, comma
     * separated, so one release always produces one comparable string.
     *
     * @param  list<self>  $artifacts
     */
    public static function toList(array $artifacts): string
    {
        $ordered = array_filter(self::cases(), static fn (self $case): bool => in_array($case, $artifacts, true));

        return implode(',', array_map(static fn (self $case): string => $case->value, $ordered));
    }
}
