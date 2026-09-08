<?php

declare(strict_types=1);

namespace App\Services\NNTP\DTO;

final readonly class BoundedArticleResponse
{
    public function __construct(public ?string $data, public int $bytes, public string $reason = 'ok') {}
}
