<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SettingsTableSeeder extends Seeder
{
    /**
     * Seed every setting a fresh install needs.
     *
     * The rows are a plain list. They used to carry hand-written numeric keys left over from an
     * old export, and two of them collided on 273: PHP silently kept the later entry, so
     * `fix_names_timeout` was never seeded at all and every fresh install started with the Fix
     * Names step timeout unset. Dropping the keys removes the whole class of bug rather than
     * renumbering the one instance of it.
     */
    public function run(): void
    {
        DB::table('settings')->delete();

        DB::table('settings')->insert([
            [
                'name' => 'backfillthreads',
                'value' => '1',
            ],
            [
                'name' => 'binarythreads',
                'value' => '1',
            ],
            [
                'name' => 'completionpercent',
                'value' => '95',
            ],
            [
                'name' => 'crossposttime',
                'value' => '2',
            ],
            [
                'name' => 'currentppticket',
                'value' => '0',
            ],
            [
                'name' => 'delaytime',
                'value' => '2',
            ],
            [
                'name' => 'disablebackfillgroup',
                'value' => '0',
            ],
            [
                'name' => 'fixnamesperrun',
                'value' => '10',
            ],
            [
                'name' => 'fixnamethreads',
                'value' => '1',
            ],
            [
                'name' => 'grabstatus',
                'value' => '1',
            ],
            [
                'name' => 'lastpretime',
                'value' => '0',
            ],
            [
                'name' => 'lookupanidb',
                'value' => '0',
            ],
            [
                'name' => 'lookupbooks',
                'value' => '1',
            ],
            [
                'name' => 'lookupgames',
                'value' => '1',
            ],
            [
                'name' => 'lookupimdb',
                'value' => '1',
            ],
            [
                'name' => 'lookupnfo',
                'value' => '1',
            ],
            [
                'name' => 'lookuptv',
                'value' => '1',
            ],
            [
                'name' => 'max_headers_iteration',
                'value' => '1000000',
            ],
            [
                'name' => 'maxaddprocessed',
                'value' => '25',
            ],
            [
                'name' => 'maxanidbprocessed',
                'value' => '100',
            ],
            [
                'name' => 'maxbooksprocessed',
                'value' => '300',
            ],
            [
                'name' => 'maxgamesprocessed',
                'value' => '150',
            ],
            [
                'name' => 'maximdbprocessed',
                'value' => '100',
            ],
            [
                'name' => 'maxmssgs',
                'value' => '20000',
            ],
            [
                'name' => 'maxnestedlevels',
                'value' => '3',
            ],
            [
                'name' => 'maxnfoprocessed',
                'value' => '100',
            ],
            [
                'name' => 'maxnforetries',
                'value' => '5',
            ],
            [
                'name' => 'maxnzbsprocessed',
                'value' => '1000',
            ],
            [
                'name' => 'maxpartrepair',
                'value' => '15000',
            ],
            [
                'name' => 'maxpartsprocessed',
                'value' => '3',
            ],
            [
                'name' => 'maxrageprocessed',
                'value' => '75',
            ],
            [
                'name' => 'maxsizetopostprocess',
                'value' => '107374182400',
            ],
            [
                'name' => 'maxsizetoprocessnfo',
                'value' => '107374182400',
            ],
            [
                'name' => 'minsizetopostprocess',
                'value' => '1048576',
            ],
            [
                'name' => 'minsizetoprocessnfo',
                'value' => '1048576',
            ],
            [
                'name' => 'mischashedretentionhours',
                'value' => '0',
            ],
            [
                'name' => 'miscotherretentionhours',
                'value' => '0',
            ],
            [
                'name' => 'newgroupdaystoscan',
                'value' => '1',
            ],
            [
                'name' => 'newgroupmsgstoscan',
                'value' => '100000',
            ],
            [
                'name' => 'newgroupscanmethod',
                'value' => '0',
            ],
            [
                'name' => 'nextppticket',
                'value' => '0',
            ],
            [
                'name' => 'nfothreads',
                'value' => '1',
            ],
            [
                'name' => 'nntpretries',
                'value' => '10',
            ],
            [
                'name' => 'nzbpath',
                'value' => '/var/www/nntmux/storage/nzb/',
            ],
            [
                'name' => 'nzbsplitlevel',
                'value' => '4',
            ],
            [
                'name' => 'partrepair',
                'value' => '1',
            ],
            [
                'name' => 'partrepairmaxtries',
                'value' => '3',
            ],
            [
                'name' => 'partretentionhours',
                'value' => '72',
            ],
            [
                'name' => 'passchkattempts',
                'value' => '1',
            ],
            [
                'name' => 'postthreads',
                'value' => '1',
            ],
            [
                'name' => 'postthreadsamazon',
                'value' => '1',
            ],
            [
                'name' => 'postthreadsnon',
                'value' => '1',
            ],
            [
                'name' => 'processjpg',
                'value' => '0',
            ],
            [
                'name' => 'processthumbnails',
                'value' => '0',
            ],
            [
                'name' => 'processvideos',
                'value' => '0',
            ],
            [
                'name' => 'registerstatus',
                'value' => '0',
            ],
            [
                'name' => 'releaseretentiondays',
                'value' => '0',
            ],
            [
                'name' => 'releasethreads',
                'value' => '1',
            ],
            [
                'name' => 'safebackfilldate',
                'value' => '2012-06-24',
            ],
            [
                'name' => 'safepartrepair',
                'value' => '0',
            ],
            [
                'name' => 'segmentstodownload',
                'value' => '2',
            ],
            [
                'name' => 'showpasswordedrelease',
                'value' => '0',
            ],
            [
                'name' => 'timeoutseconds',
                'value' => '0',
            ],
            [
                'name' => 'maxsizetoformrelease',
                'value' => '0',
            ],
            [
                'name' => 'minfilestoformrelease',
                'value' => '1',
            ],
            [
                'name' => 'minsizetoformrelease',
                'value' => '0',
            ],
            [
                'name' => 'categorizeforeign',
                'value' => '1',
            ],
            [
                'name' => 'catwebdl',
                'value' => '0',
            ],
            [
                'name' => 'innerfileblacklist',
                'value' => '/setup.exe|password.url/i',
            ],
            [
                'name' => 'collection_timeout',
                'value' => '48',
            ],
            [
                'name' => 'last_run_time',
                'value' => '3015-08-04 15:58:23',
            ],
            [
                'name' => 'code',
                'value' => 'NNTmux',
            ],
            [
                'name' => 'dereferrer_link',
                'value' => '',
            ],
            [
                'name' => 'footer',
                'value' => 'Usenet binary indexer.',
            ],
            [
                'name' => 'home_link',
                'value' => '/',
            ],
            [
                'name' => 'metadescription',
                'value' => 'A usenet indexing website',
            ],
            [
                'name' => 'metakeywords',
                'value' => 'usenet,nzbs,cms,community',
            ],
            [
                'name' => 'metatitle',
                'value' => 'An indexer',
            ],
            [
                'name' => 'strapline',
                'value' => 'A great usenet indexer',
            ],
            [
                'name' => 'tandc',
                'value' => '<p>All information within this database is indexed by an automated process, without any human intervention. It is obtained from global Usenet newsgroups over which this site has no control. We cannot prevent that you might find obscene or objectionable material by using this service. If you do come across obscene, incorrect or objectionable results, let us know by using the contact form.</p>',
            ],
            [
                'name' => 'back_timer',
                'value' => '30',
            ],
            [
                'name' => 'backfill',
                'value' => '0',
            ],
            [
                'name' => 'backfill_days',
                'value' => '1',
            ],
            [
                'name' => 'backfill_order',
                'value' => '2',
            ],
            [
                'name' => 'backfill_qty',
                'value' => '100000',
            ],
            [
                'name' => 'binaries',
                'value' => '0',
            ],
            [
                'name' => 'bins_timer',
                'value' => '30',
            ],
            [
                'name' => 'bwmng',
                'value' => '0',
            ],
            [
                'name' => 'collections_kill',
                'value' => '0',
            ],
            [
                'name' => 'colors_end',
                'value' => '250',
            ],
            [
                'name' => 'colors_exc',
                'value' => '4, 8, 9, 11, 15, 16, 17, 18, 19, 46, 47, 48, 49, 50, 51, 52, 53, 59, 60',
            ],
            [
                'name' => 'colors_start',
                'value' => '1',
            ],
            [
                'name' => 'console',
                'value' => '0',
            ],
            [
                'name' => 'crap_timer',
                'value' => '30',
            ],
            [
                'name' => 'fix_crap',
                'value' => '0',
            ],
            [
                'name' => 'fix_crap_opt',
                'value' => 'Disabled',
            ],
            [
                'name' => 'fix_names',
                'value' => '0',
            ],
            [
                'name' => 'fix_timer',
                'value' => '30',
            ],
            [
                'name' => 'fix_names_timeout',
                'value' => '1200',
            ],
            [
                'name' => 'htop',
                'value' => '0',
            ],
            [
                'name' => 'monitor_delay',
                'value' => '30',
            ],
            [
                'name' => 'monitor_path',
                'value' => 'NULL',
            ],
            [
                'name' => 'monitor_path_a',
                'value' => 'NULL',
            ],
            [
                'name' => 'monitor_path_b',
                'value' => 'NULL',
            ],
            [
                'name' => 'mytop',
                'value' => '0',
            ],
            [
                'name' => 'niceness',
                'value' => '19',
            ],
            [
                'name' => 'nmon',
                'value' => '0',
            ],
            [
                'name' => 'post',
                'value' => '0',
            ],
            [
                'name' => 'post_amazon',
                'value' => '0',
            ],
            [
                'name' => 'post_non',
                'value' => '0',
            ],
            [
                'name' => 'post_timer',
                'value' => '30',
            ],
            [
                'name' => 'post_timer_amazon',
                'value' => '30',
            ],
            [
                'name' => 'post_timer_non',
                'value' => '30',
            ],
            [
                'name' => 'postprocess_kill',
                'value' => '0',
            ],
            [
                'name' => 'progressive',
                'value' => '0',
            ],
            [
                'name' => 'redis',
                'value' => '0',
            ],
            [
                'name' => 'redis_args',
                'value' => '',
            ],
            [
                'name' => 'rel_timer',
                'value' => '30',
            ],
            [
                'name' => 'releases',
                'value' => '0',
            ],
            [
                'name' => 'run_ircscraper',
                'value' => '0',
            ],
            [
                'name' => 'running',
                'value' => '0',
            ],
            [
                'name' => 'sequential',
                'value' => '0',
            ],
            [
                'name' => 'showquery',
                'value' => '0',
            ],
            [
                'name' => 'tcptrack',
                'value' => '0',
            ],
            [
                'name' => 'tcptrack_args',
                'value' => '-i eth0 port 443',
            ],
            [
                'name' => 'tmux_session',
                'value' => 'nntmux',
            ],
            [
                'name' => 'vnstat',
                'value' => '0',
            ],
            [
                'name' => 'vnstat_args',
                'value' => '',
            ],
            [
                'name' => 'trailers_display',
                'value' => '1',
            ],
            [
                'name' => 'trailers_size_x',
                'value' => '480',
            ],
            [
                'name' => 'trailers_size_y',
                'value' => '345',
            ],
            [
                'name' => 'exit',
                'value' => '0',
            ],
            [
                'name' => 'releaseprocessingtimeout',
                'value' => '120',
            ],
            [
                'name' => 'maxpptimeoutcount',
                'value' => '3',
            ],
            [
                'name' => 'single_active_session',
                'value' => '0',
            ],
            [
                'name' => 'discard_executable_extensions',
                'value' => 'dll|exe|msi|scr|com|bat|cmd|pif',
            ],
            [
                'name' => 'descriptive_title_rename',
                'value' => '1',
            ],
            [
                'name' => 'backup_enabled',
                'value' => '0',
            ],
            [
                'name' => 'backup_full_dow',
                'value' => '0',
            ],
            [
                'name' => 'backup_full_time',
                'value' => '02:00',
            ],
            [
                'name' => 'backup_daily_time',
                'value' => '02:00',
            ],
            [
                'name' => 'backup_location',
                'value' => storage_path('app/backups'),
            ],
            [
                'name' => 'backup_keep_fulls',
                'value' => '4',
            ],
            [
                'name' => 'backup_pause_tmux',
                'value' => '1',
            ],
            [
                'name' => 'backup_incl_working',
                'value' => '1',
            ],
            [
                'name' => 'backup_dump_binary',
                'value' => '',
            ],
            [
                'name' => 'backup_offsite_path',
                'value' => '',
            ],
            [
                'name' => 'backup_offsite_after',
                'value' => '0',
            ],
            [
                'name' => 'backup_offsite_keep',
                'value' => '0',
            ],
            [
                'name' => 'backup_run_request',
                'value' => '',
            ],
            [
                'name' => 'backup_pause_marker',
                'value' => '',
            ],
            [
                'name' => 'repair_retry_after_hours',
                'value' => '72',
            ],
            [
                'name' => 'repair_floor_completion',
                'value' => '10',
            ],
            [
                'name' => 'repair_stat_sample_per_file',
                'value' => '2',
            ],
            [
                'name' => 'repair_max_stat_probes',
                'value' => '20',
            ],
            [
                'name' => 'repair_limit',
                'value' => '250',
            ],
            [
                'name' => 'rescan_max_articles_per_release',
                'value' => '500000',
            ],
            [
                'name' => 'rescan_max_articles_per_run',
                'value' => '5000000',
            ],
            [
                'name' => 'rescan_window_minutes',
                'value' => '30',
            ],
            [
                'name' => 'rescan_limit',
                'value' => '100',
            ],
            [
                'name' => 'postthreadsaudio',
                'value' => '1',
            ],
            [
                'name' => 'audio_segments_to_download',
                'value' => '12',
            ],
            [
                'name' => 'audio_max_rar_parts',
                'value' => '6',
            ],
            [
                'name' => 'audio_preview_seconds',
                'value' => '30',
            ],
            [
                'name' => 'audio_preview_start_seconds',
                'value' => '10',
            ],
            [
                'name' => 'audio_spectrogram',
                'value' => '1',
            ],
            [
                'name' => 'audio_max_archive_mb',
                'value' => '1024',
            ],
            [
                'name' => 'audio_min_completion_percent',
                'value' => '95',
            ],
            [
                'name' => 'preview_target_seconds',
                'value' => '30',
            ],
            [
                'name' => 'preview_max_fetch_mb',
                'value' => '300',
            ],
            [
                'name' => 'preview_max_rar_parts',
                'value' => '6',
            ],
            [
                'name' => 'clip_minimum_seconds',
                'value' => '5',
            ],
            [
                'name' => 'music_identity_enabled',
                'value' => '1',
            ],
            [
                'name' => 'music_identity_workers',
                'value' => '1',
            ],
            [
                'name' => 'forced_root_pc_escape',
                'value' => '0',
            ],
            [
                'name' => 'amazonsleep',
                'value' => '1000',
            ],
        ]);
    }
}
