<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\ReleaseBrowserState;
use App\Enums\BrowseRoot;
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
        $sort = $this->state->sort;
        if ($sort === 'title') {
            return [$alias.'.title', 'asc'];
        }
        if ($sort === 'year' && in_array($this->state->root, [BrowseRoot::Movies, BrowseRoot::Audio], true)) {
            return [$alias.'.year', 'desc'];
        }
        if ($sort === 'rating' && $this->state->root === BrowseRoot::Movies) {
            return ['CAST('.$alias.'.rating AS DECIMAL(4,2))', 'desc'];
        }
        if ($sort === 'artist' && $this->state->root === BrowseRoot::Audio) {
            return [$alias.'.artist', 'asc'];
        }
        if ($sort === 'grabs' && $this->state->root === BrowseRoot::Movies) {
            return ['SUM(COALESCE(recent_grabs.grabs, 0))', 'desc'];
        }

        return ['MAX(r.adddate)', 'desc'];
    }

    public function applyTo(Builder $query): void
    {
        $query->whereIn('r.id', (clone $this->releases)->select('r.id'));
    }

    public function isTrending(): bool
    {
        return $this->state->view === 'covers' && $this->state->sort === 'grabs'
            && in_array($this->state->root, [BrowseRoot::Movies, BrowseRoot::Tv], true);
    }

    public static function recentGrabs(): Builder
    {
        return DB::table('user_downloads')->select('releases_id')->selectRaw('COUNT(*) AS grabs')
            ->where('timestamp', '>=', now()->subDays(7))->groupBy('releases_id');
    }
}
