<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Nzb\NzbService;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

final class NzbServicePathResolutionTest extends TestCase
{
    public function test_select_preferred_base_path_prefers_existing_runtime_storage_path_for_foreign_storage_root(): void
    {
        $tempDir = sys_get_temp_dir().'/nzb-service-'.uniqid('', true);
        $configuredPath = $tempDir.'/foreign-app/storage/nzb/';
        $runtimePath = $tempDir.'/runtime-app/storage/nzb/';
        mkdir($runtimePath, 0777, true);

        try {
            $service = $this->makeServiceWithoutConstructor();
            $preferredPath = \Closure::bind(
                fn (array $paths): string => $this->selectPreferredNzbBasePath($paths),
                $service,
                NzbService::class
            )([$configuredPath, $runtimePath]);

            $this->assertSame($runtimePath, $preferredPath);
        } finally {
            $this->deleteDirectory($tempDir);
        }
    }

    public function test_nzb_path_returns_existing_file_from_alternate_candidate_base_paths(): void
    {
        $tempDir = sys_get_temp_dir().'/nzb-path-'.uniqid('', true);
        $guid = '4aabfe07-daff-4d28-9d1d-d2a4ab7b6511';
        $configuredPath = $tempDir.'/foreign-app/storage/nzb/';
        $runtimePath = $tempDir.'/runtime-app/storage/nzb/';
        $expectedFile = $runtimePath.'4/a/a/b/'.$guid.'.nzb.gz';
        mkdir(dirname($expectedFile), 0777, true);
        file_put_contents($expectedFile, 'test');

        try {
            $service = $this->makeServiceWithoutConstructor();
            \Closure::bind(
                function (int $splitLevel, string $primaryPath, array $paths): void {
                    $this->nzbSplitLevel = $splitLevel;
                    $this->siteNzbPath = $primaryPath;
                    $this->siteNzbPaths = $paths;
                },
                $service,
                NzbService::class
            )(4, $configuredPath, [$configuredPath, $runtimePath]);

            $this->assertSame($expectedFile, $service->nzbPath($guid));
        } finally {
            $this->deleteDirectory($tempDir);
        }
    }

    public function test_storage_is_unavailable_when_no_candidate_root_is_a_readable_directory(): void
    {
        $service = $this->makeServiceWithoutConstructor();
        \Closure::bind(
            function (string $preferredPath, array $paths): void {
                $this->siteNzbPath = $preferredPath;
                $this->siteNzbPaths = $paths;
            },
            $service,
            NzbService::class
        )('/unmounted/nzb-one/', ['/unmounted/nzb-one/', '/unmounted/nzb-two/']);

        $this->assertFalse($service->hasReadableNzbStorage());
    }

    public function test_storage_is_available_when_the_preferred_root_is_a_readable_directory(): void
    {
        $readableRoot = $this->makeTempDirectory('nzb-readable-root').'/';
        $service = $this->makeServiceWithoutConstructor();
        \Closure::bind(
            function (string $preferredPath, array $paths): void {
                $this->siteNzbPath = $preferredPath;
                $this->siteNzbPaths = $paths;
            },
            $service,
            NzbService::class
        )($readableRoot, ['/unmounted/nzb/', $readableRoot]);

        $this->assertTrue($service->hasReadableNzbStorage());
    }

    public function test_readable_fallback_does_not_mask_an_unavailable_preferred_root(): void
    {
        $readableFallback = $this->makeTempDirectory('nzb-readable-fallback').'/';
        $service = $this->makeServiceWithoutConstructor();
        \Closure::bind(
            function (string $preferredPath, array $paths): void {
                $this->siteNzbPath = $preferredPath;
                $this->siteNzbPaths = $paths;
            },
            $service,
            NzbService::class
        )('/unmounted/external-nzb/', ['/unmounted/external-nzb/', $readableFallback]);

        $this->assertFalse($service->hasReadableNzbStorage());
    }

    public function test_nzb_path_honors_split_level_one_when_searching_candidate_paths(): void
    {
        $tempDir = sys_get_temp_dir().'/nzb-path-split-one-'.uniqid('', true);
        $guid = '4aabfe07-daff-4d28-9d1d-d2a4ab7b6511';
        $configuredPath = $tempDir.'/foreign-app/storage/nzb/';
        $runtimePath = $tempDir.'/runtime-app/storage/nzb/';
        $expectedFile = $runtimePath.'4/'.$guid.'.nzb.gz';
        mkdir(dirname($expectedFile), 0777, true);
        file_put_contents($expectedFile, 'test');

        try {
            $service = $this->makeServiceWithoutConstructor();
            \Closure::bind(
                function (int $splitLevel, string $primaryPath, array $paths): void {
                    $this->nzbSplitLevel = $splitLevel;
                    $this->siteNzbPath = $primaryPath;
                    $this->siteNzbPaths = $paths;
                },
                $service,
                NzbService::class
            )(1, $configuredPath, [$configuredPath, $runtimePath]);

            $this->assertSame($expectedFile, $service->nzbPath($guid));
        } finally {
            $this->deleteDirectory($tempDir);
        }
    }

