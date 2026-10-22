<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Categorization\Arbitration\CategoryArbitrationUseCase;
use App\Services\DecisionModel\DecisionModelClient;
use App\Services\DecisionModel\DecisionRunner;
use App\Services\DecisionModel\DecisionUseCaseRegistry;
use App\Services\MusicIdentity\DecisionModel\MusicAlbumCheck;
use App\Services\MusicIdentity\DecisionModel\MusicAlbumCheckUseCase;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class DecisionModelServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Shared per process, because the client carries the circuit breaker.
        $this->app->singleton(DecisionModelClient::class);
        $this->app->singleton(DecisionRunner::class);

        // Shared so the registry and the music pass's catch-up read one criteria file.
        $this->app->singleton(MusicAlbumCheckUseCase::class);
        $this->app->singleton(MusicAlbumCheck::class);

        // A new use case is added to this list; nothing else changes.
        $this->app->singleton(DecisionUseCaseRegistry::class, static fn (Application $app): DecisionUseCaseRegistry => new DecisionUseCaseRegistry([
            $app->make(CategoryArbitrationUseCase::class),
            $app->make(MusicAlbumCheckUseCase::class),
        ]));
    }
}
