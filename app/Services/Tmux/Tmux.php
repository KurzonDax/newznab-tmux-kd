<?php

declare(strict_types=1);

namespace App\Services\Tmux;

use App\Models\Category;
use App\Models\Settings;
use App\Services\NameFixing\NameFixingService;
use App\Services\NfoService;
use App\Services\NNTP\NntpProviderPool;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Class Tmux.
 */
class Tmux
{
    /** How long a socket listing may be reused before another `ss` is worth the cost. */
    private const float SOCKET_SNAPSHOT_TTL_SECONDS = 1.0;

    private ?string $socketSnapshot = null;

    private float $socketSnapshotTakenAt = 0.0;

    /**
     * @var \PDO
     */
    public \Closure|\PDO $pdo;

    public mixed $tmux_session;

    /**
     * Tmux constructor.
     */
    public function __construct()
    {
        $this->pdo = DB::connection()->getPdo();
    }

    /**
     * One row per configured NNTP provider, in position order, for the monitor display.
     *
     * @return list<array{name: string, host: string, port: int, ip: string, enabled: bool}>
     */
    public function getConnectionsInfo(): array
    {
        $connections = [];

        foreach (NntpProviderPool::configuredProviders() as $provider) {
            $connections[] = [
                'name' => $provider->name,
                'host' => $provider->host,
                'port' => $provider->port,
                'ip' => gethostbyname($provider->host),
                'enabled' => $provider->enabled,
            ];
        }

        return $connections;
    }

    /**
     * Count the sockets currently open to one host:port.
     *
     * @return array{active: int, total: int}
     */
    public function getProviderSocketCounts(string $ip, string|int $port, ?string $socketSnapshot = null): array
    {
        $ip = trim($ip);
        $port = (string) $port;
        // Every needle is anchored on the provider's IP. Matching a bare port would count
        // another provider's sockets as this one's -- backbones commonly share port 119/563.
        $needles = array_values(array_filter([
            $ip !== '' && $port !== '' ? $ip.':'.$port : null,
            $ip !== '' ? $ip.':https' : null,
            $ip !== '' ? $ip : null,
        ]));
        $lines = preg_split('/\R/', $socketSnapshot ?? $this->getSocketSnapshot()) ?: [];

        foreach ($needles as $needle) {
            $matchingLines = array_filter(
                $lines,
                static fn (string $line): bool => str_contains($line, $needle),
            );

            if ($matchingLines !== []) {
                return [
                    'active' => count(array_filter(
                        $matchingLines,
                        static fn (string $line): bool => str_contains($line, 'ESTAB'),
                    )),
                    'total' => count($matchingLines),
                ];
            }
        }

        return ['active' => 0, 'total' => 0];
    }

    /**
     * One `ss` listing of the host's sockets.
     *
     * Memoised for a second: `doConnect()` asks for this on every connect, and shelling out
     * per connection was a measurable share of the cost of opening one. Callers that need a
     * genuinely current reading -- the monitor's refresh -- ask for a fresh one.
     */
    public function getSocketSnapshot(bool $fresh = false): string
    {
        $now = microtime(true);

        if ($fresh || $this->socketSnapshot === null || ($now - $this->socketSnapshotTakenAt) > self::SOCKET_SNAPSHOT_TTL_SECONDS) {
            $this->socketSnapshot = (string) shell_exec('ss -nH 2>/dev/null');
            $this->socketSnapshotTakenAt = $now;
        }

        return $this->socketSnapshot;
    }

    /**
     * @return array<string, mixed>
     */
    public function getListOfPanes(mixed $constants): array
    {
        $panes = ['zero' => '', 'one' => '', 'two' => ''];
        switch ($constants['sequential']) {
            case 0:
            case 1:
                $panes_win_1 = shell_exec("echo `tmux list-panes -t {$constants['tmux_session']}:0 -F '#{pane_title}'`");
                $panes['zero'] = str_replace("\n", '', explode(' ', $panes_win_1));
                $panes_win_2 = shell_exec("echo `tmux list-panes -t {$constants['tmux_session']}:1 -F '#{pane_title}'`");
                $panes['one'] = str_replace("\n", '', explode(' ', $panes_win_2));
                $panes_win_3 = shell_exec("echo `tmux list-panes -t {$constants['tmux_session']}:2 -F '#{pane_title}'`");
                $panes['two'] = str_replace("\n", '', explode(' ', $panes_win_3));
                break;
            case 2:
                $panes_win_1 = shell_exec("echo `tmux list-panes -t {$constants['tmux_session']}:0 -F '#{pane_title}'`");
                $panes['zero'] = str_replace("\n", '', explode(' ', $panes_win_1));
                $panes_win_2 = shell_exec("echo `tmux list-panes -t {$constants['tmux_session']}:1 -F '#{pane_title}'`");
                $panes['one'] = str_replace("\n", '', explode(' ', $panes_win_2));
                break;
        }

        return $panes;
    }