    public function test_nzb_path_works_with_existing_resources_nzb_base_path(): void
    {
        $tempDir = sys_get_temp_dir().'/nzb-path-resources-'.uniqid('', true);
        $guid = '4aabfe07-daff-4d28-9d1d-d2a4ab7b6511';
        $configuredPath = $tempDir.'/resources/nzb/';
        $storagePath = $tempDir.'/storage/nzb/';
        $expectedFile = $configuredPath.'4/'.$guid.'.nzb.gz';
        $configuredLinkTarget = rtrim($configuredPath, '/');
        $storageLinkPath = rtrim($storagePath, '/');

        mkdir(dirname($expectedFile), 0777, true);
        mkdir(dirname($storageLinkPath), 0777, true);
        symlink($configuredLinkTarget, $storageLinkPath);
        file_put_contents($expectedFile, 'test');

        try {
            $service = $this->makeServiceWithoutConstructor();
            \Closure::bind(
                function (int $splitLevel, string $primaryPath, array $paths): void {
                    $this->nzbSplitLevel = $splitLevel;
                    $this->siteNzbPath = $primaryPath;
                    $this->siteNzbPaths = $paths;
                },
                $service,
                NzbService::class
            )(1, $configuredPath, [$configuredPath, $storagePath]);

            $this->assertSame($expectedFile, $service->nzbPath($guid));
        } finally {
            $this->deleteDirectory($tempDir);
        }
    }

    public function test_build_nzb_path_creates_group_writable_setgid_directories_despite_umask(): void
    {
        $tempDir = sys_get_temp_dir().'/nzb-path-permissions-'.uniqid('', true);
        $basePath = $tempDir.'/';
        $previousUmask = umask(0022);

        try {
            $service = $this->makeServiceWithoutConstructor();
            \Closure::bind(
                function (string $path): void {
                    $this->siteNzbPath = $path;
                },
                $service,
                NzbService::class
            )($basePath);

            $path = $service->buildNzbPath('4aabfe07-daff-4d28-9d1d-d2a4ab7b6511', 4, true);

            $this->assertSame($basePath.'4/a/a/b/', $path);
            foreach ([rtrim($basePath, '/'), $basePath.'4', $basePath.'4/a', $basePath.'4/a/a', $basePath.'4/a/a/b'] as $directory) {
                $this->assertSame(02775, fileperms($directory) & 07777);
            }
        } finally {
            umask($previousUmask);
            $this->deleteDirectory($tempDir);
        }
    }

    public function test_a_stored_split_level_of_zero_reads_back_the_file_the_write_path_stored_flat(): void
    {
        $basePath = $this->makeTempDirectory('nzb-flat-storage').'/';
        $guid = '4aabfe07-daff-4d28-9d1d-d2a4ab7b6511';

        $service = $this->makeServiceWithoutConstructor();
        $this->applyStorageState($service, 0, $basePath, [$basePath]);

        $writtenPath = $service->buildNzbPath($guid, $service->getNzbSplitLevel(), true).$guid.'.nzb.gz';
        file_put_contents($writtenPath, 'test');

        $this->assertSame($basePath.$guid.'.nzb.gz', $writtenPath, 'A stored 0 must write flat.');
        $this->assertSame($writtenPath, $service->nzbPath($guid), 'A stored 0 must read flat.');
    }

    public function test_a_file_stored_at_the_previous_split_level_is_still_found_after_the_level_changes(): void
    {
        $basePath = $this->makeTempDirectory('nzb-level-change').'/';
        $guid = '4aabfe07-daff-4d28-9d1d-d2a4ab7b6511';
        $storedFile = $basePath.'4/a/a/b/'.$guid.'.nzb.gz';
        mkdir(dirname($storedFile), 0777, true);
        file_put_contents($storedFile, 'test');

        foreach ([0, 1, 2, 6] as $newLevel) {
            $service = $this->makeServiceWithoutConstructor();
            $this->applyStorageState($service, $newLevel, $basePath, [$basePath]);

            $this->assertSame(
                $storedFile,
                $service->nzbPath($guid),
                "A level-4 file must survive the level changing to {$newLevel}."
            );
        }
    }

    public function test_a_flat_file_is_found_after_the_level_changes_away_from_zero(): void
    {
        $basePath = $this->makeTempDirectory('nzb-flat-to-deep').'/';
        $guid = '4aabfe07-daff-4d28-9d1d-d2a4ab7b6511';
        $storedFile = $basePath.$guid.'.nzb.gz';
        file_put_contents($storedFile, 'test');

        $service = $this->makeServiceWithoutConstructor();
        $this->applyStorageState($service, 4, $basePath, [$basePath]);

        $this->assertSame($storedFile, $service->nzbPath($guid));
    }

