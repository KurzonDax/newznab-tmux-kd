<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Facades\Search;
use App\Models\Category;
use App\Models\Release;
use App\Services\NameFixing\Extractors\ObfuscatedSubjectExtractor;
use App\Services\NameFixing\NameCandidate;
use App\Services\NameFixing\NameSource;
use App\Services\NameFixing\ReleaseNameWriter;
use Illuminate\Console\Command;

class RequeueFailedBooks extends Command
{
    protected $signature = 'books:requeue-failed
                            {--dry-run : Preview affected releases without writing changes}
                            {--limit=0 : Max releases to inspect (0 = no limit)}
                            {--only-obfuscated : Only requeue rows with obfuscated subject that can be normalized}
                            {--normalize-obfuscated-all : Normalize obfuscated names across all book/audiobook releases, not just bookinfo_id=-2}';

    protected $description = 'Requeue failed book metadata rows (bookinfo_id = -2) and normalize obfuscated search names.';

    public function handle(ObfuscatedSubjectExtractor $extractor, ReleaseNameWriter $writer): int
    {
        $limit = max(0, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');
        $onlyObfuscated = (bool) $this->option('only-obfuscated');
        $normalizeObfuscatedAll = (bool) $this->option('normalize-obfuscated-all');

        $query = Release::query()
            ->select(['id', 'name', 'searchname', 'fromname', 'groups_id', 'categories_id', 'bookinfo_id'])
            ->where(function ($builder): void {
                $builder->whereBetween('categories_id', [Category::BOOKS_ROOT, Category::BOOKS_UNKNOWN])
                    ->orWhere('categories_id', Category::MUSIC_AUDIOBOOK);
            })
            ->orderBy('id');

        if ($normalizeObfuscatedAll) {
            $query->where(function ($builder): void {
                $builder
                    ->where('searchname', 'like', 'N:/NZB%')
                    ->orWhere('searchname', 'like', 'N_NZB_%')
                    ->orWhere('name', 'like', 'N:/NZB%')
                    ->orWhere('name', 'like', 'N_NZB_%');
            });
        } else {
            $query->where('bookinfo_id', -2);
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        $releases = $query->get();
        $total = $releases->count();

        if ($total === 0) {
            $this->info('No failed book releases found (bookinfo_id = -2).');

            return self::SUCCESS;
        }

        $queued = 0;
        $renamed = 0;
        $skipped = 0;

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        foreach ($releases as $release) {
            $bar->advance();

            $normalized = $extractor->extract((string) $release->searchname)
                ?? $extractor->extract((string) $release->name);

            if ($onlyObfuscated && $normalized === null) {
                $skipped++;

                continue;
            }

            $shouldRequeue = (int) $release->bookinfo_id === -2;
            $rename = $normalized !== null && $normalized !== $release->searchname;

            if (! $shouldRequeue && ! $rename) {
                $skipped++;

                continue;
            }

            if ($dryRun) {
                if ($rename) {
                    $this->newLine();
                    $this->line(sprintf(
                        '[dry-run] #%d searchname: "%s" -> "%s"',
                        (int) $release->id,
                        (string) $release->searchname,
                        (string) $normalized
                    ));
                }

                $queued++;
                if ($rename) {
                    $renamed++;
                }

                continue;
            }

            // A written rename clears bookinfo_id with the other links, which requeues the lookup too.
            $written = false;
            if ($rename) {
                $outcome = $writer->rename($release, new NameCandidate((int) $release->id, (string) $normalized, NameSource::BookSubjectRepair,
                    'Obfuscated subject', categoryId: (int) $release->categories_id));
                $written = $outcome->written;
                if (! $written) {
                    $this->newLine();
                    $this->warn(sprintf('#%d not renamed: %s', (int) $release->id, $outcome->reason()));
                }
            }
            if (! $written && $shouldRequeue) {
                Release::query()->where('id', $release->id)->update(['bookinfo_id' => null]);
                Search::updateRelease((int) $release->id);
            }

            if (! $written && ! $shouldRequeue) {
                $skipped++;

                continue;
            }

            $queued++;
            if ($written) {
                $renamed++;
            }
        }

        $bar->finish();
        $this->newLine(2);

        if ($dryRun) {
            $this->info(sprintf(
                'Dry run complete. Inspected: %d, would apply updates: %d, would normalize names: %d, skipped: %d.',
                $total,
                $queued,
                $renamed,
                $skipped
            ));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Requeued %d failed book release(s). Normalized %d searchname value(s). Skipped: %d.',
            $queued,
            $renamed,
            $skipped
        ));
        $this->line('Next step: run your books postprocess (e.g. `php artisan update:postprocess book`).');

        return self::SUCCESS;
    }
}
