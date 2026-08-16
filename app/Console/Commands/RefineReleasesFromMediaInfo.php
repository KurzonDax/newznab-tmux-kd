<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Release;
use App\Services\Categorization\MediaInfoRefinementService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

#[Signature('releases:refine-from-mediainfo
    {--root= : Restrict to movies, tv, xxx, or music (name or root category id)}
    {--limit=0 : Maximum releases to inspect; zero means no limit}
    {--dry-run : Report refinements without writing changes}')]
#[Description('Refine eligible Other releases into media-specific categories using persisted MediaInfo')]
class RefineReleasesFromMediaInfo extends Command
{
    public function __construct(
        private readonly MediaInfoRefinementService $refinementService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $categoryId = $this->categoryIdForRoot($this->option('root'));
        if ($categoryId === false) {
            $this->error('Invalid --root. Use movies, tv, xxx, music, or the corresponding root category id.');

            return self::FAILURE;
        }

        $limit = max(0, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');
        $inspected = 0;
        $refined = 0;
        $rules = [];

        foreach ($this->candidates($categoryId)->lazyById(250) as $release) {
            if ($limit > 0 && $inspected >= $limit) {
                break;
            }

            $inspected++;
            $oldCategoryId = (int) $release->categories_id;
            $decision = $this->refinementService->refine((int) $release->id, $dryRun);

            if ($decision === null || $decision->categoryId === $oldCategoryId) {
                continue;
            }

            $refined++;
            $rules[$decision->rule] = ($rules[$decision->rule] ?? 0) + 1;
        }

        ksort($rules);
        $prefix = $dryRun ? 'Dry run: ' : '';
        $this->info("{$prefix}inspected {$inspected}; refined {$refined}; unchanged ".($inspected - $refined).'.');
        foreach ($rules as $rule => $count) {
            $this->line("{$rule}: {$count}");
        }

        return self::SUCCESS;
    }

    /**
     * @return Builder<Release>
     */
    private function candidates(?int $categoryId): Builder
    {
        return Release::query()
            ->select(['id', 'categories_id'])
            ->whereIn('categories_id', $categoryId === null
                ? MediaInfoRefinementService::eligibleCategoryIds()
                : [$categoryId])
            ->where(function (Builder $query): void {
                $query->whereExists(function ($video): void {
                    $video->selectRaw('1')
                        ->from('video_data')
                        ->whereColumn('video_data.releases_id', 'releases.id');
                })->orWhereExists(function ($audio): void {
                    $audio->selectRaw('1')
                        ->from('audio_data')
                        ->whereColumn('audio_data.releases_id', 'releases.id');
                });
            });
    }

    private function categoryIdForRoot(mixed $root): int|false|null
    {
        if ($root === null || trim((string) $root) === '') {
            return null;
        }

        return match (strtolower(trim((string) $root))) {
            'movies', 'movie', (string) Category::MOVIE_ROOT => Category::MOVIE_OTHER,
            'tv', 'television', (string) Category::TV_ROOT => Category::TV_OTHER,
            'xxx', 'adult', (string) Category::XXX_ROOT => Category::XXX_OTHER,
            'music', 'audio', (string) Category::MUSIC_ROOT => Category::MUSIC_OTHER,
            default => false,
        };
    }
}
