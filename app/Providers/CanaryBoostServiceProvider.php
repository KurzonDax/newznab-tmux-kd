<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\CanaryBoostPolicy;
use App\Support\CanaryBoostServer;
use App\Support\CanaryBoostToolExecutor;
use Illuminate\Routing\Router;
use Laravel\Boost\BoostServiceProvider;
use Laravel\Boost\Console\ExecuteToolCommand;
use Laravel\Boost\Mcp\ToolExecutor;
use Laravel\Boost\Mcp\ToolRegistry;
use Laravel\Mcp\Facades\Mcp;

/** Registered only by the dedicated diagnostic CLI, after deployed configuration loads. */
final class CanaryBoostServiceProvider extends BoostServiceProvider
{
    public function register(): void
    {
        parent::register();
        $excluded = [];
        foreach (glob(base_path('vendor/laravel/boost/src/Mcp/Tools/*.php')) ?: [] as $path) {
            $class = 'Laravel\\Boost\\Mcp\\Tools\\'.basename($path, '.php');
            if (! in_array($class, CanaryBoostPolicy::TOOLS, true)) {
                $excluded[] = $class;
            }
        }
        $this->app->make('config')->set('boost.mcp.tools', [
            'include' => CanaryBoostPolicy::TOOLS,
            'exclude' => $excluded,
        ]);
        ToolRegistry::clearCache();
        $this->app->bind(ToolExecutor::class, CanaryBoostToolExecutor::class);
    }

    public function boot(Router $router): void
    {
        Mcp::local('laravel-boost', CanaryBoostServer::class);
        $this->commands([ExecuteToolCommand::class]);
    }

    protected function shouldRun(): bool
    {
        return $this->app->runningInConsole();
    }
}
