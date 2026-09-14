<?php

declare(strict_types=1);

namespace App\Data;

final readonly class ReleaseRowData
{
    public function __construct(
        public int $id,
        public string $guid,
        public string $name,
        public string $category,
        public string $size,
        public int $files,
        public string $added,
        public string $posted,
        public int $grabs,
        public int $comments,
        public float $completion,
        public ?string $repair_outcome,
        public ?string $rescan_outcome,
        public bool $passworded,
        public bool $has_media_info,
        public ?string $media_info_summary,
        public bool $nfo,
        public string $preview,
        public string $group,
        public string $poster,
        public bool $renamed,
        public bool $pp_done,
        public ?ReleaseEntityData $entity,
        public bool $in_basket,
        public bool $watched,
        public int $reports = 0,
        public int $public_responses = 0,
    ) {}
}
