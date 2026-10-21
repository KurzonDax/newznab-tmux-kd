<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\UpdateReleasesIndexSchema;
use App\Services\Search\Support\ManticoreIndexRegistry;
use App\Support\ReleaseSearchIndexDocument;
use Illuminate\Console\OutputStyle;
use Manticoresearch\Client as ManticoreClient;
use Manticoresearch\Table;
use Mockery;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * The imported flag in the releases search index (issue #1050 section 5): the document carries
 * `is_imported` as a number the index can filter on, and the existing schema command adds the
 * column to an index made before it existed.
 */
final class ReleaseImportedSearchIndexTest extends TestCase
{
    public function test_the_release_document_carries_the_imported_flag_as_a_number(): void
    {
        $this->assertContains('is_imported', ReleaseSearchIndexDocument::fields());
        $this->assertSame(1, ReleaseSearchIndexDocument::normalize(['is_imported' => '1'])['is_imported']);
        $this->assertSame(0, ReleaseSearchIndexDocument::normalize(['is_imported' => 0])['is_imported']);
        $this->assertSame(0, ReleaseSearchIndexDocument::normalize([])['is_imported']);
        $this->assertSame(1, ReleaseSearchIndexDocument::normalizeForBulk(ReleaseSearchIndexDocument::normalize(['id' => 7, 'is_imported' => true]))['is_imported']);
    }

    public function test_a_new_manticore_releases_table_declares_the_flag_as_an_integer_attribute(): void
    {
        $this->assertSame(['type' => 'integer'], ManticoreIndexRegistry::definitions()['releases']['columns']['is_imported'] ?? null);
    }

    public function test_the_manticore_schema_command_adds_the_flag_to_a_table_that_lacks_it_and_leaves_one_that_has_it(): void
    {
        $this->assertContains(['add', 'is_imported', 'int'], $this->manticoreAlterations(['id' => ['Type' => 'bigint'], 'name' => ['Type' => 'text']]));
        $this->assertNotContains(['add', 'is_imported', 'int'], $this->manticoreAlterations(['id' => ['Type' => 'bigint'], 'is_imported' => ['Type' => 'uint']]));
    }

    /**
     * The columns `nntmux:update-releases-index --add-fields` adds to a table with the given columns.
     *
     * @param  array<string, array{Type: string}>  $columns
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function manticoreAlterations(array $columns): array
    {
        $alterations = [];
        $table = Mockery::mock(Table::class);
        $table->shouldReceive('describe')->andReturn($columns);
        $table->shouldReceive('alter')->andReturnUsing(function (string $operation, string $column, string $type) use (&$alterations): array {
            $alterations[] = [$operation, $column, $type];

            return [];
        });
        $client = Mockery::mock(ManticoreClient::class);
        $client->shouldReceive('table')->with('releases_rt')->andReturn($table);

        $command = $this->app->make(UpdateReleasesIndexSchema::class);
        $command->setLaravel($this->app);
        $input = new ArrayInput(['--add-fields' => true], $command->getDefinition());
        $input->setInteractive(false);
        $command->setInput($input);
        $command->setOutput(new OutputStyle($input, new BufferedOutput));
        (new ReflectionProperty($command, 'client'))->setValue($command, $client);
        $this->assertSame(0, (new ReflectionMethod($command, 'addNewFields'))->invoke($command));

        return $alterations;
    }
}
