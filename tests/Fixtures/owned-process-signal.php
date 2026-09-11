<?php

declare(strict_types=1);

use App\Services\Runners\OwnedProcess;

require dirname(__DIR__, 2).'/vendor/autoload.php';

function writeReadyFile(string $path, string $contents): void
{
    file_put_contents($path.'.pending', $contents);
    rename($path.'.pending', $path);
}

$mode = $argv[1];
$readyPath = $argv[2];

if ($mode === 'worker') {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, SIG_IGN);
    $child = pcntl_fork();
    if ($child < 0) {
        exit(92);
    }
    if ($child === 0) {
        posix_setsid();
        writeReadyFile($readyPath.'.child', (string) getmypid());
        while (true) {
            usleep(10000);
        }
    }
    $deadline = microtime(true) + 5;
    while (! is_file($readyPath.'.child')) {
        if (microtime(true) >= $deadline) {
            exit(90);
        }
        usleep(1000);
    }
    writeReadyFile($readyPath, json_encode([getmypid(), $child], JSON_THROW_ON_ERROR));
    while (true) {
        usleep(10000);
    }
}

$processes = [];
$pids = [];
foreach ([0, 1] as $index) {
    $path = $readyPath.'.'.$index;
    $process = new OwnedProcess([PHP_BINARY, __FILE__, 'worker', $path]);
    $process->setTimeout(20);
    $process->start();
    $processes[] = $process;
    $deadline = microtime(true) + 5;
    while (! is_file($path)) {
        if (microtime(true) >= $deadline) {
            exit(91);
        }
        usleep(1000);
    }
    $pids = [...$pids, ...json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)];
}

if ($mode === 'fork-owner') {
    $child = pcntl_fork();
    if ($child < 0) {
        exit(93);
    }
    if ($child === 0) {
        $processes[0]->stop(0);
        $own = new OwnedProcess([PHP_BINARY, '-r', 'sleep(20);']);
        $own->start();
        writeReadyFile($readyPath.'.fork', json_encode([getmypid(), $own->getPid()], JSON_THROW_ON_ERROR));
        while (true) {
            usleep(10000);
        }
    }
}

writeReadyFile($readyPath, json_encode($pids, JSON_THROW_ON_ERROR));
if ($mode === 'stopping-owner') {
    $processes[0]->stop(0.2);
}
while (true) {
    usleep(10000);
}
