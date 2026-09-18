<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PDO;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class TvBrowseMemoryMariaDbTest extends TestCase
{
    public function test_cover_pages_stay_below_128_mib_when_the_catalogue_doubles(): void
    {
        if (getenv('CBP_INTEGRATION_DB_DATABASE') !== 'cbp_integration') {
            $this->markTestSkipped('Requires the isolated Sail MariaDB runtime.');
        }
        $admin = $this->connect(null);
        $database = 'tv_browse_'.bin2hex(random_bytes(8));
        $admin->statement("CREATE DATABASE `{$database}`");
        try {
            $pdo = $this->connect($database)->getPdo();
            $this->schema($pdo);
            $peaks = [];
            foreach ([3000, 6000] as $shows) {
                $this->seedCatalogue($pdo, $shows === 3000 ? 1 : 3001, $shows);
                foreach ([1, 400] as $page) {
                    $process = new Process([PHP_BINARY, '-d', 'memory_limit=128M', __DIR__.'/../Support/tv-browse-memory-probe.php', $database, (string) $page]);
                    $process->setTimeout(300);
                    $process->run();
                    $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
                    $this->assertJson($process->getOutput(), $process->getOutput().$process->getErrorOutput());
                    $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                    foreach ($result['pages'] as $actual) {
                        $this->assertSame($shows * 10, $actual['total']);
                        $expected = [];
                        for ($offset = ($page - 1) * 48; $offset < $page * 48; $offset++) {
                            $expected[] = intdiv($offset, 10) * 100 + $offset % 10 + 1;
                        }
                        $this->assertSame($expected, $actual['ids']);
                        $this->assertSame(array_map(static fn (int $id): int => match ($id % 100) {
                            1 => 3, 2 => 2, default => 1
                        }, $expected), $actual['counts']);
                    }
                    $peaks[$shows][$page] = $result['peak'];
                    $this->assertLessThan(128 * 1024 * 1024, $result['peak']);
                    $this->assertLessThanOrEqual(4000, $result['max_bindings']);
                    $this->assertLessThanOrEqual(64000, $result['max_sql_bytes']);
                    $this->assertLessThanOrEqual(128 * 1024, $result['cache_bytes']);
                }
            }
            foreach ([1, 400] as $page) {
                $this->assertLessThanOrEqual(8 * 1024 * 1024, $peaks[6000][$page] - $peaks[3000][$page]);
            }
            fwrite(STDERR, 'TV browse peak bytes: '.json_encode($peaks).PHP_EOL);
        } finally {
            $admin->statement("DROP DATABASE `{$database}`");
        }
    }

    public function test_tv_b_membership_sorting_expansion_and_dialog_contracts(): void
    {
        if (getenv('CBP_INTEGRATION_DB_DATABASE') !== 'cbp_integration') {
            $this->markTestSkipped('Requires the isolated Sail MariaDB runtime.');
        }
        $admin = $this->connect(null);
        $database = 'tv_browse_'.bin2hex(random_bytes(8));
        $admin->statement("CREATE DATABASE `{$database}`");
        try {
            $pdo = $this->connect($database)->getPdo();
            $this->schema($pdo);
            $pdo->exec("INSERT INTO videos VALUES (1,'Same title','2020-01-01',0),(2,'Same title','2020-01-01',0)");
            $pdo->exec("INSERT INTO tv_episodes VALUES (1,1,2,1,'First'),(2,1,2,2,'Second'),(3,1,2,3,'Third'),(4,1,0,1,'Special'),(9,1,2,1,'Duplicate'),(11,2,2,1,'Other show'),(20,1,3,1,'Next season')");
            $releases = [
                [1, 1, 20, 'S02E01-E03', 1, 9, 3, 'Zulu'],
                [2, 1, 20, 'S02E01E03', 3, 7, 9, 'bravo'],
                [3, 1, 20, 'S02E01+03', 2, 8, 8, 'ALPHA'],
                [4, 1, 1, 'S02E03-E01', 9, 9, 99, 'Invalid reversed'],
                [5, 1, 1, 'S02E01-S03E01', 9, 9, 99, 'Invalid cross season'],
                [6, 1, 20, 'S02.COMPLETE', 5, 5, 6, 'echo'],
                [7, 1, 20, 'COMPLETE.S02', 4, 6, 7, 'delta'],
                [8, 1, 9, 'Unparseable', 6, 4, 1, 'zeta'],
                [9, 1, 11, 'Foreign link', 9, 9, 99, 'Invalid foreign'],
                [10, 1, 0, 'S00E01', 7, 3, 2, 'charlie'],
                [11, 2, 0, 'S02E01-E03', 8, 2, 4, 'foxtrot'],
                [12, 0, 1, 'S02E01', 9, 9, 99, 'Invalid zero'],
                [13, null, 1, 'S02E01', 9, 9, 99, 'Invalid null'],
                [14, 1, 0, 'S09.COMPLETE', 9, 9, 99, 'Empty season pack'],
            ];
            $insert = $pdo->prepare('INSERT INTO releases (id,videos_id,tv_episodes_id,searchname,postdate,adddate,grabs,display_name) VALUES (?,?,?,?,?,?,?,?)');
            foreach ($releases as [$id, $show, $link, $name, $posted, $added, $grabs, $display]) {
                $insert->execute([$id, $show, $link, $name, '2026-01-0'.$posted, '2026-02-0'.$added, $grabs, $display]);
            }
            foreach ([1 => 1, 8 => 4, 9 => 50, 10 => 5, 11 => 2] as $release => $count) {
                for ($grab = 0; $grab < $count; $grab++) {
                    $pdo->exec("INSERT INTO user_downloads VALUES ({$release},NOW())");
                }
            }
            $pdo->exec("INSERT INTO user_downloads VALUES (2,'2000-01-01')");
            $pdo->exec('INSERT INTO user_series VALUES (1,1),(2,2)');
            for ($show = 100; $show < 1100; $show++) {
                $pdo->exec("INSERT INTO user_series VALUES (1,{$show})");
            }
            $process = new Process([PHP_BINARY, '-d', 'memory_limit=128M', __DIR__.'/../Support/tv-browse-memory-probe.php', $database, 'matrix']);
            $process->setTimeout(120)->run();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $this->assertJson($process->getOutput(), $process->getOutput().$process->getErrorOutput());
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertTrue($result['interrupted']);
            $this->assertSame(2, $result['missing_tables']);
            $this->assertTrue($result['connection_failed']);
            $orders = ['posted' => [11, 4, 1, 2, 3], 'posted_oldest' => [1, 2, 3, 4, 11],
                'newest' => [1, 2, 3, 4, 11], 'oldest' => [11, 4, 1, 2, 3],
                'grabs' => [1, 3, 2, 11, 4], 'title' => [1, 3, 4, 2, 11], 'trending' => [1, 4, 11, 2, 3]];
            $members = [1 => [8, 6, 7, 2, 3, 1], 2 => [6, 7, 1], 3 => [6, 7, 2, 3, 1], 4 => [10], 11 => [11]];
            foreach ($orders as $sort => $order) {
                $this->assertSame($order, $result['sorts'][$sort]['ids'], $sort);
                $this->assertSame(5, $result['sorts'][$sort]['total']);
                foreach ($result['sorts'][$sort]['tiles'] as $id => $tile) {
                    $this->assertSame(count($members[$id]), $tile['count']);
                    $this->assertSame(array_slice($members[$id], 0, 2), $tile['releases']);
                }
            }
            $this->assertSame($members, $result['expansions']);
            $this->assertSame([8, 2, 3, 1], $result['dialog_episode']);
            $this->assertSame([6, 7], $result['dialog_packs']);
            $this->assertSame([1 => 4, 2 => 1, 3 => 3], $result['dialog_season']);
            $this->assertSame(1, $result['empty_pack_count']);
            $this->assertSame([1 => true, 2 => false], $result['watched']);
            $this->assertNotEmpty($result['watch_queries']);
            foreach ($result['watch_queries'] as $query) {
                $this->assertLessThanOrEqual(3, $query['bindings']);
                $this->assertStringContainsString(' in ', strtolower($query['sql']));
            }
        } finally {
            $admin->statement("DROP DATABASE `{$database}`");
        }
    }

    /** Laravel connections, so the schema guard inspects the fixture before its database is dropped. */
    private function connect(?string $database): Connection
    {
        $name = $database === null ? 'tv_browse_admin' : 'tv_browse';
        config(['database.connections.'.$name => ['driver' => 'mariadb', 'host' => 'mariadb', 'port' => 3306,
            'database' => $database, 'username' => 'root', 'password' => 'password', 'prefix' => '']]);

        return DB::connection($name);
    }

    private function schema(PDO $pdo): void
    {
        $statements = [
            'CREATE TABLE settings (name VARCHAR(255) PRIMARY KEY, value TEXT)',
            "INSERT INTO settings VALUES ('categorizeforeign','0'),('catwebdl','0'),('showpasswordedrelease','0')",
            'CREATE TABLE videos (id INT PRIMARY KEY, title VARCHAR(255), started VARCHAR(20), type INT DEFAULT 0)',
            'CREATE TABLE tv_episodes (id INT PRIMARY KEY, videos_id INT, series INT, episode INT, title VARCHAR(255), INDEX identity_tuple(videos_id,series,episode,id))',
            'CREATE TABLE tv_info (videos_id INT PRIMARY KEY, publisher VARCHAR(255), image INT DEFAULT 0)',
            'CREATE TABLE root_categories (id INT PRIMARY KEY, title VARCHAR(255))',
            "INSERT INTO root_categories VALUES (5000,'TV')",
            'CREATE TABLE categories (id INT PRIMARY KEY, root_categories_id INT, title VARCHAR(255))',
            "INSERT INTO categories VALUES (5030,5000,'HD')",
            'CREATE TABLE releases (id INT PRIMARY KEY, videos_id INT, tv_episodes_id INT, searchname VARCHAR(255), display_name VARCHAR(255), categories_id INT DEFAULT 5030, passwordstatus INT DEFAULT 0, completion INT DEFAULT 100, postdate DATETIME, adddate DATETIME, grabs INT, groups_id INT, guid VARCHAR(64) UNIQUE, additional_pp_claim_token VARCHAR(64), nfostatus INT DEFAULT 0, size BIGINT DEFAULT 0, totalpart INT DEFAULT 0, comments INT DEFAULT 0, repair_outcome VARCHAR(64), rescan_outcome VARCHAR(64), haspreview INT DEFAULT 0, jpgstatus INT DEFAULT 0, fromname VARCHAR(255), isrenamed INT DEFAULT 0, imdbid VARCHAR(20), musicinfo_id INT, consoleinfo_id INT, gamesinfo_id INT, bookinfo_id INT, anidbid INT, INDEX show_id(videos_id,id))',
            'CREATE TABLE user_series (users_id INT, videos_id INT)',
            'CREATE TABLE users_releases (users_id INT, releases_id INT, UNIQUE (users_id, releases_id))',
            'CREATE TABLE user_downloads (releases_id INT, timestamp DATETIME, INDEX release_time(releases_id,timestamp))',
        ];
        $statements[] = 'CREATE TABLE release_audio_tags (releases_id INT, '.implode(',', array_map(static fn (string $name): string => $name.' VARCHAR(255)', ['album', 'album_performer', 'performer', 'genre', 'recorded_date', 'track_name', 'track_position', 'track_position_total', 'musicbrainz_album_id', 'musicbrainz_track_id', 'audio_format', 'has_preview', 'preview_extension', 'preview_mime', 'preview_seconds', 'has_spectrogram'])).', UNIQUE (releases_id))';
        $statements[] = 'CREATE TABLE release_video_clips (releases_id INT UNIQUE, extension VARCHAR(10), mime VARCHAR(255))';
        $statements[] = 'ALTER TABLE releases ADD videostatus INT DEFAULT 0';
        foreach ($statements as $sql) {
            $pdo->exec($sql);
        }
    }

    private function seedCatalogue(PDO $pdo, int $first, int $last): void
    {
        $episodeRows = [];
        $showRows = [];
        $releaseRows = [];
        for ($show = $first; $show <= $last; $show++) {
            $showRows[] = "({$show},'Fixture Show {$show}','2020-01-01',0)";
            for ($season = 1; $season <= 10; $season++) {
                for ($episode = 1; $episode <= 10; $episode++) {
                    $id = ($show - 1) * 100 + ($season - 1) * 10 + $episode;
                    $episodeRows[] = "({$id},{$show},{$season},{$episode},'Episode {$season}-{$episode}')";
                    if (count($episodeRows) === 500) {
                        $pdo->exec('INSERT INTO tv_episodes VALUES '.implode(',', $episodeRows));
                        $episodeRows = [];
                    }
                }
            }
            foreach (['S01.COMPLETE', 'S01E01', 'S01E01-E02'] as $index => $name) {
                $id = ($show - 1) * 3 + $index + 1;
                $linked = $index === 1 ? ($show - 1) * 100 + 1 : 0;
                $day = $index + 1;
                $releaseRows[] = "({$id},{$show},{$linked},'Fixture.Show.{$show}.{$name}','2026-01-0{$day}','2026-02-0{$day}',{$day})";
            }
            if (count($showRows) === 100 || $show === $last) {
                $pdo->exec('INSERT INTO videos VALUES '.implode(',', $showRows));
                $pdo->exec('INSERT INTO releases (id,videos_id,tv_episodes_id,searchname,postdate,adddate,grabs) VALUES '.implode(',', $releaseRows));
                $showRows = [];
                $releaseRows = [];
            }
        }
    }
}
