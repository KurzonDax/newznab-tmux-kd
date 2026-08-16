<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Release;
use App\Services\AdditionalProcessing\AdditionalCandidateQuery;
use App\Services\AdditionalProcessing\Config\PasswordInspectionMode;
use App\Services\AdditionalProcessing\UnknownPayloadCandidateSelector;
use App\Services\Nzb\NzbParserService;
use App\Services\Nzb\NzbService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

#[Signature('releases:requeue-unknown-payloads
    {--dry-run : Report matching releases without changing them (default)}
    {--apply : Return matching releases to the additional-processing pending state}
    {--limit=0 : Stop after this many eligible releases (0 means unlimited)}
    {--category= : Restrict to an exact category ID}
    {--min-size=0 : Additional minimum release size in bytes}
    {--max-size=0 : Additional maximum release size in bytes}')]
#[Description('Re-queue processed releases whose NZBs contain extensionless or non-informative payloads')]
class RequeueUnknownPayloads extends Command
{
    public function __construct(
        private readonly NzbService $nzbService,
        private readonly NzbParserService $nzbParser,
        private readonly UnknownPayloadCandidateSelector $candidateSelector,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->option('dry-run') && $this->option('apply')) {
            $this->error('Choose either --dry-run or --apply, not both.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $limit = max((int) $this->option('limit'), 0);
        $scanned = 0;
        $eligible = 0;
        $updated = 0;

        foreach ($this->candidates()->lazyById(100) as $release) {
            $scanned++;
            if (! $this->nzbContainsUnknownPayload($release)) {
                continue;
            }

            $eligible++;
            if ($apply) {
                $updated += Release::query()->whereKey($release->id)->update([
                    'haspreview' => -1,
                    'passwordstatus' => PasswordInspectionMode::pendingReleaseStatus(),
                ]);
            }

            if ($limit > 0 && $eligible >= $limit) {
                break;
            }
        }

        $mode = $apply ? 'Applied' : 'Dry run';
        $this->info("{$mode}: scanned {$scanned} releases; {$eligible} contained extensionless or .bin payloads; {$updated} updated.");

        return self::SUCCESS;
    }

    /**
     * @return Builder<Release>
     */
    private function candidates(): Builder
    {
        $minimum = max(AdditionalCandidateQuery::minSizeBytes(), max((int) $this->option('min-size'), 0));
        $configuredMaximum = AdditionalCandidateQuery::maxSizeBytes();
        $requestedMaximum = max((int) $this->option('max-size'), 0);
        $maximum = match (true) {
            $configuredMaximum === 0 => $requestedMaximum,
            $requestedMaximum === 0 => $configuredMaximum,
            default => min($configuredMaximum, $requestedMaximum),
        };

        $query = Release::query()
            ->where('haspreview', '!=', -1)
            ->where('passwordstatus', 0)
            ->where('nzbstatus', 1)
            ->whereDoesntHave('file')
            ->orderBy('id');

        if ($minimum > 0) {
            $query->where('size', '>', $minimum);
        }
        if ($maximum > 0) {
            $query->where('size', '<', $maximum);
        }
        if ((string) $this->option('category') !== '') {
            $query->where('categories_id', (int) $this->option('category'));
        }

        return $query;
    }

    private function nzbContainsUnknownPayload(Release $release): bool
    {
        $path = $this->nzbService->nzbPath((string) $release->guid);
        if ($path === false) {
            return false;
        }

        $contents = unzipGzipFile($path);
        if ($contents === false) {
            return false;
        }

        return $this->candidateSelector->hasRequeueEligibleFile($this->nzbParser->parseNzbFileList($contents));
    }
}
