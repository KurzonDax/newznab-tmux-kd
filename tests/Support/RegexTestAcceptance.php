<?php

declare(strict_types=1);

use App\Models\Release;
use App\Services\RegexService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require __DIR__.'/../../vendor/autoload.php';

// Explicit acceptance only: agent-sail php tests/Support/RegexTestAcceptance.php COUNT MODE.
$count = (int) ($argv[1] ?? 100000);
$mode = $argv[2] ?? 'matches';
if (! in_array($count, [100000, 500000], true) || ! in_array($mode, ['matches', 'no-match', 'collection'], true)) {
    throw new InvalidArgumentException('Use 100000 or 500000 and matches, no-match, or collection.');
}
$database = tempnam(sys_get_temp_dir(), 'regex-acceptance-');
if ($database === false) {
    throw new RuntimeException('Could not create the acceptance database.');
}

try {
    $pdo = new PDO('sqlite:'.$database);
    $pdo->exec('CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT)');
    $pdo->exec("INSERT INTO settings VALUES ('categorizeforeign', '0'), ('catwebdl', '0')");
    $pdo->exec('CREATE TABLE usenet_groups (id INTEGER PRIMARY KEY, name TEXT)');
    $pdo->exec("INSERT INTO usenet_groups VALUES (1, 'alt.binaries.example'), (2, 'alt.binaries.sentinel')");
    $pdo->exec('CREATE TABLE releases (id INTEGER PRIMARY KEY, groups_id INTEGER, name TEXT, searchname TEXT)');
    $pdo->exec('CREATE INDEX release_group_id ON releases (groups_id, id)');
    $pdo->exec('CREATE TABLE collections (id INTEGER PRIMARY KEY, groups_id INTEGER, fromname TEXT, collectionhash BLOB)');
    $pdo->exec("INSERT INTO collections VALUES (1, 1, 'poster', 'old'), (2, 2, 'sentinel', 'old')");
    $pdo->exec('CREATE TABLE binaries (id INTEGER PRIMARY KEY, collections_id INTEGER, name TEXT, totalparts INTEGER, currentparts INTEGER, binaryhash BLOB)');
    $pdo->beginTransaction();
    if ($mode === 'collection') {
        $insert = $pdo->prepare('INSERT INTO binaries VALUES (?, ?, ?, 1, 1, ?)');
        for ($id = 1; $id <= 100100; $id++) {
            $insert->execute([$id, $id <= 100 ? 1 : 2, $id <= 100 ? 'Café' : "\xff", 'same-hash']);
        }
    } else {
        $insert = $pdo->prepare('INSERT INTO releases VALUES (?, 1, ?, ?)');
        for ($id = 1; $id <= $count; $id++) {
            $insert->execute([$id, $id % 200 === 0 ? 'Café' : 'unmatched', 'Original']);
        }
    }
    $pdo->commit();
    unset($insert, $pdo);
    foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database,
        'CACHE_STORE' => 'array', 'LOG_CHANNEL' => 'stderr'] as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
    $app = require __DIR__.'/../../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    DB::disableQueryLog();
    $batches = 0;
    DB::listen(function (QueryExecuted $query) use (&$batches): void {
        if (! str_starts_with(strtolower($query->sql), 'select')) {
            throw new RuntimeException('A regex test attempted to mutate data.');
        }
        if (str_contains($query->sql, '"releases"') || str_contains($query->sql, '"binaries"')) {
            if (! preg_match('/limit (\d+)$/', $query->sql, $match) || (int) $match[1] > 500) {
                throw new RuntimeException('Unbounded candidate query: '.$query->sql);
            }
            $batches++;
        }
    });
    Event::listen('eloquent.retrieved: '.Release::class, function (): never {
        throw new RuntimeException('A regex test hydrated a Release model.');
    });
    $start = microtime(true);
    memory_reset_peak_usage();
    if ($mode === 'collection') {
        $result = (new RegexService('collection_regexes'))->testCollectionRegex('alt.binaries.example', '/(?<name>Café)/u', 50);
        if ($result['tested'] !== 50 || $result['matched'] !== 50 || array_column($result['rows'], 'binaryID') !== range(1, 50) || $batches !== 1) {
            throw new RuntimeException('Collection group isolation or candidate limit failed.');
        }
    } else {
        $service = new RegexService('release_naming_regexes');
        $pattern = $mode === 'matches' ? '/(?<name>Café)/u' : '/(?<name>never)/u';
        $result = $service->testReleaseNamingRegex('alt.binaries.example', $pattern, 1000, $count);
        $expected = $mode === 'matches' ? min(1000, intdiv($count, 200)) : 0;
        if (count($result['rows']) !== $expected || $result['tested'] !== ($mode === 'matches' ? min($count, 200000) : $count)) {
            throw new RuntimeException('Large naming scan returned incorrect counts.');
        }
        if ($mode === 'matches' && array_column($result['rows'], 'releaseID') !== range(200, $expected * 200, 200)) {
            throw new RuntimeException('Large naming scan lost release order or duplicate names.');
        }
        if ($count === 100000 && $mode === 'matches') {
            $limited = $service->testReleaseNamingRegex('alt.binaries.example', $pattern, 250, $count);
            if ($limited['tested'] !== 50000 || count($limited['rows']) !== 250 || $limited['stopReason'] !== 'result_limit') {
                throw new RuntimeException('The 250-result acceptance fixture failed.');
            }
            $limited = $service->testReleaseNamingRegex('alt.binaries.example', $pattern, 250, 10000);
            if ($limited['tested'] !== 10000 || count($limited['rows']) !== 50 || $limited['stopReason'] !== 'candidate_limit') {
                throw new RuntimeException('The 10000-candidate acceptance fixture failed.');
            }
        }
    }
    $peak = memory_get_peak_usage(true);
    if ($peak >= 128 * 1024 * 1024) {
        throw new RuntimeException('Regex test exceeded the 128 MiB memory budget.');
    }
    echo json_encode(['mode' => $mode, 'candidates' => $count, 'tested' => $result['tested'], 'matched' => count($result['rows']),
        'peak_bytes' => $peak, 'batches' => $batches, 'scan_seconds' => round(microtime(true) - $start, 3)], JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    if (isset($app)) {
        DB::disconnect();
    }
    unset($insert, $pdo);
    unlink($database);
}
