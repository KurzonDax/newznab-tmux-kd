<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\RetentionRegistry;
use Tests\Support\SchemaAuthority;

final class RetentionRegistryTest extends TestCase
{
    public function test_a_table_missing_from_the_registry_is_unclassified(): void
    {
        $registry = $this->registry($this->table('kept').$this->table('new_table'), ['core_tables' => ['kept'], 'owners' => []]);

        self::assertSame(['new_table'], $registry->unclassified());
        self::assertSame([], $registry->cascade());
    }

    public function test_a_child_of_a_classified_parent_is_derived_as_cascade(): void
    {
        $registry = $this->registry(
            $this->table('grandchild', '`child` (`id`) ON DELETE CASCADE')
            .$this->table('kept')
            .$this->table('child', '`kept` (`id`) ON DELETE CASCADE'),
            ['core_tables' => ['kept'], 'owners' => []],
        );

        self::assertSame(['child', 'grandchild'], $registry->cascade());
        self::assertSame([], $registry->unclassified());
    }

    public function test_a_set_null_or_restrict_child_is_not_cascade(): void
    {
        $registry = $this->registry(
            $this->table('kept')
            .$this->table('set_null_child', '`kept` (`id`) ON DELETE SET NULL')
            .$this->table('restrict_child', '`kept` (`id`)'),
            ['core_tables' => ['kept'], 'owners' => []],
        );

        self::assertSame([], $registry->cascade());
        self::assertSame(['restrict_child', 'set_null_child'], $registry->unclassified());
    }

    public function test_conflicts_and_unknown_entries_are_reported(): void
    {
        $registry = $this->registry(
            $this->table('both').$this->table('twice').$this->table('owned'),
            ['core_tables' => ['twice', 'both', 'twice', 'gone'], 'owners' => ['both' => self::class, 'owned' => self::class, 'missing' => self::class]],
        );

        self::assertSame(['both', 'twice'], $registry->conflicts());
        self::assertSame(['gone', 'missing'], $registry->unknown());
        self::assertSame([], $registry->unclassified());
    }

    /** @param ?string $references the foreign key target of parent_id, e.g. "`kept` (`id`) ON DELETE CASCADE" */
    private function table(string $name, ?string $references = null): string
    {
        $lines = ['  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT', '  `parent_id` bigint(20) unsigned DEFAULT NULL', '  PRIMARY KEY (`id`)'];
        if ($references !== null) {
            $lines[] = "  CONSTRAINT `{$name}_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES {$references}";
        }

        return "CREATE TABLE `{$name}` (\n".implode(",\n", $lines)."\n) ENGINE=InnoDB;\n";
    }

    /** @param array{core_tables: list<string>, owners: array<string, string>} $config */
    private function registry(string $dump, array $config): RetentionRegistry
    {
        $path = tempnam(sys_get_temp_dir(), 'retention-registry-');
        try {
            file_put_contents($path, $dump."\n");

            return new RetentionRegistry(new SchemaAuthority($path), $config);
        } finally {
            unlink($path);
        }
    }
}
