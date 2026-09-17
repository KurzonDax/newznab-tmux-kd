<?php

declare(strict_types=1);

namespace Tests\Support;

/** Temporary rollout support. Delete with tests/schema-fixture-baseline.json in #697. */
final class FixtureSchemaBaseline
{
    /** @var array<string, array<string, array<string, array>>> */
    private array $expected = [];

    /** @param list<array{class: string, table: string, rule: string, subject: string, tests: list<string>}> $entries */
    public function __construct(array $entries)
    {
        foreach ($entries as $entry) {
            foreach ($entry['tests'] as $test) {
                $this->expected[$entry['class']][$test][self::key($entry)] = $entry;
            }
        }
    }

    /**
     * Expectations are per observed test case so focused runs can reject stale entries
     * without treating unexecuted methods/data sets as repaired fixtures.
     *
     * @param  list<array{table: string, rule: string, subject: string, authority: string, connection: string}>  $violations
     * @return array{errors: list<string>, reports: list<string>}
     */
    public function check(string $class, string $test, array $violations): array
    {
        $expected = $this->expected[$class][$test] ?? [];
        $actual = [];
        $errors = [];
        $reports = [];
        foreach ($violations as $violation) {
            $key = self::key($violation);
            $actual[$key] = true;
            $message = $class.'::'.$test.' connection='.$violation['connection'].' table='.$violation['table'].' '.$violation['rule'].' '.$violation['subject'].'; authority: '.$violation['authority'];
            if (isset($expected[$key])) {
                $reports[] = 'SCHEMA_TOLERATED '.$message;
            } else {
                $errors[] = $message;
            }
        }
        foreach (array_diff_key($expected, $actual) as $entry) {
            $errors[] = $class.'::'.$test.' table='.$entry['table'].' '.$entry['rule'].' '.$entry['subject'].' no longer occurs; delete the baseline entry for this test case.';
        }

        return ['errors' => $errors, 'reports' => $reports];
    }

    /** @param array{table: string, rule: string, subject: string} $violation */
    public static function key(array $violation): string
    {
        return $violation['table'].' '.$violation['rule'].' '.$violation['subject'];
    }
}
