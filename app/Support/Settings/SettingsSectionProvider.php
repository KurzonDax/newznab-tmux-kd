<?php

declare(strict_types=1);

namespace App\Support\Settings;

/**
 * Supplies one page of the hub. Sections are declared one class per page so a page can land
 * without touching the others.
 */
interface SettingsSectionProvider
{
    public static function section(): SettingSection;
}
