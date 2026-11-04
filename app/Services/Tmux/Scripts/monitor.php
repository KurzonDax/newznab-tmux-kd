#!/usr/bin/env php
<?php

/**
 * Tmux Monitor Script
 *
 * This script runs in tmux pane 0.0 and continuously monitors the system,
 * collecting statistics and spawning tasks in other panes.
 *
 * This is a simple wrapper that calls the tmux:monitor artisan command.
 * The monitor inherits the terminal directly because a passthru() relay holds
 * back the end of any frame longer than 4,096 bytes.
 */
$artisan = escapeshellarg(dirname(__DIR__, 4).'/artisan');

// Pass any arguments to the artisan command
$args = array_slice($argv ?? [], 1);
$argString = implode(' ', array_map('escapeshellarg', $args));

$process = proc_open("php {$artisan} tmux:monitor {$argString}", [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
if (! is_resource($process)) {
    exit(1);
}

exit(proc_close($process));
