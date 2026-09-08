<?php

declare(strict_types=1);

namespace Tests\Support\Reconciliation;

use Illuminate\Support\Facades\DB;

trait CreatesPostingSchema
{
    private function createPostingSchema(): void
    {
        // Minimal tables.
        DB::statement('CREATE TABLE settings (
            section TEXT NULL,
            subsection TEXT NULL,
            name TEXT PRIMARY KEY,
            value TEXT NULL,
            hint TEXT NULL,
            setting TEXT NULL
        )');
        // Seed the settings queried in Binaries constructor.
        $defaults = [
            'maxmssgs' => '20000',
            'partrepair' => '1',
            'newgroupscanmethod' => '0',
            'newgroupmsgstoscan' => '50000',
            'newgroupdaystoscan' => '3',
            'maxpartrepair' => '15000',
            'partrepairmaxtries' => '3',
        ];
        foreach ($defaults as $k => $v) {
            DB::table('settings')->insert(['name' => $k, 'value' => $v]);
        }

        DB::statement('CREATE TABLE collections (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            subject VARCHAR(255),
            fromname VARCHAR(255),
            date DATETIME NULL,
            xref TEXT DEFAULT "",
            groups_id INT,
            totalfiles INT,
            declaredfiles INT NOT NULL DEFAULT 0,
            firstarticle INT NULL,
            lastarticle INT NULL,
            collectionhash VARCHAR(40) UNIQUE,
            collection_regexes_id INT,
            dateadded DATETIME NULL,
            last_seen_at DATETIME NULL,
            last_seen_head_postdate DATETIME NULL,
            last_seen_tail_postdate DATETIME NULL,
            filecheck INT DEFAULT 0,
            filesize INT DEFAULT 0,
            noise VARCHAR(64) DEFAULT ""
        )');

        DB::statement('CREATE TABLE collection_groups (
            collections_id INT,
            group_name VARCHAR(255),
            UNIQUE(collections_id, group_name)
        )');

        DB::statement('CREATE TABLE binaries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            binaryhash BLOB,
            name VARCHAR(255),
            collections_id INT,
            totalparts INT,
            currentparts INT,
            filenumber INT,
            partsize INT,
            partcheck INT DEFAULT 0,
            UNIQUE(binaryhash, collections_id)
        )');

        DB::statement('CREATE TABLE parts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            binaries_id INT,
            number INT,
            messageid VARCHAR(255),
            partnumber INT,
            size INT,
            UNIQUE(binaries_id, partnumber)
        )');

        DB::statement('CREATE TABLE missed_parts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            numberid INT,
            groups_id INT,
            attempts INT DEFAULT 0,
            UNIQUE(numberid, groups_id)
        )');

        DB::statement('CREATE TABLE collection_regexes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            group_regex VARCHAR(255),
            regex VARCHAR(255),
            status INT DEFAULT 1,
            ordinal INT DEFAULT 0,
            description VARCHAR(1000) DEFAULT ""
        )');
        DB::statement('CREATE TABLE releases (
            id INTEGER PRIMARY KEY,
            name VARCHAR(255),
            searchname VARCHAR(255),
            searchname_normalized VARCHAR(255),
            display_name VARCHAR(255),
            totalpart INTEGER,
            declaredfiles INTEGER NULL,
            firstarticle INTEGER NULL,
            lastarticle INTEGER NULL,
            groups_id INTEGER,
            adddate DATETIME NULL,
            guid VARCHAR(64),
            leftguid VARCHAR(1),
            postdate DATETIME NULL,
            fromname VARCHAR(255),
            size INTEGER,
            passwordstatus INTEGER,
            haspreview INTEGER,
            categories_id INTEGER,
            nfostatus INTEGER,
            nzbstatus INTEGER,
            completion DOUBLE NOT NULL DEFAULT 0,
            isrenamed INTEGER,
            is_trusted_name INTEGER DEFAULT 0,
            iscategorized INTEGER,
            predb_id INTEGER,
            source VARCHAR(255) NULL,
            movieinfo_id INTEGER DEFAULT 0, imdbid INTEGER DEFAULT 0, videos_id INTEGER DEFAULT 0, tv_episodes_id INTEGER DEFAULT 0,
            additional_pp_claimed_at DATETIME NULL, recovery_claimed_at DATETIME NULL, recovery_claim_token TEXT NULL,
            nzb_creation_claimed_at DATETIME NULL, nzb_creation_claim_token TEXT NULL,
            collectionhash BLOB NULL
        )');
        DB::statement('CREATE UNIQUE INDEX ux_releases_collectionhash ON releases (collectionhash)');

        DB::statement('ALTER TABLE collections ADD releases_id INTEGER NULL');
        DB::statement('ALTER TABLE collections ADD added DATETIME NULL');
        DB::statement('CREATE TABLE usenet_groups (id INTEGER PRIMARY KEY, name TEXT, active INTEGER DEFAULT 1,
            backfill INTEGER DEFAULT 0, last_record_postdate DATETIME, first_record_postdate DATETIME,
            backfill_settled_at DATETIME, minsizetoformrelease INTEGER DEFAULT 0, minfilestoformrelease INTEGER DEFAULT 0)');
        DB::statement('CREATE TABLE releases_groups (releases_id INTEGER, groups_id INTEGER, UNIQUE(releases_id, groups_id))');
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.boneless', 'last_record_postdate' => '2026-01-01 12:00:00']);
        (require database_path('migrations/2026_09_08_121907_create_collection_reconciliation_tables.php'))->up();
    }
}
