<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Category;
use Illuminate\Support\Facades\DB;

/**
 * Production-shaped tables and one fully linked release for the name and category write
 * characterization tests. The using class must use {@see IsolatedSqliteDatabase}.
 */
trait NameWriteTables
{
    /** The links every release fixture starts with, so a test can see which ones a write clears. */
    protected const array LINKED = [
        'videos_id' => 41,
        'tv_episodes_id' => 42,
        'movieinfo_id' => 43,
        'imdbid' => '1234567',
        'musicinfo_id' => 44,
        'consoleinfo_id' => 45,
        'bookinfo_id' => 46,
        'anidbid' => 47,
        'gamesinfo_id' => 48,
    ];

    protected function createNameWriteTables(): void
    {
        $tables = ProductionTables::fromAuthority();
        $tables->create('releases', [
            'id', 'name', 'searchname', 'searchname_normalized', 'display_name', 'totalpart', 'groups_id', 'size',
            'postdate', 'adddate', 'guid', 'leftguid', 'fromname', 'completion', 'categories_id', 'videos_id',
            'tv_episodes_id', 'imdbid', 'musicinfo_id', 'consoleinfo_id', 'gamesinfo_id', 'bookinfo_id', 'anidbid',
            'movieinfo_id', 'predb_id', 'grabs', 'passwordstatus', 'haspreview', 'nfostatus', 'nzbstatus',
            'iscategorized', 'isrenamed', 'is_trusted_name', 'name_source', 'proc_pp', 'proc_par2', 'proc_nfo',
            'proc_files', 'proc_xxx', 'proc_uid', 'proc_media_movie', 'proc_srr', 'proc_hash16k', 'proc_crc32',
            'proc_srrdb', 'pp_timeout_count', 'additional_pp_claimed_at', 'additional_pp_claim_token',
        ]);
        $tables->create('usenet_groups', ['id', 'name', 'active', 'backfill', 'forced_root_categories_id']);
        $tables->create('releases_groups', ['releases_id', 'groups_id']);
        $tables->create('release_files', ['releases_id', 'name', 'size', 'crc32']);
        $tables->create('predb', ['id', 'title', 'source']);
        $tables->create('root_categories', ['id', 'title', 'generate_previews']);
        $tables->create('categories', ['id', 'title', 'root_categories_id']);
        $tables->create('par_hashes', ['releases_id', 'hash']);
        $tables->create('video_data', ['releases_id', 'containerformat', 'videoformat', 'videocodec', 'videowidth', 'videoheight', 'videoduration']);
        $tables->create('audio_data', ['id', 'releases_id', 'audioid', 'audioformat']);

        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.characterization', 'active' => 1, 'backfill' => 0]);
        foreach ([Category::OTHER_ROOT => 'Other', Category::MOVIE_ROOT => 'Movies', Category::TV_ROOT => 'TV', Category::MUSIC_ROOT => 'Audio',
            Category::BOOKS_ROOT => 'Books', Category::XXX_ROOT => 'XXX', Category::GAME_ROOT => 'Console', Category::PC_ROOT => 'PC'] as $id => $title) {
            DB::table('root_categories')->insert(['id' => $id, 'title' => $title, 'generate_previews' => 1]);
        }
        foreach ([Category::OTHER_MISC => 'Misc', Category::OTHER_HASHED => 'Hashed', Category::MOVIE_HD => 'HD',
            Category::MOVIE_OTHER => 'Other', Category::TV_HD => 'HD', Category::TV_OTHER => 'Other',
            Category::TV_ANIME => 'Anime', Category::MUSIC_MP3 => 'MP3', Category::MUSIC_AUDIOBOOK => 'Audiobook',
            Category::BOOKS_EBOOK => 'Ebook', Category::BOOKS_UNKNOWN => 'Other', Category::XXX_OTHER => 'Other',
            Category::XXX_X264 => 'x264'] as $id => $title) {
            DB::table('categories')->insert(['id' => $id, 'title' => $title, 'root_categories_id' => Category::rootCategoryFor($id)]);
        }
    }

    /**
     * A release with every metadata link set and no rename status yet.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function linkedRelease(int $id, array $overrides = []): int
    {
        DB::table('releases')->insert([
            'id' => $id,
            'name' => 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6 yEnc',
            'searchname' => 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6',
            'searchname_normalized' => 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6',
            'guid' => sprintf('%040d', $id),
            'leftguid' => '0',
            'groups_id' => 1,
            'size' => 1000,
            'fromname' => 'poster@example.test',
            'categories_id' => Category::OTHER_HASHED,
            ...self::LINKED,
            'predb_id' => 0,
            'iscategorized' => 0,
            'isrenamed' => 0,
            'is_trusted_name' => 0,
            'name_source' => null,
            'haspreview' => 0,
            'passwordstatus' => 0,
            'nzbstatus' => 1,
            'proc_pp' => 0, 'proc_par2' => 0, 'proc_nfo' => 0, 'proc_files' => 0, 'proc_xxx' => 0, 'proc_uid' => 0,
            'proc_media_movie' => 0, 'proc_srr' => 0, 'proc_hash16k' => 0, 'proc_crc32' => 0, 'proc_srrdb' => 0,
            ...$overrides,
        ]);

        return $id;
    }

    /** @return array<string, mixed> */
    protected function releaseRow(int $id): array
    {
        return (array) DB::table('releases')->where('id', $id)->first();
    }

    /**
     * The link columns of a stored release, as stored.
     *
     * @return array<string, mixed>
     */
    protected function linksOf(int $id): array
    {
        $row = $this->releaseRow($id);

        $links = [];
        foreach (array_keys(self::LINKED) as $column) {
            $value = $row[$column];
            $links[$column] = $value === null ? null : (is_numeric($value) ? (int) $value : $value);
        }

        return $links;
    }

    /**
     * Link values in stored order and form: the fixture's links with the given ones replaced.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected function links(array $values = []): array
    {
        $links = [];
        foreach (self::LINKED as $column => $linked) {
            $value = array_key_exists($column, $values) ? $values[$column] : $linked;
            $links[$column] = $value === null ? null : (is_numeric($value) ? (int) $value : $value);
        }

        return $links;
    }
}
