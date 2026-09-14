<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\ReleaseBrowserState;
use App\Enums\BrowseRoot;
use App\Enums\ReleaseSort;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

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
        $this->cacheable = ! $state->watching && ! $state->basketOnly && ! $this->isTrending();
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

    public function isTrending(): bool
    {
        return $this->state->view === 'covers' && $this->state->trending
            && in_array($this->state->root, [BrowseRoot::Movies, BrowseRoot::Tv], true);
    }

    public static function recentGrabs(): Builder
    {
        return DB::table('user_downloads')->select('releases_id')->selectRaw('COUNT(*) AS grabs')
            ->where('timestamp', '>=', now()->subDays(7))->groupBy('releases_id');
    }
}
