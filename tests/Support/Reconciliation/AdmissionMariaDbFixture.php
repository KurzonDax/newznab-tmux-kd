<?php

declare(strict_types=1);

namespace Tests\Support\Reconciliation;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Deterministic, entirely synthetic CBP trees in the disposable integration database. */
final class AdmissionMariaDbFixture
{
    /** @return list<int> */
    public static function callerBatch(string $case, bool $preserveBackground = false): array
    {
        self::requireIsolatedDatabase();
        Cache::flush();
        DB::table('collections')->when($preserveBackground, static fn ($query) => $query->where('id', '<', 2000000))->delete();
        DB::table('releases')->delete();
        DB::table('reconciliation_admissions')->delete();
        DB::table('reconciliation_decisions')->delete();
        DB::table('reconciliation_claims')->delete();
        DB::table('collection_sweep_cursors')->delete();
        $count = match ($case) {
            'dense' => 128,
            'single' => 1,
            'fallback', 'positive' => 2,
            default => 8,
        };
        self::collections(1, $count, 1);
        DB::table('collections')->where('id', '<=', $count)->update(['declaredfiles' => 3, 'filesize' => 2097152]);
        DB::table('binaries')->where('collections_id', '<=', $count)->update(['name' => DB::raw("REPLACE(name, '/02]', '/03]')")]);
        if ($case === 'sparse') {
            DB::statement("UPDATE collections SET fromname = CONCAT('Poster ', id)");
        } elseif ($case === 'positive') {
            DB::table('binaries')->delete();
            DB::table('collections')->update(['subject' => 'Example', 'declaredfiles' => 5, 'totalfiles' => 5]);
            DB::table('collection_regexes')->insertOrIgnore(['id' => 999999, 'group_regex' => '.*',
                'regex' => '/^\\[\\d+\\/\\d+\\] - "(?P<name>[^.]+)\\./', 'status' => 1, 'ordinal' => -100]);
            foreach ([1 => [1 => 'Example.mkv', 4 => 'Example.par2', 5 => 'Example.vol000+001.par2'],
                2 => [2 => 'example.r10', 3 => 'example.sfv']] as $source => $files) {
                foreach ($files as $ordinal => $name) {
                    DB::table('binaries')->insert(['id' => $ordinal, 'binaryhash' => md5($name, true),
                        'collections_id' => $source, 'name' => sprintf('[%02d/05] - "%s" yEnc', $ordinal, $name),
                        'filenumber' => $ordinal, 'totalparts' => 1, 'currentparts' => 1, 'partsize' => 1048576, 'partcheck' => 1]);
                    DB::table('parts')->insert(['binaries_id' => $ordinal, 'messageid' => 'file-'.$ordinal.'@example.invalid',
                        'number' => $ordinal, 'partnumber' => 1, 'size' => 1048576]);
                }
            }
        } else {
            $template = (array) DB::table('collections')->where('id', 1)->first();
            $rows = [];
            $dates = $case === 'fallback' ? ['2026-01-01 08:01:00', '2026-01-01 11:30:00'] : ['2026-01-01 09:00:00'];
            if ($case === 'fallback') {
                DB::table('collections')->where('id', 2)->update(['date' => '2026-01-01 10:59:00']);
            }
            foreach ($dates as $index => $date) {
                for ($row = 1; $row <= 257; $row++) {
                    $id = 1000 + $index * 257 + $row;
                    $rows[] = array_replace($template, ['id' => $id, 'collectionhash' => sha1('witness:'.$id, true),
                        'date' => $date, 'dateadded' => '2026-01-01 12:00:00', 'last_seen_at' => '2026-01-01 12:00:00',
                        'last_seen_head_postdate' => '2026-01-01 12:00:00', 'filecheck' => 0, 'totalfiles' => 0,
                        'subject' => str_repeat('s', 255), 'xref' => str_repeat('x', 2000)]);
                }
            }
            DB::table('collections')->insert($rows);
        }

        return range(1, $count);
    }

    public static function requireIsolatedDatabase(): void
    {
        if (DB::connection()->getDatabaseName() !== 'cbp_integration'
            || ! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            throw new \RuntimeException('Admission fixtures require the isolated cbp_integration MariaDB database.');
        }
    }

    public static function collections(int $first, int $count, int $validEvery = 100, int $group = 1): void
    {
        self::requireIsolatedDatabase();
        if ($first < 1 || $count < 1 || $validEvery < 1) {
            throw new \InvalidArgumentException('Fixture ranges must be positive.');
        }
        $last = $first + $count - 1;
        DB::statement("INSERT INTO collections (id, subject, fromname, date, groups_id, totalfiles, declaredfiles,
            collectionhash, dateadded, added, last_seen_at, last_seen_head_postdate, filecheck)
            SELECT seq, CONCAT('Synthetic.Series.S01E', LPAD(seq, 6, '0'), '.1080p.TEST'), 'Synthetic Poster',
            DATE_ADD('2026-01-01 09:00:00', INTERVAL MOD(seq, 120) SECOND), ?, 2, 2,
            UNHEX(SHA1(CONCAT('admission-fixture:', seq))), '2026-01-01 09:00:00', '2026-01-01 09:00:00',
            '2026-01-01 09:00:00', '2026-01-01 09:00:00', 2 FROM seq_{$first}_to_{$last}", [$group]);
        DB::statement("INSERT INTO collection_groups (collections_id, group_name)
            SELECT seq, 'alt.binaries.synthetic' FROM seq_{$first}_to_{$last}");
        for ($file = 1; $file <= 2; $file++) {
            DB::statement("INSERT INTO binaries (id, binaryhash, name, collections_id, filenumber, totalparts, currentparts, partsize, partcheck)
                SELECT seq * 2 + ?, UNHEX(MD5(CONCAT('binary:', seq, ':', ?))),
                CONCAT('[0', ?, '/02] - \"Synthetic.Series.S01E', LPAD(seq, 6, '0'), '.file', ?, '.mkv\" yEnc'),
                seq, ?, 1, 1, IF(MOD(seq, ?) = 0, 1048576, 1), 1 FROM seq_{$first}_to_{$last}",
                [$file, $file, $file, $file, $file, $validEvery]);
            DB::statement("INSERT INTO parts (binaries_id, messageid, number, partnumber, size)
                SELECT seq * 2 + ?, CONCAT('synthetic-', seq, '-', ?, '@example.invalid'), seq * 2 + ?, 1,
                IF(MOD(seq, ?) = 0, 1048576, 1) FROM seq_{$first}_to_{$last}", [$file, $file, $file, $validEvery]);
        }
    }
}
