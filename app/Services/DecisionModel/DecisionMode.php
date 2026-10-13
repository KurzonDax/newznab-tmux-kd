<?php

declare(strict_types=1);

namespace App\Services\DecisionModel;

/**
 * How far a use case lets the model's answer reach.
 */
enum DecisionMode: string
{
    /** The model is not asked. */
    case Off = 'off';

    /** The model is asked and its answer logged; the application's own answer stands. */
    case Shadow = 'shadow';

    /** The model is asked, its answer logged, and an accepted answer replaces the application's own. */
    case Apply = 'apply';

    /**
     * Anything but a known mode is Off, so a blank, missing or mistyped
     * setting never sends a request.
     */
    public static function fromSetting(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom(strtolower(trim($value))) ?? self::Off) : self::Off;
    }
}
