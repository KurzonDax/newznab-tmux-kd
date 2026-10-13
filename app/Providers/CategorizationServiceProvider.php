<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Categorization\Arbitration\CategoryArbiter;
use App\Services\Categorization\Arbitration\CategoryArbitrationUseCase;
use App\Services\Categorization\CategorizationPipeline;
use Illuminate\Support\ServiceProvider;

class CategorizationServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register the pipeline as a singleton
        $this->app->singleton(CategorizationPipeline::class, function ($app) {
            return CategorizationPipeline::createDefault();
        });

        // Alias for easier access
        $this->app->alias(CategorizationPipeline::class, 'categorization');

        // Shared per process: the arbiter keeps the settings it read for a minute,
        // and the use case keeps the criteria file it parsed.
        $this->app->singleton(CategoryArbiter::class);
        $this->app->singleton(CategoryArbitrationUseCase::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
