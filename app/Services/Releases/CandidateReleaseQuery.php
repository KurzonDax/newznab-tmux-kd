<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Models\Release;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

final class CandidateReleaseQuery
{
    /** @return Builder<Release> */
    public static function fromCandidateIds(QueryBuilder $seed, string $alias): Builder
    {
        /** @var Connection $connection */
        $connection = $seed->getConnection();
        $query = Release::on($connection->getName());
        if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            $grammar = $query->getQuery()->getGrammar();

            return $query->fromRaw(
                '('.$seed->toSql().') as '.$grammar->wrapTable($alias)
                .' STRAIGHT_JOIN '.$grammar->wrapTable('releases as r')
                .' ON '.$grammar->wrap('r.id').' = '.$grammar->wrap($alias.'.id'),
                $seed->getBindings(),
            );
        }

        return $query->fromSub($seed, $alias)->join('releases as r', 'r.id', '=', $alias.'.id');
    }
}
