<?php

declare(strict_types=1);

namespace App\Data;

final readonly class ReleaseRowData
{
    public function __construct(
        public string $guid,
        public string $name,
        public string $category,
        public int $files,
        public int $grabs,
        public int $comments,
        public bool $passworded,
        public bool $imported,
        public bool $has_media_info,
        public ?string $media_info_summary,
        public bool $nfo,
        public string $group,
        public string $poster,
        public ?ReleaseEntityData $entity,
        public bool $in_basket,
        public bool $watched,
        public int $reports = 0,
        public int $public_responses = 0,
    ) {}
}
