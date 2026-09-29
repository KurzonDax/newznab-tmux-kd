<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Release;
use App\Services\Categorization\ReleaseRecategorizer;
use Illuminate\Console\Command;

class RecategorizeReleases extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nntmux:recategorize-releases
    {--misc : Re-categorize all releases in misc categories}
    {--all : Re-categorize all releases}
    {--test : Test only, no updates}
    {--group= : Re-categorize all releases in a group}
    {--groups= : Re-categorize all releases in a list of groups}
    {--category= : Re-categorize all releases in a category}
    {--categories= : Re-categorize all releases in a list of categories}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Re-categorize releases based on their name and group.';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $countQuery = Release::query();
        if ($this->option('misc')) {
            $countQuery->whereIn('categories_id', Category::OTHERS_GROUP);
        } elseif ($this->option('all')) {
            if (! $this->option('test') && ! $this->confirm('This will reset categorization on all releases and re-categorize them all from scratch. Are you sure? (y/n)', false)) {
                $this->info('Reset script stopped.');
                exit();
            }

        } elseif ($this->option('group')) {
            $countQuery->where('groups_id', $this->option('group'));
        } elseif ($this->option('groups')) {
            $countQuery->whereIn('groups_id', explode(',', $this->option('groups')));
        } elseif ($this->option('category')) {
            $countQuery->where('categories_id', $this->option('category'));
        } elseif ($this->option('categories')) {
            $countQuery->whereIn('categories_id', explode(',', $this->option('categories')));
        } elseif ($this->option('test')) {
            $countQuery->where('iscategorized', 0);
        } else {
            $this->error('You must specify at least one option. See: --help');
            exit();
        }

        $count = $countQuery->count();

        $recategorizer = new ReleaseRecategorizer;
        $bar = $this->output->createProgressBar($count);
        $bar->start();
        $countQuery
            ->select(['id', 'searchname', 'fromname', 'groups_id', 'categories_id', 'iscategorized'])
            ->eachById(function (Release $release) use ($bar, $recategorizer): void {
                $bar->advance();
                $newCategoryId = $recategorizer->categoryFor($release);

                if ((int) $release->categories_id !== $newCategoryId) {
                    if ($this->option('test')) {
                        $this->info('Would have changed '.$release->searchname.' from '.$release->categories_id.' to '.$newCategoryId);
                    } else {
                        $recategorizer->moveTo((int) $release->id, $newCategoryId);

                        /** @var Category|null $newCategory */
                        $newCategory = Category::query()->where('id', $newCategoryId)->first();

                        $this->line('');
                        $this->output->writeln('<fg=yellow>ID       :</> '.$release->id);
                        $this->output->writeln('<fg=green>Release  :</> '.$release->searchname);
                        $this->output->writeln('<fg=cyan>Group    :</> '.$release->group->name);
                        $oldCategoryTitle = $release->category?->parent ? ($release->category->parent->title.' -> '.$release->category->title) : ($release->category?->title ?? 'N/A'); // @phpstan-ignore nullsafe.neverNull

                        $newCategoryTitle = $newCategory?->parent ? ($newCategory->parent->title.' -> '.$newCategory->title) : ($newCategory?->title ?? 'N/A'); // @phpstan-ignore nullsafe.neverNull
                        $this->output->writeln('<fg=white>Category :</> '.$oldCategoryTitle.' <fg=yellow>→</> <fg=magenta>'.$newCategoryTitle.'</>');
                        $this->line('');
                    }
                } elseif ($this->option('all')) {
                    if ($this->option('test') && (int) $release->iscategorized !== 1) {
                        $this->info('Would have finalized '.$release->searchname.' as categorized');
                    } elseif (! $this->option('test')) {
                        Release::query()->where('id', $release->id)->update([
                            'iscategorized' => 1,
                        ]);
                    }
                }
            }, 1000);
        $bar->finish();
    }
}
