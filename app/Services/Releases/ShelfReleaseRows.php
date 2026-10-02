<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\ReleaseRowData;
use App\Data\ShelfReleaseRow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Loads a page of Books, Console or PC release ids into display rows: ReleaseRowFacts supplies the
 * facts every release list shows; this drops the preview and sample (the lists show no picture,
 * docs/proposals/books-console-pc-redesign/SPEC.md 5.5) and adds the Category cell: the
 * sub-category title, read for the whole page in one query, and the shared row data's
 * "Root > Sub" category as its title.
 */
final class ShelfReleaseRows
{
    public function __construct(private readonly ReleaseRowFacts $facts) {}

    /**
     * @param  list<int>  $ids  in display order
     * @return list<ShelfReleaseRow>
     */
    public function load(array $ids, bool $byAdded): array
    {
        return array_map(static fn (array $row): ShelfReleaseRow => new ShelfReleaseRow(...$row['facts']), $this->rowArguments($ids, $byAdded));
    }

    /**
     * The page's releases with a shelf row's constructor arguments; Console adds its game to them
     * (ConsoleReleaseRows).
     *
     * @param  list<int>  $ids  in display order
     * @param  list<string>  $columns  further `releases` columns the caller reads
     * @return list<array{release: object, facts: array<string, mixed>}>
     */
    public function rowArguments(array $ids, bool $byAdded, array $columns = []): array
    {
        $releases = $this->facts->load($ids, $columns);
        if ($releases === []) {
            return [];
        }
        $now = CarbonImmutable::now(config('app.timezone', 'UTC'));
        $titles = DB::table('categories')->whereIn('id', array_values(array_unique(array_map(static fn (object $release): int => (int) $release->categories_id, $releases))))
            ->pluck('title', 'id');

        return array_map(function (object $release) use ($byAdded, $now, $titles): array {
            /** @var ReleaseRowData $row */
            $row = $release->row_data;

            return ['release' => $release, 'facts' => [...$this->facts->facts($release, $byAdded, $now), 'preview' => null, 'sample' => null,
                'category' => (string) ($titles[(int) $release->categories_id] ?? ''), 'categoryPath' => $row->category]];
        }, $releases);
    }
}
