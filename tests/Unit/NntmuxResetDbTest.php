<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\NntmuxResetDb;
use PHPUnit\Framework\TestCase;

/** Truncation restarts ids, so every link table must be on the reset list or it re-attaches to new rows. */
final class NntmuxResetDbTest extends TestCase
{
    public function test_the_reset_list_names_every_emptied_table_and_keeps_the_shared_name_tables(): void
    {
        $this->assertSame([
            'binaries',
            'collections',
            'parts',
            'missed_parts',
            'usenet_group_provider_cursors',
            'usenet_group_provider_ingested_ranges',
            'videos',
            'tv_episodes',
            'tv_info',
            'video_genres',
            'video_people',
            'release_nfos',
            'release_comments',
            'users_releases',
            'user_movies',
            'user_series',
            'movieinfo',
            'movie_genres',
            'movie_people',
            'musicinfo',
            'release_files',
            'audio_data',
            'release_music_candidate_attempts',
            'release_music_identifications',
            'release_audio_evidence_tracks',
            'release_audio_evidence',
            'release_audio_tags',
            'release_subtitles',
            'release_tv_episodes',
            'release_audio_languages',
            'video_data',
            'media_infos',
            'releases',
            'anidb_titles',
            'anidb_info',
            'releases_groups',
        ], NntmuxResetDb::TRUNCATE_TABLES);
        $this->assertNotContains('genres', NntmuxResetDb::TRUNCATE_TABLES);
        $this->assertNotContains('people', NntmuxResetDb::TRUNCATE_TABLES);
        $this->assertNotContains('languages', NntmuxResetDb::TRUNCATE_TABLES);
    }
}
