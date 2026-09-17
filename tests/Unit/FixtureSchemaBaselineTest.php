<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestBuilder;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\TestSuite;
use PHPUnit\Util\Test as PHPUnitTest;
use ReflectionClass;
use Tests\Support\FixtureSchemaBaseline;

final class FixtureSchemaBaselineTest extends TestCase
{
    public function test_only_listed_violations_are_tolerated_and_resolved_entries_fail(): void
    {
        $entry = ['class' => 'ExampleTest', 'table' => 'video_data', 'rule' => 'R1', 'subject' => 'id', 'tests' => ['test_video']];
        $baseline = new FixtureSchemaBaseline([$entry]);
        $violation = ['table' => 'video_data', 'rule' => 'R1', 'subject' => 'id', 'authority' => 'columns (releases_id); keys (releases_id)', 'connection' => 'testing'];
        $result = $baseline->check('ExampleTest', 'test_video', [$violation]);
        self::assertSame([], $result['errors']);
        self::assertStringContainsString('TOLERATED', $result['reports'][0]);
        self::assertStringContainsString('ExampleTest::test_video connection=testing table=video_data R1 id', $result['reports'][0]);
        self::assertStringContainsString('keys (releases_id)', $result['reports'][0]);
        self::assertStringContainsString('delete the baseline entry', $baseline->check('ExampleTest', 'test_video', [])['errors'][0]);
        self::assertSame([], $baseline->check('ExampleTest', 'test_unrelated', [])['errors']);
        self::assertNotEmpty($baseline->check('AnotherTest', 'test_video', [$violation])['errors']);
        self::assertNotEmpty($baseline->check('ExampleTest', 'test_new_case', [$violation])['errors']);
    }

    public function test_committed_baseline_contains_only_discoverable_test_cases(): void
    {
        $root = dirname(__DIR__, 2);
        $entries = json_decode(file_get_contents($root.'/tests/schema-fixture-baseline.json'), true, flags: JSON_THROW_ON_ERROR);
        if ($entries === []) {
            self::assertSame([], $entries);

            return;
        }
        $known = [];
        $seen = [];
        foreach ($entries as $entry) {
            self::assertTrue(class_exists($entry['class']), 'Delete baseline entry for removed class: '.$entry['class']);
            $key = $entry['class'].' '.FixtureSchemaBaseline::key($entry);
            self::assertArrayNotHasKey($key, $seen, 'Duplicate baseline entry: '.$key);
            $seen[$key] = true;
            self::assertNotEmpty($entry['tests'], 'Delete empty baseline entry: '.$key);
            foreach ($entry['tests'] as $test) {
                $method = explode(' with data set ', $test, 2)[0];
                $methodId = $entry['class'].'::'.$method;
                if (! isset($known[$methodId])) {
                    self::assertTrue(method_exists($entry['class'], $method), 'Delete baseline entry for removed method: '.$methodId);
                    // Use PHPUnit's own discovery, preserving even NUL-containing data-set
                    // names that its XML listing cannot represent without truncation.
                    $class = new ReflectionClass($entry['class']);
                    self::assertTrue(PHPUnitTest::isTestMethod($class->getMethod($method)), 'Method is no longer a discoverable test; delete baseline entry: '.$methodId);
                    $built = (new TestBuilder)->build($class, $method);
                    $cases = $built instanceof TestSuite ? $built->tests() : [$built];
                    $known[$methodId] = [];
                    foreach ($cases as $case) {
                        $known[$methodId][$case->nameWithDataSet()] = true;
                    }
                }
                self::assertArrayHasKey($test, $known[$methodId], 'Test case no longer exists; delete baseline case: '.$key.' '.$test);
            }
        }
    }
}
