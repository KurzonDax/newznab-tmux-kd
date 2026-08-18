<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Video;
use App\Services\TvBannerService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tv:fetch-banners {--limit=0 : Maximum shows to inspect; zero means all missing banners} {--delay=1000 : Delay between Fanart requests in milliseconds} {--dry-run : List eligible shows without fetching or writing}')]
#[Description('Fetch missing fanart.tv banners for TV shows with TVDB IDs')]
final class FetchTvBanners extends Command
{
    public function __construct(private readonly TvBannerService $tvBannerService)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $delayMilliseconds = (int) $this->option('delay');

        if ($limit < 0 || $delayMilliseconds < 0) {
            $this->error('--limit and --delay must be zero or greater.');

            return self::FAILURE;
        }

        $query = Video::query()
            ->select(['videos.id', 'videos.title', 'videos.tvdb'])
            ->join('tv_info', 'videos.id', '=', 'tv_info.videos_id')
            ->where('videos.tvdb', '>', 0)
            ->where('tv_info.banner', false)
            ->orderBy('videos.id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $shows = $query->get();

        if ((bool) $this->option('dry-run')) {
            $this->info(sprintf('Dry run: %d show(s) have a missing banner and a TVDB ID.', $shows->count()));

            return self::SUCCESS;
        }

        if (! $this->tvBannerService->isConfigured()) {
            $this->error('Fanart.tv is not configured. Set FANARTTV_APIKEY before running this command.');

            return self::FAILURE;
        }

        $saved = 0;
        $unavailable = 0;

        foreach ($shows as $index => $show) {
            if ($this->tvBannerService->fetch((int) $show->id, (int) $show->tvdb)) {
                $saved++;
            } else {
                $unavailable++;
            }

            if ($delayMilliseconds > 0 && $index < $shows->count() - 1) {
                usleep($delayMilliseconds * 1000);
            }
        }

        $this->info(sprintf(
            'Inspected %d show(s): saved %d banner(s); %d unavailable or failed.',
            $shows->count(),
            $saved,
            $unavailable,
        ));

        return self::SUCCESS;
    }
}
