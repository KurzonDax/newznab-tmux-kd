<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Classifies every schema dump table against config/nntmux-retention.php: listed as core,
 * listed with an owner, or derived as cascade through an ON DELETE CASCADE chain that ends
 * at a listed table.
 */
final class RetentionRegistry
{
    /** @var list<string> */
    private array $tables;

    /** @var list<string> */
    private array $core;

    /** @var list<string> */
    private array $owned;

    /** @var array<string, list<string>> */
    private array $cascadeParents;

    /** @param array{core_tables: list<string>, owners: array<string, string>} $registry */
    public function __construct(SchemaAuthority $authority, array $registry)
    {
        $this->tables = array_keys($authority->tables());
        $this->cascadeParents = $authority->cascadeParents();
        $this->core = array_values($registry['core_tables']);
        $this->owned = array_map('strval', array_keys($registry['owners']));
    }

    /** @return list<string> Dump tables with no listed class and no cascade chain to one. */
    public function unclassified(): array
    {
        return $this->sorted(array_diff($this->tables, $this->core, $this->owned, $this->cascade()));
    }

    /** @return list<string> Listed tables that are not in the dump. */
    public function unknown(): array
    {
        return $this->sorted(array_diff([...$this->core, ...$this->owned], $this->tables));
    }

    /** @return list<string> Tables listed as both core and owned, or listed twice as core. */
    public function conflicts(): array
    {
        $twice = array_keys(array_filter(array_count_values($this->core), static fn (int $count): bool => $count > 1));

        return $this->sorted([...array_intersect($this->core, $this->owned), ...$twice]);
    }

    /** @return list<string> Unlisted dump tables deleted by the database with a classified parent. */
    public function cascade(): array
    {
        $classified = array_fill_keys([...$this->core, ...$this->owned], true);
        $cascade = [];
        do {
            $added = false;
            foreach ($this->tables as $table) {
                if (isset($classified[$table])) {
                    continue;
                }
                foreach ($this->cascadeParents[$table] ?? [] as $parent) {
                    if (isset($classified[$parent])) {
                        $classified[$table] = true;
                        $cascade[] = $table;
                        $added = true;
                        break;
                    }
                }
            }
        } while ($added);

        return $this->sorted($cascade);
    }

    /**
     * @param  array<array-key, string>  $tables
     * @return list<string>
     */
    private function sorted(array $tables): array
    {
        $tables = array_values(array_unique($tables));
        sort($tables);

        return $tables;
    }
}
