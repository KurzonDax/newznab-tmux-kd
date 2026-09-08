<?php

declare(strict_types=1);

namespace App\Services\NNTP\Contracts;

use App\Services\NNTP\DTO\BoundedArticleResponse;

/** Message-ID HEAD is an article operation, unlike primary-pinned overview scans. */
interface BoundedProviderClient extends ProviderClient
{
    public function fetchBoundedArticle(string $messageId, bool $head, int $maxBytes, float $deadline): BoundedArticleResponse;
}