    /**
     * @return array<string, mixed>
     */
    public function getConstantSettings(): array
    {
        $settings = [
            'sequential',
            'tmux_session',
            'run_ircscraper',
            'delaytime',
        ];

        $constants = Settings::query()
            ->whereIn('name', $settings)
            ->get()
            ->mapWithKeys(function ($item) {
                return [$item->name => Settings::convertValue($item->getRawOriginal('value'))];
            })
            ->toArray();

        return $constants;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMonitorSettings(): array
    {
        $settingsMap = [
            'monitor_delay' => 'monitor',
            'binaries' => 'binaries_run',
            'backfill' => 'backfill',
            'backfill_qty' => 'backfill_qty',
            'nzbs' => 'nzbs',
            'post' => 'post',
            'releases' => 'releases_run',
            'fix_names' => 'fix_names',
            'seq_timer' => 'seq_timer',
            'bins_timer' => 'bins_timer',
            'back_timer' => 'back_timer',
            'rel_timer' => 'rel_timer',
            'fix_timer' => 'fix_timer',
            'fix_names_timeout' => 'fix_names_timeout',
            'post_timer' => 'post_timer',
            'collections_kill' => 'collections_kill',
            'postprocess_kill' => 'postprocess_kill',
            'crap_timer' => 'crap_timer',
            'fix_crap' => 'fix_crap',
            'fix_crap_opt' => 'fix_crap_opt',
            'post_kill_timer' => 'post_kill_timer',
            'monitor_path' => 'monitor_path',
            'monitor_path_a' => 'monitor_path_a',
            'monitor_path_b' => 'monitor_path_b',
            'progressive' => 'progressive',
            'backfill_days' => 'backfilldays',
            'post_amazon' => 'post_amazon',
            'post_timer_amazon' => 'post_timer_amazon',
            'post_non' => 'post_non',
            'post_timer_non' => 'post_timer_non',
            'colors_start' => 'colors_start',
            'colors_end' => 'colors_end',
            'colors_exc' => 'colors_exc',
            'showquery' => 'show_query',
            'running' => 'is_running',
            'lookupbooks' => 'processbooks',
            'lookupmusic' => 'processmusic',
            'lookupgames' => 'processgames',
            'lookupimdb' => 'processmovies',
            'lookuptv' => 'processtvrage',
            'lookupanidb' => 'processanime',
            'lookupnfo' => 'processnfo',
            'lookuppar2' => 'processpar2',
            'nzbthreads' => 'nzbthreads',
            'maxsizetopostprocess' => 'maxsize_pp',
            'minsizetopostprocess' => 'minsize_pp',
        ];

        return Settings::query()
            ->whereIn('name', array_keys($settingsMap))
            ->get()
            ->mapWithKeys(function ($item) use ($settingsMap) {
                return [$settingsMap[$item->name] => Settings::convertValue($item->getRawOriginal('value'))];
            })
            ->toArray();
    }

    public function updateItem(mixed $setting, mixed $value): int
    {
        return Settings::query()->where('name', '=', $setting)->update(['value' => $value]);
    }

    public function microtime_float(): float
    {
        [$usec, $sec] = explode(' ', microtime());

        return (float) $usec + (float) $sec;
    }

    public function decodeSize(int|float $bytes): string
    {
        // Handle zero case
        if ($bytes === 0) {
            return '0 B';
        }

        // Handle negative values
        if ($bytes < 0) {
            return '-'.$this->decodeSize(abs($bytes));
        }

        // Add more size units (PB, EB)
        $types = ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB'];
        $index = 0;

        // Use while loop instead of foreach for more efficiency
        while ($bytes >= 1024.0 && $index < count($types) - 1) {
            $bytes /= 1024.0;
            $index++;
        }

        return round($bytes, 2).' '.$types[$index];
    }

    public function writelog(mixed $pane): ?string
    {
        $path = storage_path('logs');
        $getDate = now()->format('Y_m_d');
        $logs = Settings::settingValue('write_logs') ?? 0;
        if ($logs === 1) {
            return "2>&1 | tee -a $path/$pane-$getDate.log";
        }

        return '';
    }

    /**
     * @throws \Exception
     */
    public function get_color(mixed $colors_start, mixed $colors_end, mixed $colors_exc): int
    {
        // Handle null values with sensible defaults
        $colors_start = $colors_start ?? 0;
        $colors_end = $colors_end ?? 255;
        $colors_exc = $colors_exc ?? '';

        $exception = str_replace('.', '.', $colors_exc);
        $exceptions = explode(',', $exception);
        sort($exceptions);
        $number = random_int($colors_start, $colors_end - \count($exceptions));
        foreach ($exceptions as $exception) {
            if ($number >= $exception) {
                $number++;
            } else {
                break;
            }
        }

        return $number;
    }

    public function relativeTime(mixed $_time): string
    {
        return Carbon::createFromTimestamp($_time, date_default_timezone_get())->ago();
    }

    /**
     * @throws \Exception
     */
    public function proc_query(mixed $qry, string $db_name, ?string $ppmax = '', ?string $ppmin = ''): bool|string
    {
        // Convert null to empty string for backward compatibility
        $ppmax = $ppmax ?? '';
        $ppmin = $ppmin ?? '';

        switch ((int) $qry) {
            case 1:
                // NOTE: `processrenames` is deliberately absent here. A hand-written
                // copy of part of the standard sweep's predicate slept the Fix Names
                // pane on real work; the count now comes from
                // \App\Services\NameFixing\NameFixingQueryService::standardCandidateCount()
                // via TmuxMonitorService::getProcessCounts().
                $movieLookupSql = imdb_id_needs_lookup_sql('imdbid');
                $lookupMovies = (int) Settings::settingValue('lookupimdb');

                if ($lookupMovies <= 0) {
                    $movieLookupSql = '0 = 1';
                } elseif ($lookupMovies === 2) {
                    $movieLookupSql .= ' AND isrenamed = 1';
                }

                return sprintf(
                    '
					SELECT
					SUM(IF(categories_id BETWEEN %d AND %d AND categories_id != %d AND videos_id = 0 AND tv_episodes_id BETWEEN -3 AND 0 AND size > 1048576,1,0)) AS processtv,
					SUM(IF(categories_id = %d AND anidbid IS NULL,1,0)) AS processanime,
                      SUM(IF(categories_id BETWEEN %d AND %d AND '.$movieLookupSql.',1,0)) AS processmovies,
					SUM(IF(categories_id IN (%d, %d, %d) AND musicinfo_id IS NULL,1,0)) AS processmusic,
					SUM(IF(categories_id BETWEEN %d AND %d AND consoleinfo_id IS NULL,1,0)) AS processconsole,
					SUM(IF(categories_id BETWEEN %d AND %d AND bookinfo_id IS NULL,1,0)) AS processbooks,
					SUM(IF(categories_id = %d AND gamesinfo_id = 0,1,0)) AS processgames,
					SUM(IF(1=1 %s,1,0)) AS processnfo,
					SUM(IF(isrenamed = %d,1,0)) AS renamed,
          SUM(IF(nfostatus = %d,1,0)) AS nfo,
					SUM(IF(predb_id > 0,1,0)) AS predb_matched,
					COUNT(DISTINCT(predb_id)) AS distinct_predb_matched
					FROM releases r',
                    Category::TV_ROOT,
                    Category::TV_OTHER,
                    Category::TV_ANIME,
                    Category::TV_ANIME,
                    Category::MOVIE_ROOT,
                    Category::MOVIE_OTHER,
                    Category::MUSIC_MP3,
                    Category::MUSIC_LOSSLESS,
                    Category::MUSIC_OTHER,
                    Category::GAME_ROOT,
                    Category::GAME_OTHER,
                    Category::BOOKS_ROOT,
                    Category::BOOKS_UNKNOWN,
                    Category::PC_GAMES,
                    NfoService::NfoQueryString(),
                    NameFixingService::IS_RENAMED_DONE,
                    NfoService::NFO_FOUND
                );

            case 2:
                // NOTE: the "Misc In Process" / `work` count was previously computed here
                // and repeatedly drifted out of sync with the actual additional
                // post-processor selection in
                // \App\Services\AdditionalProcessing\AdditionalCandidateQuery::applyPredicates()
                // (typical drift: a missing `nzbstatus = 1` filter, which leaves
                // NZB-less releases stuck in the queue forever). It now lives in
                // \App\Services\Tmux\TmuxMonitorService::collectProcessCounts(), which
                // calls AdditionalCandidateQuery directly. The $ppmax / $ppmin args
                // are kept for backward compatibility with the public signature but
                // are no longer used by this case.
                unset($ppmax, $ppmin);

                return 'SELECT
					(SELECT COUNT(id) FROM usenet_groups WHERE active = 1) AS active_groups,
					(SELECT COUNT(id) FROM usenet_groups WHERE name IS NOT NULL) AS all_groups';

            case 4:
                return sprintf(
                    "
					SELECT
					(SELECT TABLE_ROWS FROM information_schema.TABLES WHERE table_name = 'predb' AND TABLE_SCHEMA = %1\$s) AS predb,
					(SELECT COUNT(id) FROM usenet_groups WHERE first_record IS NOT NULL AND backfill = 1
						AND (now() - INTERVAL backfill_target DAY) < first_record_postdate
					) AS backfill_groups_days,
					(SELECT COUNT(id) FROM usenet_groups WHERE first_record IS NOT NULL AND backfill = 1 AND (now() - INTERVAL datediff(curdate(),
					(SELECT VALUE FROM settings WHERE name = 'safebackfilldate')) DAY) < first_record_postdate) AS backfill_groups_date",
                    escapeString($db_name)
                );
            case 6:
                return 'SELECT
					(SELECT searchname FROM releases ORDER BY id DESC LIMIT 1) AS newestrelname,
					(SELECT UNIX_TIMESTAMP(MIN(dateadded)) FROM collections) AS oldestcollection,
					(SELECT UNIX_TIMESTAMP(MAX(predate)) FROM predb) AS newestpre,
					(SELECT UNIX_TIMESTAMP(adddate) FROM releases ORDER BY id DESC LIMIT 1) AS newestrelease';
            default:
                return false;
        }
    }

    /**
     * @return bool true if tmux is running, false otherwise.
     *
     * @throws \RuntimeException
     */
    public function isRunning(): bool
    {
        $running = Settings::query()->where(['name' => 'running'])->first(['value']);
        if ($running === null) {
            throw new \RuntimeException('Tmux\\\'s running flag was not found in the database.'.PHP_EOL.'Please check the tables are correctly setup.'.PHP_EOL);
        }

        return ! ((int) $running->value === 0);
    }

    /**
     * @throws \Exception
     */
    public function stopIfRunning(): bool
    {
        if ($this->isRunning()) {
            Settings::query()->where(['name' => 'running'])->update(['value' => 0]);
            $sleep = Settings::settingValue('monitor_delay');
            cli()->header('Stopping tmux scripts and waiting '.$sleep.' seconds for all panes to shutdown');
            sleep($sleep);

            return true;
        }
        cli()->info('Tmux scripts are not running!');

        return false;
    }

    /**
     * @throws \RuntimeException
     */
    public function startRunning(): void
    {
        if (! $this->isRunning()) {
            Settings::query()->where(['name' => 'running'])->update(['value' => 1]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function cbpmTableQuery(): array
    {
        return DB::select(
            "
			SELECT TABLE_NAME AS name, TABLE_ROWS AS row_count
      		FROM information_schema.TABLES
      		WHERE TABLE_SCHEMA = (SELECT DATABASE())
			AND TABLE_NAME REGEXP {escapeString('^(multigroup_)?(collections|binaries|parts|missed_parts)(_[0-9]+)?$')}
			ORDER BY TABLE_NAME ASC"
        );
    }
}
