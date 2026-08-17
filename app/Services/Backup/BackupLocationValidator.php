<?php

declare(strict_types=1);

namespace App\Services\Backup;

use InvalidArgumentException;

class BackupLocationValidator
{
    public function validate(string $path): string
    {
        if (! str_starts_with($path, DIRECTORY_SEPARATOR)) {
            throw new InvalidArgumentException('Backup location must be an absolute path.');
        }

        $resolved = realpath($path);
        if ($resolved === false || ! is_dir($resolved)) {
            throw new InvalidArgumentException('Backup location must be an existing directory.');
        }

        if (! is_writable($resolved)) {
            throw new InvalidArgumentException('Backup location must be writable.');
        }

        $public = realpath(public_path());
        if ($public !== false && ($resolved === $public || str_starts_with($resolved, $public.DIRECTORY_SEPARATOR))) {
            throw new InvalidArgumentException('Backup location must not be inside the public web root.');
        }

        return $resolved;
    }
}
