<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CollectionReconciliation\HistoricalReconciliation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ReconcileReleaseFragments extends Command
{
    protected $signature = 'releases:reconcile-fragments
        {--release=* : Explicit source release IDs}
        {--group= : Primary group ID for a bounded date selector}
        {--from= : Inclusive UTC posting-date start}
        {--to= : Inclusive UTC posting-date end, at most one hour after start}
        {--max-candidates=100 : Maximum source releases (2-256)}
        {--anchor= : Selected release to preserve, default oldest add date then ID}
        {--report= : Create a JSON plan at this new path}
        {--apply : Apply the reviewed plan}
        {--digest= : Required reviewed plan digest for apply}';

    protected $description = 'Plan or explicitly apply bounded PAR2 reconciliation of historical release fragments';

    public function handle(HistoricalReconciliation $recovery): int
    {
        try {
            $max = filter_var($this->option('max-candidates'), FILTER_VALIDATE_INT);
            if ($max === false || $max < 2 || $max > 256) {
                throw new RuntimeException('Use --max-candidates=2..256.');
            }
            $ids = array_map('intval', $this->option('release'));
            if ($ids === []) {
                $group = filter_var($this->option('group'), FILTER_VALIDATE_INT);
                $from = strtotime((string) $this->option('from'));
                $to = strtotime((string) $this->option('to'));
                if (! $group || ! $from || ! $to || $from > $to || $to - $from > 3600) {
                    throw new RuntimeException('Select explicit --release IDs or --group with --from/--to spanning at most one hour.');
                }
                $ids = DB::table('releases')->where('groups_id', $group)->whereBetween('postdate', [gmdate('Y-m-d H:i:s', $from), gmdate('Y-m-d H:i:s', $to)])
                    ->orderBy('id')->limit($max + 1)->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            }
            if (count($ids) > $max || count($ids) < 2 || min($ids) < 1) {
                throw new RuntimeException('Source population is empty, singular, invalid, or exceeds the candidate bound; narrow the selector.');
            }
            $anchor = $this->option('anchor') === null ? null : (int) $this->option('anchor');
            if ($this->option('apply')) {
                $this->line($recovery->apply($ids, $anchor, (string) $this->option('digest')));

                return self::SUCCESS;
            }
            $plan = $recovery->plan($ids, $anchor);
            $json = json_encode($plan, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
            $path = (string) $this->option('report');
            if ($path !== '') {
                $file = @fopen($path, 'xb');
                if ($file === false) {
                    throw new RuntimeException('Report path must be new and writable.');
                }
                try {
                    if (fwrite($file, $json) !== strlen($json)) {
                        throw new RuntimeException('Incomplete report write.');
                    }
                } finally {
                    fclose($file);
                }
            }
            $this->line($json);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
