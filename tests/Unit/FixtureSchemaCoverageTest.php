<?php

declare(strict_types=1);

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tests\TestCase as BaseTestCase;

final class FixtureSchemaCoverageTest extends TestCase
{
    /** Statements and helpers that build tables: raw DDL, the schema builder, the #700 builder, migrations and dumps. */
    private const string BUILDS_TABLES = '/\bcreate\s+(?:temp(?:orary)?\s+)?table\b|\bSchema::(?:connection\([^)]*\)->)?create\(|->getSchemaBuilder\(\)->create\(|\bProductionTables::|->up\(\)|[\'"]migrate(?::\w+)?[\'"]|->unprepared\(/i';

    /** The guard's own tests: they parse SQL text or run it on private handles, never a fixture for application code. */
    private const array GUARD_TESTS = [
        FixtureSchemaCoverageTest::class,
        FixtureSchemaGuardTest::class,
        ProductionTablesTest::class,
        SchemaAuthorityTest::class,
    ];

    public function test_table_building_tests_extend_the_shared_base_test_case(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/tests', FilesystemIterator::SKIP_DOTS)) as $file) {
            $source = $file->getExtension() === 'php' ? (string) file_get_contents($file->getPathname()) : '';
            if (! preg_match('/^namespace\s+([\w\\\\]+);/m', $source, $namespace) || ! preg_match('/^(?:(?:abstract|final|readonly)\s+)*class\s+(\w+)/m', $source, $class)) {
                continue;
            }
            $name = $namespace[1].'\\'.$class[1];
            self::assertTrue(class_exists($name), 'Cannot autoload '.$name.' from '.$file->getPathname());
            if (! is_subclass_of($name, TestCase::class) || is_subclass_of($name, BaseTestCase::class) || in_array($name, self::GUARD_TESTS, true)) {
                continue;
            }
            foreach (self::sources(new ReflectionClass($name)) as $path) {
                if (preg_match(self::BUILDS_TABLES, (string) file_get_contents($path), $match)) {
                    $offenders[] = $name.' ('.substr($path, strlen($root) + 1).': '.$match[0].')';

                    break;
                }
            }
        }

        self::assertSame([], $offenders, 'Tests that build tables must extend Tests\TestCase so the schema guard checks them.');
    }

    /**
     * The test's own file, its parents below PHPUnit and every trait they use under tests/.
     *
     * @param  ReflectionClass<object>  $class
     * @return list<string>
     */
    private static function sources(ReflectionClass $class): array
    {
        $tests = dirname(__DIR__).DIRECTORY_SEPARATOR;
        $paths = [];
        $pending = [$class];
        while ($pending !== []) {
            $current = array_pop($pending);
            if ($current->getName() === TestCase::class || ! str_starts_with((string) $current->getFileName(), $tests)) {
                continue;
            }
            $paths[] = (string) $current->getFileName();
            array_push($pending, ...array_values($current->getTraits()));
            if ($current->getParentClass() !== false) {
                $pending[] = $current->getParentClass();
            }
        }

        return array_values(array_unique($paths));
    }
}