    public function test_the_depth_fallback_searches_every_candidate_base_path(): void
    {
        $tempDir = $this->makeTempDirectory('nzb-fallback-candidates');
        $guid = '4aabfe07-daff-4d28-9d1d-d2a4ab7b6511';
        $configuredPath = $tempDir.'/foreign-app/storage/nzb/';
        $runtimePath = $tempDir.'/runtime-app/storage/nzb/';
        $storedFile = $runtimePath.'4/'.$guid.'.nzb.gz';
        mkdir(dirname($storedFile), 0777, true);
        file_put_contents($storedFile, 'test');

        $service = $this->makeServiceWithoutConstructor();
        $this->applyStorageState($service, 4, $configuredPath, [$configuredPath, $runtimePath]);

        $this->assertSame($storedFile, $service->nzbPath($guid));
    }

    public function test_a_release_with_no_stored_file_at_any_depth_still_reports_missing(): void
    {
        $basePath = $this->makeTempDirectory('nzb-truly-missing').'/';
        $guid = '4aabfe07-daff-4d28-9d1d-d2a4ab7b6511';
        $otherGuid = '9bbcfe07-daff-4d28-9d1d-d2a4ab7b6512';
        $decoyFile = $basePath.'9/b/b/'.$otherGuid.'.nzb.gz';
        mkdir(dirname($decoyFile), 0777, true);
        file_put_contents($decoyFile, 'test');

        $service = $this->makeServiceWithoutConstructor();
        $this->applyStorageState($service, 4, $basePath, [$basePath]);

        $this->assertFalse($service->nzbPath($guid));
    }

    /**
     * The depth fallback skips the configured depth, so it can only ever answer with the
     * stale flat copy. Getting the configured-depth file back therefore proves the first
     * check answered and the fallback never ran -- the happy path is unchanged.
     */
    public function test_a_file_at_the_configured_depth_is_answered_by_the_first_check(): void
    {
        $basePath = $this->makeTempDirectory('nzb-two-depths').'/';
        $guid = '4aabfe07-daff-4d28-9d1d-d2a4ab7b6511';
        $configuredDepthFile = $basePath.'4/a/a/b/'.$guid.'.nzb.gz';
        $staleFile = $basePath.$guid.'.nzb.gz';
        mkdir(dirname($configuredDepthFile), 0777, true);
        file_put_contents($configuredDepthFile, 'current');
        file_put_contents($staleFile, 'stale');

        $service = $this->makeServiceWithoutConstructor();
        $this->applyStorageState($service, 4, $basePath, [$basePath]);

        $this->assertSame(
            $configuredDepthFile,
            $service->nzbPath($guid),
            'A hit at the configured depth must win before any fallback probing happens.'
        );
    }

    /**
     * @return array<string, array{0: mixed, 1: int}>
     */
    public static function splitLevelSettingProvider(): array
    {
        return [
            'missing row' => [null, NzbService::DEFAULT_SPLIT_LEVEL],
            'blank row' => ['', NzbService::DEFAULT_SPLIT_LEVEL],
            'whitespace row' => ['  ', NzbService::DEFAULT_SPLIT_LEVEL],
            'non-numeric row' => ['four', NzbService::DEFAULT_SPLIT_LEVEL],
            'explicit zero' => [0, 0],
            'explicit zero as string' => ['0', 0],
            'explicit level' => [3, 3],
            'above the cap' => [40, NzbService::MAX_SPLIT_LEVEL],
            'negative' => [-2, 0],
        ];
    }

    #[DataProvider('splitLevelSettingProvider')]
    public function test_split_level_resolution_from_the_stored_setting(mixed $stored, int $expected): void
    {
        $this->assertSame($expected, NzbService::resolveSplitLevel($stored));
    }

    /**
     * @param  list<string>  $candidatePaths
     */
    private function applyStorageState(NzbService $service, int $splitLevel, string $primaryPath, array $candidatePaths): void
    {
        \Closure::bind(
            function (int $splitLevel, string $primaryPath, array $paths): void {
                $this->nzbSplitLevel = $splitLevel;
                $this->siteNzbPath = $primaryPath;
                $this->siteNzbPaths = $paths;
            },
            $service,
            NzbService::class
        )($splitLevel, $primaryPath, $candidatePaths);
    }

    private function makeServiceWithoutConstructor(): NzbService
    {
        return (new ReflectionClass(NzbService::class))->newInstanceWithoutConstructor();
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isLink()) {
                unlink($item->getPathname());
            } elseif ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }
}
