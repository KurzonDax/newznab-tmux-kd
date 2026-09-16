<?php

declare(strict_types=1);

namespace App\Services\NameFixing;

/** Naming policy only: excluded paths still belong to the archive inventory. */
final class ArchiveNamingPath
{
    public static function eligible(string $path): bool
    {
        $path = str_replace('\\', '/', $path);
        while (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }

        if ($path === '' || str_starts_with($path, '/')
            || preg_match('~^[a-zA-Z]:|[\x00-\x1f\x7f]|(?:^|/)\.\.(?:/|$)~', $path)) {
            return false;
        }

        $components = explode('/', $path);
        array_pop($components);
        foreach ($components as $component) {
            if ($component !== '.' && str_starts_with($component, '.')) {
                return false;
            }
        }

        return ! str_starts_with($path, '.') && ! str_ends_with($path, '/');
    }
}
