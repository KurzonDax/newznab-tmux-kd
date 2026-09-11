<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\SchemaCapabilities;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

class SchemaCapabilitiesTest extends TestCase
{
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_nested_cycles_cache_metadata_but_recheck_negative_answers_after_exit(): void
    {
        $reads = 0;
        DB::listen(static function (QueryExecuted $query) use (&$reads): void {
            if (str_contains($query->sql, 'sqlite_master')) {
                $reads++;
            }
        });
        SchemaCapabilities::during(function (): void {
            $this->assertFalse(SchemaCapabilities::hasTable('capability_probe'));
            SchemaCapabilities::during(function (): void {
                $this->assertFalse(SchemaCapabilities::hasTable('capability_probe'));
            });
        });
        $this->assertSame(1, $reads);
        Schema::create('capability_probe', static fn ($table) => $table->id());
        SchemaCapabilities::during(function (): void {
            $this->assertTrue(SchemaCapabilities::hasTable('capability_probe'));
        });
    }

    public function test_exception_exit_does_not_leave_a_cached_schema_answer(): void
    {
        try {
            SchemaCapabilities::during(function (): never {
                $this->assertFalse(SchemaCapabilities::hasTable('capability_probe'));
                throw new RuntimeException('cycle interrupted');
            });
        } catch (RuntimeException) {
        }
        Schema::create('capability_probe', static fn ($table) => $table->id());
        $this->assertTrue(SchemaCapabilities::hasTable('capability_probe'));
    }

    public function test_connection_replacement_and_prefix_changes_do_not_reuse_cached_capabilities(): void
    {
        SchemaCapabilities::during(function (): void {
            Schema::create('capability_probe', static fn ($table) => $table->id());
            $this->assertTrue(SchemaCapabilities::hasTable('capability_probe'));
            $name = DB::getDefaultConnection();
            $original = config('database.connections.'.$name);
            try {
                config(['database.connections.'.$name.'.database' => ':memory:']);
                DB::purge($name);
                $this->assertFalse(SchemaCapabilities::hasTable('capability_probe'));
                Schema::create('capability_probe', static fn ($table) => $table->id());
                DB::connection()->setTablePrefix('alternate_');
                $this->assertFalse(SchemaCapabilities::hasTable('capability_probe'));
            } finally {
                config(['database.connections.'.$name => $original]);
                DB::purge($name);
            }
        });
    }
}
