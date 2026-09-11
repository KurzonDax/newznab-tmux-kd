<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CandidateQueryPlans;
use Tests\Support\CandidateReference\MovieProcessingCandidateQuery;
use Tests\Support\CandidateReference\MusicIdentityCandidateQuery;
use Tests\Support\CandidateReference\TvProcessingCandidateQuery;
use Tests\TestCase;

final class MetadataCandidateScaleMariaDbTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $originalEnvironment = [];

    public function createApplication()
    {
        $database = getenv('CBP_INTEGRATION_DB_DATABASE');
        if ($database === false || $database === '') {
            return parent::createApplication();
        }
        if ($database !== 'cbp_integration') {
            throw new \RuntimeException('Collection scale tests require the isolated cbp_integration database.');
        }

        foreach (['DB_CONNECTION', 'DB_DATABASE', 'DB_HOST', 'DB_USERNAME', 'DB_PASSWORD'] as $key) {
            $this->originalEnvironment[$key] = getenv($key);
        }

        $this->setEnvironmentValue('DB_CONNECTION', 'mariadb');
        $this->setEnvironmentValue('DB_DATABASE', $database);
        $this->setEnvironmentValue('DB_HOST', 'mariadb');
        $this->setEnvironmentValue('DB_USERNAME', (string) getenv('CBP_INTEGRATION_DB_USERNAME'));
        $this->setEnvironmentValue('DB_PASSWORD', (string) getenv('CBP_INTEGRATION_DB_PASSWORD'));

        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Isolated MariaDB integration test.');
        }
        if (DB::connection()->getDatabaseName() !== 'cbp_integration') {
            throw new \RuntimeException('Refusing a non-test database.');
        }
        DB::setTablePrefix('metadata_'.getmypid().'_');
        DB::statement('CREATE TABLE '.DB::getTablePrefix().'releases (
            id INTEGER UNSIGNED PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            searchname VARCHAR(255) NOT NULL,
            fromname VARCHAR(255) NOT NULL,
            guid VARCHAR(64) NOT NULL,
            leftguid VARCHAR(1) NOT NULL,
            groups_id INTEGER NOT NULL,
            categories_id INTEGER NOT NULL,
            size INTEGER NOT NULL,
            completion INTEGER NOT NULL DEFAULT 0,
            adddate DATETIME NOT NULL,
            nzbstatus INTEGER NOT NULL DEFAULT 0,
            predb_id INTEGER NOT NULL DEFAULT 0,
            nfostatus INTEGER NOT NULL DEFAULT -1,
            proc_nfo INTEGER NOT NULL DEFAULT 0,
            proc_uid INTEGER NOT NULL DEFAULT 0,
            proc_files INTEGER NOT NULL DEFAULT 0,
            proc_xxx INTEGER NOT NULL DEFAULT 0,
            proc_media_movie INTEGER NOT NULL DEFAULT 0,
            proc_par2 INTEGER NOT NULL DEFAULT 0,
            proc_hash16k INTEGER NOT NULL DEFAULT 0,
            proc_srr INTEGER NOT NULL DEFAULT 0,
            proc_crc32 INTEGER NOT NULL DEFAULT 0,
            proc_srrdb INTEGER NOT NULL DEFAULT 0,
            isrenamed INTEGER NOT NULL DEFAULT 0,
            passwordstatus INTEGER NOT NULL DEFAULT 0,
            is_trusted_name INTEGER NOT NULL DEFAULT 0
        )');

        DB::statement('CREATE TABLE '.DB::getTablePrefix().'release_files (
            id INTEGER PRIMARY KEY AUTO_INCREMENT,
            releases_id INTEGER NOT NULL,
            name VARCHAR(255) NOT NULL,
            crc32 VARCHAR(8) NULL,
            size INTEGER NOT NULL DEFAULT 0, KEY release_files_release (releases_id)
        )');

        DB::statement('CREATE TABLE '.DB::getTablePrefix().'media_infos (
            id INTEGER PRIMARY KEY AUTO_INCREMENT,
            releases_id INTEGER NOT NULL,
            movie_name VARCHAR(255),
            unique_id VARCHAR(255), KEY media_infos_release (releases_id)
        )');
        Schema::table('releases', function (Blueprint $table): void {
            $table->unsignedInteger('videos_id')->default(0);
            $table->integer('tv_episodes_id')->default(0);
            $table->dateTime('tv_episode_lookup_attempted_at')->nullable();
            $table->dateTime('postdate')->nullable();
            $table->string('imdbid')->nullable();
            $table->integer('imdb_lookup_attempts')->nullable();
            $table->dateTime('imdb_lookup_attempted_at')->nullable();
            $table->string('additional_pp_claim_token')->nullable();
            $table->integer('musicinfo_id')->nullable();
            $table->index(['categories_id', 'postdate'], 'ix_releases_categories_postdate_admin');
            $table->index(['imdbid', 'passwordstatus', 'categories_id', 'postdate'], 'ix_releases_imdbid_password_cat_postdate');
            $table->index(['groups_id']);
            $table->index(['leftguid']);
            $table->index(['postdate']);
        });
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('forced_root_categories_id')->nullable()->index();
        });
        Schema::create('releases_groups', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id');
            $table->unsignedInteger('groups_id');
            $table->primary(['releases_id', 'groups_id']);
            $table->index(['groups_id', 'releases_id']);
        });
        foreach (['2026_08_30_204947_create_release_audio_evidence_tables.php',
            '2026_08_31_000729_create_release_music_identification_tables.php',
            '2026_08_31_013417_create_release_music_synthesis_attempts_table.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        DB::table('usenet_groups')->insert(['id' => 1, 'forced_root_categories_id' => null]);

    }

    protected function tearDown(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) && str_starts_with(DB::getTablePrefix(), 'metadata_')) {
            foreach (['release_music_synthesis_attempts', 'release_music_candidate_attempts', 'release_music_identifications', 'release_audio_evidence_tracks', 'release_audio_evidence', 'releases_groups', 'usenet_groups', 'releases', 'media_infos', 'release_files'] as $table) {
                Schema::dropIfExists($table);
            }
            DB::setTablePrefix('');
        }
        parent::tearDown();
        foreach ($this->originalEnvironment as $key => $value) {
            $this->setEnvironmentValue($key, $value === false ? null : $value);
        }
    }

    public function test_metadata_calls_stay_local_and_preserve_ordered_sets(): void
    {
        $measurements = [];
        foreach ([100000, 1000000] as $size) {
            DB::table('releases')->delete();
            $r = DB::getTablePrefix().'releases';
            DB::statement("INSERT INTO `$r` (id,name,searchname,fromname,guid,leftguid,groups_id,categories_id,size,adddate,postdate,imdbid,isrenamed)
                SELECT seq,'neutral','neutral','neutral',SHA1(CONCAT('background:',seq)),SUBSTRING('0123456789abcdef',MOD(seq,16)+1,1),
                1,CASE MOD(seq,3) WHEN 0 THEN 7000 WHEN 1 THEN 6000 ELSE 1000 END,2097152,'2026-01-01','2026-01-01','1234567',1 FROM seq_1_to_$size");
            DB::statement("ANALYZE TABLE `$r`");
            foreach ([0, 160, 4096] as $pending) {
                DB::table('releases')->where('id', '>', $size)->delete();
                if ($pending > 0) {
                    foreach ([['tv', 5040], ['movie', 2000], ['music', 3010]] as $index => [$kind, $category]) {
                        $base = (array) DB::table('releases')->where('id', 1)->first();
                        foreach (range(1, $pending) as $offset) {
                            $id = $size + $index * $pending + $offset;
                            DB::table('releases')->insert(array_replace($base, ['id' => $id,
                                'guid' => sha1('pending:'.$kind.':'.$offset), 'leftguid' => dechex($offset % 16),
                                'categories_id' => $category, 'imdbid' => $offset % 2 ? null : '0']));
                        }
                    }
                }
                foreach (['tv', 'movie', 'music'] as $kind) {
                    $column = $kind === 'music' ? 'r.id' : 'id';
                    $reference = $this->candidate($kind, true)->orderBy('postdate')->orderBy($column)->pluck($column)->map(static fn ($id): int => (int) $id)->all();
                    $before = $this->reads();
                    $samples = [];
                    $byCall = [];
                    DB::enableQueryLog();
                    for ($sample = 0; $sample < 20; $sample++) {
                        $start = microtime(true);
                        $actualCount = $this->candidate($kind)->count();
                        $samples[] = $byCall['count'][] = microtime(true) - $start;
                        $this->assertSame(count($reference), $actualCount);
                        foreach (str_split('0123456789abcdef') as $bucket) {
                            $start = microtime(true);
                            $actual = $this->candidate($kind, bucket: $bucket)->orderBy('postdate')->orderBy($column)->limit(75)->pluck($column)->map(static fn ($id): int => (int) $id)->all();
                            $samples[] = $byCall[$bucket][] = microtime(true) - $start;
                            $expected = array_slice(array_values(array_filter($reference, static fn (int $id): bool => dechex(($id - $size) % 16) === $bucket)), 0, 75);
                            $this->assertSame($expected, $actual);
                        }
                    }
                    $sql = DB::getQueryLog();
                    DB::disableQueryLog();
                    DB::flushQueryLog();
                    $reads = $this->reads() - $before;
                    $plans = CandidateQueryPlans::inspect($sql);
                    $this->assertLessThan(10000, max(array_column($plans, 'rows_times_loops')));
                    fwrite(STDERR, 'CANDIDATE_PLANS='.json_encode($plans, JSON_THROW_ON_ERROR).PHP_EOL);
                    $p95ByCall = [];
                    foreach ($byCall as $call => $durations) {
                        sort($durations);
                        $this->assertCount(20, $durations);
                        $p95ByCall[$call] = $durations[18];
                        $this->assertLessThan(1.0, $p95ByCall[$call], (string) $call);
                    }
                    sort($samples);
                    $p95 = $samples[(int) ceil(count($samples) * 0.95) - 1];
                    $measurements[$size][$pending][$kind] = $reads;
                    fwrite(STDERR, json_encode(['size' => $size, 'pending_each' => $pending, 'kind' => $kind,
                        'reads' => $reads, 'p95_seconds' => $p95, 'p95_by_call' => $p95ByCall, 'samples' => count($samples), 'sql_count' => count($sql)]).PHP_EOL);
                    $this->assertLessThan(1.0, $p95);
                }
            }
        }
        foreach ([0, 160, 4096] as $pending) {
            foreach (['tv', 'movie', 'music'] as $kind) {
                $this->assertLessThanOrEqual($measurements[100000][$pending][$kind] * 2 + 1000, $measurements[1000000][$pending][$kind]);
            }
        }
    }

    private function candidate(string $kind, bool $reference = false, string $bucket = ''): Builder
    {
        return match ($kind) {
            'tv' => $reference ? TvProcessingCandidateQuery::query(guidChar: $bucket, processTv: 1)
                : \App\Services\TvProcessing\TvProcessingCandidateQuery::query(guidChar: $bucket, processTv: 1),
            'movie' => $reference ? MovieProcessingCandidateQuery::query(guidChar: $bucket, lookupMode: 1)
                : \App\Services\MetadataProcessing\MovieProcessingCandidateQuery::query(guidChar: $bucket, lookupMode: 1),
            'music' => $reference ? MusicIdentityCandidateQuery::query(guidChar: $bucket)
                : \App\Services\MusicIdentity\MusicIdentityCandidateQuery::query(guidChar: $bucket),
        };
    }

    private function reads(): int
    {
        return array_sum(array_map(static fn ($row): int => (int) $row->Value,
            DB::select("SHOW SESSION STATUS WHERE Variable_name IN ('Handler_read_key','Handler_read_next','Handler_read_prev','Handler_read_rnd','Handler_read_rnd_next')")));
    }

    private function setEnvironmentValue(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            return;
        }

        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
