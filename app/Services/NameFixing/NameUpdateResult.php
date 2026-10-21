<?php

declare(strict_types=1);

namespace App\Services\NameFixing;

/** What one rename call did: whether its candidate renamed the release, or why not. */
final readonly class NameUpdateResult
{
    private function __construct(
        public bool $renamed,
        public ?NameRejection $rejection,
        public string $title,
    ) {}

    public static function renamed(string $title): self
    {
        return new self(true, null, $title);
    }

    public static function declined(?NameRejection $rejection = null): self
    {
        return new self(false, $rejection, '');
    }
}
