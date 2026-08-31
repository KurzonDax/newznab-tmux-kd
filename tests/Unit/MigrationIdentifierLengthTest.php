<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MigrationIdentifierLengthTest extends TestCase
{
    private const int MAX_IDENTIFIER_LENGTH = 64;

    #[DataProvider('unsafeImplicitIdentifierProvider')]
    public function test_it_detects_unsafe_implicit_migration_identifiers(string $definition, string $expected): void
    {
        $this->assertSame([$expected], self::unsafeImplicitIdentifiers($definition));
    }

    public function test_migrations_do_not_generate_unsafe_implicit_identifiers(): void
    {
        $migrationFiles = glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [];
        $unsafeIdentifiers = [];

        foreach ($migrationFiles as $migrationFile) {
            $source = file_get_contents($migrationFile);
            $this->assertIsString($source, 'Could not read '.$migrationFile);

            foreach (self::unsafeImplicitIdentifiers($source) as $identifier) {
                $unsafeIdentifiers[] = basename($migrationFile).': '.$identifier;
            }
        }

        $this->assertSame(
            [],
            $unsafeIdentifiers,
            "Migration identifiers may not exceed 64 characters:\n".implode("\n", $unsafeIdentifiers),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unsafeImplicitIdentifierProvider(): iterable
    {
        yield 'foreign key' => [
            <<<'PHP'
                Schema::create('release_music_candidate_attempts', function (Blueprint $table) {
                    $table->foreign('release_music_identification_id')->references('id');
                });
                PHP,
            'release_music_candidate_attempts_release_music_identification_id_foreign',
        ];

        yield 'index' => [
            <<<'PHP'
                Schema::create('release_music_candidate_attempts', function (Blueprint $table) {
                    $table->index('release_music_identification_id');
                });
                PHP,
            'release_music_candidate_attempts_release_music_identification_id_index',
        ];

        yield 'unique column modifier' => [
            <<<'PHP'
                Schema::create('release_music_candidate_attempts', function (Blueprint $table) {
                    $table->unsignedBigInteger('release_music_identification_id')->unique();
                });
                PHP,
            'release_music_candidate_attempts_release_music_identification_id_unique',
        ];

        yield 'alternate Blueprint variable' => [
            <<<'PHP'
                Schema::create('release_music_candidate_attempts', static function (Blueprint $blueprint): void {
                    $blueprint->index('release_music_identification_id');
                });
                PHP,
            'release_music_candidate_attempts_release_music_identification_id_index',
        ];

        yield 'dynamic table fails closed' => [
            <<<'PHP'
                Schema::table($tableName, static function (Blueprint $blueprint): void {
                    $blueprint->unique('release_music_identification_id');
                });
                PHP,
            'Cannot verify implicit unique identifier for dynamic table expression $tableName',
        ];

        yield 'mixed literal and dynamic columns fail closed' => [
            <<<'PHP'
                Schema::create('release_music_candidate_attempts', static function (Blueprint $blueprint) use ($dynamicColumn): void {
                    $blueprint->index(['release_music_identification_id', $dynamicColumn]);
                });
                PHP,
            "Cannot verify implicit index identifier for release_music_candidate_attempts with dynamic columns ['release_music_identification_id', \$dynamicColumn]",
        ];
    }

    /**
     * @return list<string>
     */
    private static function unsafeImplicitIdentifiers(string $source): array
    {
        preg_match_all(
            '/Schema::(?:create|table)\(\s*(?<tableExpression>[^,]+?)\s*,\s*(?:static\s+)?function\s*\(\s*(?:Blueprint\s+)?\$(?<blueprint>[A-Za-z_]\w*)/s',
            $source,
            $schemaCalls,
            PREG_OFFSET_CAPTURE,
        );

        $unsafeIdentifiers = [];
        $schemaCallCount = count($schemaCalls[0]);

        for ($index = 0; $index < $schemaCallCount; $index++) {
            $offset = $schemaCalls[0][$index][1];
            $nextOffset = $schemaCalls[0][$index + 1][1] ?? strlen($source);
            $definition = substr($source, $offset, $nextOffset - $offset);
            $tableExpression = trim($schemaCalls['tableExpression'][$index][0]);
            $table = self::literalTableName($tableExpression);
            $blueprint = preg_quote($schemaCalls['blueprint'][$index][0], '/');

            preg_match_all(
                '/\$'.$blueprint.'->(?<type>foreign|index|unique)\(\s*(?<arguments>[^)]*)\)/s',
                $definition,
                $tableConstraints,
                PREG_SET_ORDER,
            );

            foreach ($tableConstraints as $constraint) {
                [$columnsExpression, $name] = self::constraintArguments($constraint['arguments']);

                if (self::hasExplicitName($name)) {
                    continue;
                }

                self::collectImplicitIdentifierIssue(
                    $unsafeIdentifiers,
                    $table,
                    $tableExpression,
                    self::literalColumns($columnsExpression),
                    $columnsExpression,
                    $constraint['type'],
                );
            }

            preg_match_all(
                '/\$'.$blueprint.'->\w+\(\s*([\'\"])(?<column>[^\'\"]+)\1[^;]*?->(?<type>index|unique)\(\s*(?<name>[^)]*)\)/s',
                $definition,
                $columnModifiers,
                PREG_SET_ORDER,
            );

            foreach ($columnModifiers as $modifier) {
                if (self::hasExplicitName($modifier['name'])) {
                    continue;
                }

                self::collectImplicitIdentifierIssue(
                    $unsafeIdentifiers,
                    $table,
                    $tableExpression,
                    [$modifier['column']],
                    "'{$modifier['column']}'",
                    $modifier['type'],
                );
            }
        }

        return array_values(array_unique($unsafeIdentifiers));
    }

    private static function literalTableName(string $expression): ?string
    {
        if (preg_match('/^([\'\"])(?<table>[A-Za-z0-9_.-]+)\1$/', $expression, $match) !== 1) {
            return null;
        }

        return $match['table'];
    }

    /**
     * @return array{string, string}
     */
    private static function constraintArguments(string $arguments): array
    {
        $arguments = trim($arguments);

        if (str_starts_with($arguments, '[')) {
            $closingBracket = strrpos($arguments, ']');

            if ($closingBracket === false) {
                return [$arguments, ''];
            }

            return [
                substr($arguments, 0, $closingBracket + 1),
                ltrim(substr($arguments, $closingBracket + 1), " \t\n\r\0\x0B,"),
            ];
        }

        $parts = explode(',', $arguments, 2);

        return [trim($parts[0]), trim($parts[1] ?? '')];
    }

    /**
     * @return list<string>|null
     */
    private static function literalColumns(string $expression): ?array
    {
        $expression = trim($expression);

        if (preg_match('/^([\'\"])(?<column>[^\'\"]+)\1$/', $expression, $match) === 1) {
            return [$match['column']];
        }

        if (! str_starts_with($expression, '[') || ! str_ends_with($expression, ']')) {
            return null;
        }

        $items = preg_split('/\s*,\s*/', trim(substr($expression, 1, -1)));

        if ($items === false || $items === ['']) {
            return null;
        }

        $columns = [];

        foreach ($items as $item) {
            if (preg_match('/^([\'\"])(?<column>[^\'\"]+)\1$/', trim($item), $match) !== 1) {
                return null;
            }

            $columns[] = $match['column'];
        }

        return $columns;
    }

    private static function hasExplicitName(string $argument): bool
    {
        $argument = ltrim(trim($argument), ',');

        return $argument !== '' && strtolower(trim($argument)) !== 'null';
    }

    /**
     * @param  list<string>  $unsafeIdentifiers
     * @param  list<string>|null  $columns
     */
    private static function collectImplicitIdentifierIssue(
        array &$unsafeIdentifiers,
        ?string $table,
        string $tableExpression,
        ?array $columns,
        string $columnsExpression,
        string $type,
    ): void {
        if ($table === null) {
            $unsafeIdentifiers[] = "Cannot verify implicit {$type} identifier for dynamic table expression {$tableExpression}";

            return;
        }

        if ($columns === null) {
            $unsafeIdentifiers[] = "Cannot verify implicit {$type} identifier for {$table} with dynamic columns {$columnsExpression}";

            return;
        }

        $identifier = strtolower(str_replace(['-', '.'], '_', $table.'_'.implode('_', $columns).'_'.$type));

        if (strlen($identifier) > self::MAX_IDENTIFIER_LENGTH) {
            $unsafeIdentifiers[] = $identifier;
        }
    }
}
