<?php

declare(strict_types=1);

namespace Tests\Unit\AdditionalProcessing;

use App\Services\AdditionalProcessing\DTO\DownloadMetrics;
use App\Services\AdditionalProcessing\Enums\DownloadKind;
use App\Services\AdditionalProcessing\SevenZip\InspectionBudget;
use App\Services\AdditionalProcessing\UsenetDownloadService;
use App\Services\DTO\YencArticleMetadata;
use Mockery;
use Mockery\MockInterface;
use Symfony\Component\Process\Process;

/**
 * Builds generated 7z postings and a downloader that serves their articles.
 * 7z volumes are plain slices of the archive bytes, so a fixture can be
 * posted as parts of any size.
 */
trait BuildsSevenZipPostings
{
    /** @var array<string, array<string, mixed>> */
    private array $postedArticles = [];

    /** @var list<array{kind: string, ids: list<string>}> */
    private array $requests = [];

    /** @var list<string> */
    private array $commands = [];

    /** @var (\Closure(list<string>): void)|null Runs before each download() call. */
    private ?\Closure $downloadHook = null;

    private function sevenZipFixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/SevenZip/'.$name.'.7z'));
    }

    /**
     * NZB files for an archive cut into parts. $order lists which part each
     * NZB position holds; titles are random names, or fixture.7z.NNN when
     * $named. Every article is registered with the downloader.
     *
     * @param  list<int>|null  $order
     * @return list<array<string, mixed>>
     */
    private function sevenZipPosting(string $data, int $partSize, int $articleSize, ?array $order = null, bool $named = false): array
    {
        $parts = str_split($data, $partSize);
        $files = [];
        foreach ($parts as $part => $bytes) {
            $articles = str_split($bytes, $articleSize);
            $ids = [];
            foreach ($articles as $index => $article) {
                $ids[] = $id = 'p'.$part.'s'.$index.'@fixture';
                $this->postedArticles[$id] = [
                    'success' => true,
                    'data' => $article,
                    'groupUnavailable' => false,
                    'error' => null,
                    'crcFailures' => 0,
                    'crcFailed' => false,
                    'metadata' => new YencArticleMetadata(strlen($bytes), $index + 1, count($articles), $index * $articleSize, strlen($article)),
                ];
            }
            $files[$part] = [
                'title' => $named
                    ? '"fixture.7z.'.sprintf('%03d', $part + 1).'" yEnc ('.'1/'.count($ids).')'
                    : '"'.substr(hash('sha256', 'part'.$part), 0, 12).'" yEnc',
                'segments' => $ids,
                'segmentNumbers' => range(1, count($ids)),
                'segmentNumbersValid' => true,
                'partsactual' => count($ids),
                'size' => strlen($bytes),
            ];
        }

        return array_map(static fn (int $part): array => $files[$part], $order ?? array_keys($files));
    }

    private function sevenZipDownloader(): UsenetDownloadService&MockInterface
    {
        $downloads = Mockery::mock(UsenetDownloadService::class);
        $downloads->shouldReceive('beginReleaseScope')->andReturnNull();
        $downloads->shouldReceive('finishReleaseScope')->andReturn(new DownloadMetrics);
        $downloads->shouldReceive('meetsMinimumSize')->andReturnTrue();
        $downloads->shouldReceive('download')->andReturnUsing(function (DownloadKind $kind, array $ids): array {
            $ids = array_values($ids);
            if ($this->downloadHook !== null) {
                ($this->downloadHook)($ids);
            }
            $this->requests[] = ['kind' => $kind->value, 'ids' => $ids];
            $data = '';
            foreach ($ids as $id) {
                if (! isset($this->postedArticles[$id])) {
                    return ['success' => false, 'data' => null, 'groupUnavailable' => false, 'error' => 'missing', 'crcFailures' => 0, 'crcFailed' => false];
                }
                $data .= $this->postedArticles[$id]['data'];
            }

            return ['success' => true, 'data' => $data, 'groupUnavailable' => false, 'error' => null, 'crcFailures' => 0, 'crcFailed' => false,
                'metadata' => count($ids) === 1 ? $this->postedArticles[$ids[0]]['metadata'] : null];
        });
        $downloads->shouldReceive('downloadInspectionArticle')->andReturnUsing(function (string $id, string $group, InspectionBudget $budget): array {
            $this->requests[] = ['kind' => 'inspection', 'ids' => [$id]];

            return $this->postedArticles[$id]
                ?? ['success' => false, 'data' => null, 'groupUnavailable' => false, 'error' => 'missing', 'crcFailures' => 0, 'crcFailed' => false];
        });

        return $downloads;
    }

    /** @return list<string> */
    private function requestedIds(?string $kind = null): array
    {
        $ids = [];
        foreach ($this->requests as $request) {
            if ($kind === null || $request['kind'] === $kind) {
                array_push($ids, ...$request['ids']);
            }
        }

        return $ids;
    }

    /**
     * Runs commands the way the service's own runner does, and records them.
     *
     * @return \Closure(string): array{exitCode: int, output: string}
     */
    private function recordingRunner(?\Closure $rewrite = null): \Closure
    {
        return function (string $command) use ($rewrite): array {
            $this->commands[] = $command;
            $process = Process::fromShellCommandline('exec '.$command);
            $process->setTimeout(60);
            $process->run();
            $result = ['exitCode' => $process->getExitCode() ?? -1, 'output' => $process->getOutput()];

            return $rewrite === null ? $result : $rewrite($command, $result);
        };
    }

    private function requireSevenZip(): void
    {
        if (! is_executable('/usr/bin/7z')) {
            $this->markTestSkipped('7z is required for this test.');
        }
    }
}
