<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\ReleaseBrowserState;
use App\Enums\ReleaseSort;
use Illuminate\Database\Query\Builder;

final readonly class CoverBrowseScope
{
    public string $sql;

    /** @var array<int, mixed> */
    public array $bindings;

    public string $cacheKey;

    public bool $cacheable;

    public function __construct(private Builder $releases, private ReleaseBrowserState $state)
    {
        $ids = (clone $releases)->select('r.id');
        $this->sql = ' AND r.id IN ('.$ids->toSql().') ';
        $this->bindings = $ids->getBindings();
        $this->cacheKey = md5($this->sql.serialize($this->bindings));
        $this->cacheable = ! $state->watching && ! $state->basketOnly;
    }

    /** @return array{string, string} */
    public function order(string $alias): array
    {
        if ($this->state->letter !== '') {
            return [$alias.'.title', 'asc'];
        }

        return ReleaseSort::resolve($this->state->sort)->order(grouped: true);
    }

    public function applyTo(Builder $query): void
    {
        $query->whereIn('r.id', (clone $this->releases)->select('r.id'));
    }
}
