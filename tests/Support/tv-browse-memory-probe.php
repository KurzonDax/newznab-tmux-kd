<?php

declare(strict_types=1);

use App\Data\ReleaseBrowserState;
use App\Enums\BrowseRoot;
use App\Models\User;
use App\Services\Releases\TvEpisodeBrowser;
use App\Services\Releases\TvShowDirectory;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$database = $argv[1];
if (! preg_match('/^tv_browse_[a-f0-9]{16}$/', $database)) {
    throw new RuntimeException('Expected a disposable TV browse database.');
}
foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mariadb', 'DB_HOST' => 'mariadb', 'DB_DATABASE' => $database, 'DB_USERNAME' => 'root', 'DB_PASSWORD' => 'password', 'CACHE_STORE' => 'array', 'LOG_CHANNEL' => 'stderr'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
DB::disableQueryLog();
$maxBindings = 0;
$maxSqlBytes = 0;
DB::listen(static function (QueryExecuted $query) use (&$maxBindings, &$maxSqlBytes): void {
    $maxBindings = max($maxBindings, count($query->bindings));
    $maxSqlBytes = max($maxSqlBytes, strlen($query->sql));
});
$user = new User;
$user->id = 1;
$user->categoryexclusions = [];
if ($argv[2] === 'matrix') {
    $sorts = [];
    $state = null;
    foreach (['posted', 'posted_oldest', 'newest', 'oldest', 'grabs', 'title', 'trending'] as $sort) {
        $state = new ReleaseBrowserState(BrowseRoot::Tv, 'covers', 'medium', 48, true, 1, '', '', null, '', $sort === 'trending' ? 'grabs' : $sort, [], false, false, 0, trending: $sort === 'trending');
        $covers = app(TvEpisodeBrowser::class)->paginate($state, $user);
        $tiles = [];
        foreach ($covers as $cover) {
            $tiles[$cover->id] = ['count' => $cover->releaseCount, 'releases' => array_column($cover->releases, 'id')];
        }
        $sorts[$sort] = ['total' => $covers->total, 'ids' => $covers->map(static fn ($cover): int => (int) $cover->id)->all(), 'tiles' => $tiles];
    }
    $expansions = [];
    foreach ([1, 2, 3, 4, 11] as $episode) {
        $expansions[$episode] = app(TvEpisodeBrowser::class)->releases($state, $user, $episode)->pluck('id')->all();
    }
    $directory = app(TvShowDirectory::class);
    $dialog = [];
    foreach (['episode', 'packs', 'season'] as $kind) {
        $result = $directory->show(new Request(['season' => 2, 'kind' => $kind, 'episode' => 1]), $user, 1);
        $dialog['dialog_'.$kind] = $kind === 'season' ? $result['results']->pluck('release_count', 'id')->all() : $result['results']->pluck('id')->all();
    }
    $emptyPack = $directory->show(new Request(['season' => 9, 'kind' => 'packs']), $user, 1);
    $watchQueries = [];
    DB::listen(static function (QueryExecuted $query) use (&$watchQueries): void {
        if (str_contains($query->sql, 'user_series')) {
            $watchQueries[] = ['sql' => $query->sql, 'bindings' => count($query->bindings)];
        }
    });
    $listing = $directory->directory(new Request, $user, '');
    $abortOnce = true;
    $temporaryNames = [];
    DB::listen(static function (QueryExecuted $query) use (&$abortOnce, &$temporaryNames): void {
        if ($abortOnce && preg_match('/insert into `(tv_declarations_([a-f0-9]+))`/', $query->sql, $match)) {
            $abortOnce = false;
            $temporaryNames = [$match[1], 'tv_memberships_'.$match[2]];
            throw new RuntimeException('Fixture interrupted the request.');
        }
    });
    $interrupted = false;
    try {
        app(TvEpisodeBrowser::class)->paginate($state, $user);
    } catch (RuntimeException $exception) {
        $interrupted = $exception->getMessage() === 'Fixture interrupted the request.';
    }
    $missingTables = 0;
    foreach ($temporaryNames as $name) {
        try {
            DB::connection()->getPdo()->query('SELECT 1 FROM `'.$name.'` LIMIT 1');
        } catch (PDOException $exception) {
            if (($exception->errorInfo[1] ?? 0) === 1146) {
                $missingTables++;
            }
        }
    }
    $connectionId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
    $killOnce = true;
    DB::listen(static function (QueryExecuted $query) use (&$killOnce, $connectionId): void {
        if ($killOnce && str_contains($query->sql, 'insert into `tv_declarations_')) {
            $killOnce = false;
            $admin = new PDO('mysql:host=mariadb', 'root', 'password');
            $admin->exec('KILL CONNECTION '.$connectionId);
        }
    });
    $connectionFailed = false;
    try {
        app(TvEpisodeBrowser::class)->paginate($state, $user);
    } catch (Throwable $exception) {
        $connectionFailed = str_contains($exception->getMessage(), 'server has gone away') || str_contains($exception->getMessage(), 'Lost connection');
    }
    echo json_encode(['sorts' => $sorts, 'expansions' => $expansions, ...$dialog,
        'empty_pack_count' => $emptyPack['packCount'], 'watched' => $listing['shows']->pluck('watched', 'id')->all(), 'watch_queries' => $watchQueries, 'interrupted' => $interrupted, 'missing_tables' => $missingTables, 'connection_failed' => $connectionFailed], JSON_THROW_ON_ERROR);
    exit(0);
}
$state = new ReleaseBrowserState(BrowseRoot::Tv, 'covers', 'medium', 48, true, (int) $argv[2], '', '', null, '', 'posted_oldest', [], false, false, 0);
$pages = [];
Cache::flush();
for ($run = 0; $run < 2; $run++) {
    $results = app(TvEpisodeBrowser::class)->paginate($state, $user);
    $pages[] = ['total' => $results->total, 'ids' => $results->map(static fn ($cover): int => (int) $cover->id)->all(), 'counts' => $results->pluck('releaseCount')->all()];
    unset($results);
}
$cacheBytes = strlen(serialize(Cache::getStore()));
echo json_encode(['pages' => $pages, 'peak' => memory_get_peak_usage(true), 'max_bindings' => $maxBindings, 'max_sql_bytes' => $maxSqlBytes, 'cache_bytes' => $cacheBytes], JSON_THROW_ON_ERROR);
