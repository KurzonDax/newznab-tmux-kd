<?php

declare(strict_types=1);

use App\Providers\CanaryBoostServiceProvider;
use App\Support\CanaryBoostPolicy;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Symfony\Component\Console\Input\ArgvInput;

define('LARAVEL_START', microtime(true));

$root = dirname(__DIR__);
chdir($root);

if (! is_file($root.'/vendor/autoload.php') || ! is_file($root.'/vendor/laravel/boost/src/BoostServiceProvider.php')) {
    fwrite(STDERR, "Boost canary requires installed Composer dependencies including laravel/boost. Prepare them in the development/release environment; startup installs nothing.\n");
    exit(2);
}

require $root.'/vendor/autoload.php';

try {
    CanaryBoostPolicy::validateInvocation($root, array_slice($argv, 1));
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(2);
}

/** @var Application $app */
$app = require $root.'/bootstrap/app.php';
// Suppress the ordinary provider's browser integration even with cached permissive settings.
$app->afterBootstrapping(LoadConfiguration::class, function (Application $app): void {
    $app->make('config')->set('boost.enabled', false);
});
$app->make(Kernel::class)->bootstrap();
$app->register(CanaryBoostServiceProvider::class);

exit($app->handleCommand(new ArgvInput));
