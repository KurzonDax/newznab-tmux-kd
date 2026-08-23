<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\AdditionalProcessing\ArchiveExtractionService;
use App\Services\AdditionalProcessing\AudioTagExtractor;
use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;
use App\Services\AdditionalProcessing\MediaTools;
use App\Services\AdditionalProcessing\NzbContentParser;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\UsenetDownloadService;
use App\Services\AudioProcessing\AudioDecodableLengthProbe;
use App\Services\AudioProcessing\AudioFetcher;
use App\Services\AudioProcessing\AudioPreviewEncoder;
use App\Services\AudioProcessing\AudioProcessingConfiguration;
use App\Services\AudioProcessing\AudioProcessingOrchestrator;
use App\Services\AudioProcessing\AudioReleaseProcessor;
use App\Services\AudioProcessing\AudioSourceSelector;
use App\Services\AudioProcessing\AudioTagRenamer;
use App\Services\Categorization\CategorizationService;
use App\Services\Categorization\MediaInfoRefinementService;
use App\Services\ReleaseExtraService;
use App\Services\Releases\PreviewGenerationPolicy;
use App\Services\TempWorkspaceService;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the dedicated audio post-processing path.
 *
 * Its NZB parsing, downloading and archive handling are the shared path's,
 * resolved from {@see AdditionalProcessingServiceProvider}; only the audio
 * decisions -- what to fetch, how far, and how to cut it -- are new.
 */
class AudioProcessingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AudioProcessingConfiguration::class, fn (): AudioProcessingConfiguration => new AudioProcessingConfiguration);

        $this->app->singleton(MediaTools::class, function ($app): MediaTools {
            $config = $app->make(ProcessingConfiguration::class);

            return new MediaTools($config->timeoutSeconds, $config->mediaInfoPath);
        });

        $this->app->singleton(AudioSourceSelector::class, fn (): AudioSourceSelector => new AudioSourceSelector);

        $this->app->singleton(AudioDecodableLengthProbe::class, fn ($app): AudioDecodableLengthProbe => new AudioDecodableLengthProbe(
            $app->make(MediaTools::class),
        ));

        $this->app->singleton(AudioFetcher::class, fn ($app): AudioFetcher => new AudioFetcher(
            $app->make(AudioProcessingConfiguration::class),
            $app->make(UsenetDownloadService::class),
            $app->make(ArchiveExtractionService::class),
            $app->make(MediaTools::class),
            $app->make(AudioDecodableLengthProbe::class),
        ));

        $this->app->singleton(AudioPreviewEncoder::class, fn ($app): AudioPreviewEncoder => new AudioPreviewEncoder(
            $app->make(AudioProcessingConfiguration::class),
            $app->make(MediaTools::class),
        ));

        $this->app->singleton(AudioTagRenamer::class, fn ($app): AudioTagRenamer => new AudioTagRenamer(
            $app->make(AudioProcessingConfiguration::class),
            new CategorizationService,
            $app->make(ReleaseSearchSyncCoordinator::class),
            $app->make(PreviewGenerationPolicy::class),
        ));

        $this->app->singleton(AudioReleaseProcessor::class, fn ($app): AudioReleaseProcessor => new AudioReleaseProcessor(
            $app->make(NzbContentParser::class),
            $app->make(AudioSourceSelector::class),
            $app->make(AudioFetcher::class),
            $app->make(AudioPreviewEncoder::class),
            new AudioTagExtractor,
            $app->make(AudioTagRenamer::class),
            $app->make(ReleaseExtraService::class),
            $app->make(MediaInfoRefinementService::class),
            $app->make(ReleaseSearchSyncCoordinator::class),
            $app->make(PreviewGenerationPolicy::class),
        ));

        $this->app->singleton(AudioProcessingOrchestrator::class, fn ($app): AudioProcessingOrchestrator => new AudioProcessingOrchestrator(
            $app->make(AudioProcessingConfiguration::class),
            $app->make(AudioReleaseProcessor::class),
            $app->make(TempWorkspaceService::class),
        ));
    }
}
