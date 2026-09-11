<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Input\ArgvInput;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
Artisan::command('fixture:owned-work {pids}', function (): void {
    $pid = pcntl_fork();
    if ($pid === 0) {
        sleep(30);
        exit;
    }
    $path = $this->argument('pids');
    $pids = json_decode(file_get_contents($path), true);
    file_put_contents($path, json_encode([...$pids, getmypid(), $pid]));
    sleep(30);
});
exit($app->handleCommand(new ArgvInput));
