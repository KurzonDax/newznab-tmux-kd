<?php

declare(strict_types=1);

namespace App\Services\NameFixing;

/** A check a rename candidate must pass before its name is written. */
enum NameGuard
{
    /** Download-site advertising is never a name. */
    case Promotional;

    /** An episode's title does not name a release holding more than that episode. */
    case Episode;

    /** The title must look like a release title, unless the source skips plausibility. */
    case Plausibility;

    /** A readable current name is not replaced by a poorer one, unless the source skips plausibility. */
    case LessInformative;

    /** A candidate equal to the current name is not a rename. */
    case SameName;

    /** A service instance renames a release once. */
    case OncePerRelease;

    /** @return list<self> */
    public static function all(): array
    {
        return self::cases();
    }
}
