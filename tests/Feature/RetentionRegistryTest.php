<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\Support\RetentionRegistry;
use Tests\Support\SchemaAuthority;
use Tests\TestCase;

final class RetentionRegistryTest extends TestCase
{
    public function test_every_table_in_the_schema_dump_has_a_retention_class(): void
    {
        $unclassified = $this->registry()->unclassified();

        $this->assertSame([], $unclassified, 'Tables with no retention class: '.implode(', ', $unclassified)
            .'. Add each to config/nntmux-retention.php core_tables or owners, or give it an ON DELETE CASCADE parent.');
    }

    public function test_the_registry_lists_only_existing_tables_once(): void
    {
        $registry = $this->registry();

        $this->assertSame([], $registry->unknown(), 'config/nntmux-retention.php lists tables that are not in the schema dump.');
        $this->assertSame([], $registry->conflicts(), 'config/nntmux-retention.php lists these tables more than once.');
    }

    public function test_every_owner_is_an_existing_class_or_scheduled_task(): void
    {
        $scheduled = array_map(static fn ($event): ?string => $event->description, app(Schedule::class)->events());

        foreach (config('nntmux-retention.owners') as $table => $owner) {
            $exists = str_starts_with($owner, 'schedule:')
                ? in_array(substr($owner, strlen('schedule:')), $scheduled, true)
                : class_exists($owner);

            $this->assertTrue($exists, "Retention owner of {$table} does not exist: {$owner}");
        }
    }

    public function test_seeded_counts_match_the_dump(): void
    {
        $this->assertCount(29, config('nntmux-retention.owners'));
        $this->assertCount(114, config('nntmux-retention.core_tables'));
        $this->assertCount(69, $this->registry()->cascade());
    }

    private function registry(): RetentionRegistry
    {
        return new RetentionRegistry(new SchemaAuthority(base_path('database/schema/mariadb-schema.sql')), config('nntmux-retention'));
    }
}
