<?php

declare(strict_types=1);

namespace App\Services\Releases;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;

/** Request-local SQL membership; only release descriptors cross PHP in bounded batches. */
final class TvBrowseMembershipTable
{
    private function __construct(private readonly Connection $connection, private readonly string $declarations, private readonly string $memberships) {}

    /**
     * The transaction pins reads to the writer and prevents Laravel retrying on a lost
     * connection. Cleanup uses the original PDO even if the request fails.
     *
     * @template T
     *
     * @param  Closure(self): T  $read
     * @return T
     */
    public static function read(Builder $releases, Closure $read): mixed
    {
        /** @var Connection $connection */
        $connection = $releases->getConnection();

        return $connection->transaction(static function () use ($connection, $releases, $read): mixed {
            $suffix = bin2hex(random_bytes(8));
            $table = new self($connection, 'tv_declarations_'.$suffix, 'tv_memberships_'.$suffix);
            $pdo = $connection->getPdo();
            $grammar = $connection->getQueryGrammar();
            $declarations = $grammar->wrapTable($table->declarations);
            $memberships = $grammar->wrapTable($table->memberships);
            $engine = $connection->getDriverName() === 'sqlite' ? '' : ' ENGINE=InnoDB';
            $temporary = $engine === '' ? '' : 'TEMPORARY ';
            $sortNameType = $engine === '' ? 'TEXT COLLATE BINARY' : 'VARBINARY(1020)';
            try {
                $pdo->exec("CREATE TEMPORARY TABLE {$declarations} (release_id BIGINT NOT NULL, show_id BIGINT NOT NULL, season INTEGER NULL, number INTEGER NULL, linked BIGINT NOT NULL, full_season INTEGER NOT NULL, sort_name {$sortNameType} NOT NULL){$engine}");
                $pdo->exec("CREATE TEMPORARY TABLE {$memberships} (release_id BIGINT NOT NULL, episode_id BIGINT NOT NULL, season INTEGER NOT NULL, full_season INTEGER NOT NULL, sort_name {$sortNameType} NOT NULL, PRIMARY KEY (episode_id, release_id)){$engine}");
                $table->populate($releases);

                return $read($table);
            } finally {
                $pdo->exec("DROP {$temporary}TABLE IF EXISTS {$memberships}");
                $pdo->exec("DROP {$temporary}TABLE IF EXISTS {$declarations}");
            }
        });
    }

    public function members(): Builder
    {
        return $this->connection->table($this->memberships.' as membership')->useWritePdo();
    }

    public function packs(): Builder
    {
        return $this->connection->table($this->declarations)->useWritePdo()->where('full_season', 1);
    }

    private function populate(Builder $releases): void
    {
        $resolver = new TvReleaseMembership;
        (clone $releases)->useWritePdo()->reorder()->select(['r.id', 'r.videos_id', 'r.tv_episodes_id', 'r.searchname', 'r.display_name'])
            ->chunkById(500, function (Collection $releases) use ($resolver): void {
                $batch = [];
                foreach ($releases as $release) {
                    $descriptor = $resolver->describe($release);
                    $numbers = $descriptor['numbers'] ?? [null];
                    if ($descriptor['linked'] > 0) {
                        $numbers = [null];
                    }
                    foreach ($numbers as $number) {
                        $batch[] = ['release_id' => $release->id, 'show_id' => $release->videos_id,
                            'season' => $descriptor['season'], 'number' => $number, 'linked' => $descriptor['linked'],
                            'full_season' => (int) $descriptor['fullSeason'], 'sort_name' => strtolower(release_display_name($release))];
                        if (count($batch) === 500) {
                            $this->connection->table($this->declarations)->insert($batch);
                            $batch = [];
                        }
                    }
                }
                if ($batch !== []) {
                    $this->connection->table($this->declarations)->insert($batch);
                }
            }, 'r.id', 'id');

        $explicit = $this->connection->table($this->declarations.' as d')->join('tv_episodes as e', function (JoinClause $join): void {
            $join->on('e.videos_id', '=', 'd.show_id')->on('e.series', '=', 'd.season')
                ->where(function (Builder $numbers): void {
                    $numbers->whereColumn('e.episode', 'd.number')->orWhere('d.full_season', 1);
                });
        })->where('e.episode', '>', 0)->selectRaw('d.release_id, MIN(e.id), e.series, MAX(d.full_season), MIN(d.sort_name)')
            ->groupBy('d.release_id', 'e.videos_id', 'e.series', 'e.episode');
        $this->connection->table($this->memberships)->insertUsing(['release_id', 'episode_id', 'season', 'full_season', 'sort_name'], $explicit);

        $linked = $this->connection->table($this->declarations.' as d')->join('tv_episodes as linked', function (JoinClause $join): void {
            $join->on('linked.id', '=', 'd.linked')->on('linked.videos_id', '=', 'd.show_id');
        })->join('tv_episodes as e', function (JoinClause $join): void {
            $join->on('e.videos_id', '=', 'linked.videos_id')->on('e.series', '=', 'linked.series')->on('e.episode', '=', 'linked.episode');
        })->where('e.episode', '>', 0)->whereNull('d.season')->selectRaw('d.release_id, MIN(e.id), e.series, 0, MIN(d.sort_name)')
            ->groupBy('d.release_id', 'e.videos_id', 'e.series', 'e.episode');
        $this->connection->table($this->memberships)->insertUsing(['release_id', 'episode_id', 'season', 'full_season', 'sort_name'], $linked);
    }
}
