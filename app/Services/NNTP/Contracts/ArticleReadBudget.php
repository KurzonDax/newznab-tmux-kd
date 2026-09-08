<?php

declare(strict_types=1);

namespace App\Services\NNTP\Contracts;

interface ArticleReadBudget
{
    public function reserve(int $bytes): bool;

    public function settle(int $reserved, int $actual): void;
}
