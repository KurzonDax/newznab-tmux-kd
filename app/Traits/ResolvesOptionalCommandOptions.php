<?php

declare(strict_types=1);

namespace App\Traits;

/**
 * Reads numeric console options that are allowed to be absent.
 *
 * The distinction these draw is the whole point: for a command whose defaults live in site
 * settings, an option that was not passed must read as `null` ("use the setting"), never as the
 * `0` a bare cast would produce. A zeroed retry window or probe ceiling is a very different run
 * from an unconfigured one.
 */
trait ResolvesOptionalCommandOptions
{
    protected function floatOption(string $name): ?float
    {
        $value = $this->option($name);

        return $value === null || $value === '' ? null : (float) $value;
    }

    protected function intOption(string $name): ?int
    {
        $value = $this->option($name);

        return $value === null || $value === '' ? null : (int) $value;
    }
}
