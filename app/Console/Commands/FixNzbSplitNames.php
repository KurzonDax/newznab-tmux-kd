<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Release;
use App\Services\Categorization\CategorizationService;
use App\Services\NameFixing\NameCandidate;
use App\Services\NameFixing\NameSource;
use App\Services\NameFixing\NzbSplitUnwrapper;
use App\Services\NameFixing\ReleaseNameWriter;
use Illuminate\Console\Command;

class FixNzbSplitNames extends Command
{
    protected $signature = 'releases:fix-nzbsplit
                            {--dry-run : Preview changes without updating the database}
                            {--include-renamed : Include releases already marked as renamed}';

    protected $description = 'Fix NZBSPLIT-wrapped release names for unrenamed releases by default and recategorize the affected releases.';

    public function handle(CategorizationService $categorizationService, NzbSplitUnwrapper $nzbSplitUnwrapper, ReleaseNameWriter $writer): int
    {
        $includeRenamed = (bool) $this->option('include-renamed');

        $query = Release::query()
            ->select(['id', 'name', 'searchname', 'fromname', 'groups_id', 'categories_id'])
            ->where(function ($builder): void {
                $builder
                    ->where('name', 'like', '%__NZBSPLIT__%')
                    ->orWhere('searchname', 'like', '%__NZBSPLIT__%');
            })
            ->orderBy('id');

        if (! $includeRenamed) {
            $query->where('isrenamed', 0);
        }

        $count = (clone $query)->count();

        if ($count === 0) {
            $this->info($includeRenamed
                ? 'No NZBSPLIT-wrapped releases found.'
                : 'No unrenamed NZBSPLIT-wrapped releases found.'
            );

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $updated = 0;
        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $query->chunkById(250, function ($releases) use ($categorizationService, $nzbSplitUnwrapper, $writer, $dryRun, &$updated, $bar): void {
            foreach ($releases as $release) {
                $bar->advance();

                $newTitle = $nzbSplitUnwrapper->unwrap((string) $release->searchname)
                    ?? $nzbSplitUnwrapper->unwrap((string) $release->name);

                if ($newTitle === null) {
                    continue;
                }

                if ($dryRun) {
                    $category = $categorizationService->determineCategory(
                        $release->groups_id,
                        $newTitle,
                        (string) ($release->fromname ?? ''),
                        releaseId: (int) $release->id,
                    );
                    $newCategoryId = (int) ($category['categories_id'] ?? $release->categories_id);

                    $this->newLine();
                    $this->line(sprintf(
                        '[dry-run] #%d %s -> %s (%d -> %d)',
                        $release->id,
                        (string) $release->searchname,
                        $newTitle,
                        (int) $release->categories_id,
                        $newCategoryId
                    ));

                    continue;
                }

                // The rename event files the release: the listener recategorizes and refines it.
                $outcome = $writer->rename($release, new NameCandidate((int) $release->id, $newTitle, NameSource::NzbSplitRepair, 'NZBSPLIT wrapper'));
                if (! $outcome->written) {
                    $this->newLine();
                    $this->warn(sprintf('#%d not renamed: %s', $release->id, $outcome->refusal->value ?? $outcome->decision->rejection->value ?? 'declined'));

                    continue;
                }

                Release::query()->whereKey($release->id)->update(['name' => $newTitle]);
                $updated++;
            }
        });

        $bar->finish();
        $this->newLine(2);

        if ($dryRun) {
            $this->info(sprintf('Dry run complete. %d NZBSPLIT-wrapped releases inspected.', $count));

            return self::SUCCESS;
        }

        $this->info(sprintf('Updated %d NZBSPLIT-wrapped releases.', $updated));

        return self::SUCCESS;
    }
}
