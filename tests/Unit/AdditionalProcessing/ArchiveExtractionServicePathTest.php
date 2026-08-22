<?php

declare(strict_types=1);

namespace Tests\Unit\AdditionalProcessing;

use App\Services\AdditionalProcessing\ArchiveExtractionService;
use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;
use ReflectionClass;
use ReflectionProperty;
use Tests\TestCase;

class ArchiveExtractionServicePathTest extends TestCase
{
    public function test_it_lists_a_store_mode_archive_and_a_truncated_fragment_from_disk(): void
    {
        $fixture = base_path('tests/Fixtures/audio-store.rar');
        $service = new ArchiveExtractionService($this->config());

        $listing = $service->listArchiveContentsAtPath($fixture);

        $this->assertFalse($listing['hasPassword']);
        $this->assertSame(
            [
                ['name' => '00-group.nfo', 'size' => 11],
                ['name' => '01-track.flac', 'size' => 1024],
            ],
            array_map(
                static fn (array $file): array => [
                    'name' => $file['name'],
                    'size' => $file['size'],
                ],
                $listing['files'],
            ),
        );

        $truncated = $this->makeTempPath('audio-archive-fragment', '.rar');
        file_put_contents($truncated, substr((string) file_get_contents($fixture), 0, 80));
        $fragment = $service->listArchiveContentsAtPath($truncated);

        $this->assertSame('00-group.nfo', $fragment['files'][0]['name'] ?? null);
    }

    public function test_it_extracts_a_store_mode_file_to_the_destination_without_an_external_tool(): void
    {
        $destination = $this->makeTempDirectory('audio-archive-extraction');
        $service = new ArchiveExtractionService($this->config());

        $path = $service->extractSpecificFileToPath(
            base_path('tests/Fixtures/audio-store.rar'),
            '01-track.flac',
            $destination,
        );

        $this->assertSame($destination.DIRECTORY_SEPARATOR.'01-track.flac', $path);
        $this->assertFileExists((string) $path);
        $this->assertSame(1024, filesize((string) $path));
    }

    public function test_it_runs_the_external_tool_against_the_original_archive_path(): void
    {
        $fixture = base_path('tests/Fixtures/audio-store.rar');
        $destination = $this->makeTempDirectory('audio-external-extraction');
        $commands = [];
        $service = new ArchiveExtractionService(
            $this->config(unrarPath: '/opt/unrar'),
            commandRunner: function (string $command) use (&$commands, $destination): string {
                $commands[] = $command;
                file_put_contents($destination.DIRECTORY_SEPARATOR.'01-track.flac', str_repeat('f', 1024));

                return '';
            },
        );

        $path = $service->extractSpecificFileToPath($fixture, '01-track.flac', $destination);

        $this->assertSame($destination.DIRECTORY_SEPARATOR.'01-track.flac', $path);
        $this->assertCount(1, $commands);
        $this->assertStringContainsString($fixture, $commands[0]);
        $this->assertSame(
            ['01-track.flac'],
            array_values(array_diff(scandir($destination) ?: [], ['.', '..'])),
            'The original archive path is used directly; no archive copy is written.',
        );
    }

    private function config(string|false $unrarPath = false, string|false $unzipPath = false): ProcessingConfiguration
    {
        $reflection = new ReflectionClass(ProcessingConfiguration::class);
        /** @var ProcessingConfiguration $config */
        $config = $reflection->newInstanceWithoutConstructor();

        foreach ([
            'unrarPath' => $unrarPath,
            'unzipPath' => $unzipPath,
            'timeoutPath' => false,
            'timeoutSeconds' => 0,
            'debugMode' => false,
        ] as $property => $value) {
            (new ReflectionProperty(ProcessingConfiguration::class, $property))->setValue($config, $value);
        }

        return $config;
    }
}
