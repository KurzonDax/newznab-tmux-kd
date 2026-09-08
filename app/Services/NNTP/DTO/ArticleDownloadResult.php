<?php

declare(strict_types=1);

namespace App\Services\NNTP\DTO;

use App\Services\DTO\YencArticleMetadata;

final readonly class ArticleDownloadResult
{
    /**
     * @param  list<string>  $crcFailedMessageIds  Distinct articles whose provider copy failed CRC verification.
     */
    public function __construct(
        public mixed $data,
        public array $crcFailedMessageIds = [],
        public bool $damaged = false,
        public ?YencArticleMetadata $metadata = null,
    ) {}

    public function crcFailureCount(): int
    {
        return count($this->crcFailedMessageIds);
    }
}
