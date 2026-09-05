<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Settings rows nothing reads, removed with the legacy admin forms that were their only
     * remaining mention.
     *
     * Two groups. The first twelve were fields on `admin/site-edit` or `admin/tmux-edit` whose
     * values reached no consumer -- editing them changed nothing, which is worse than not
     * offering them. The rest are rows inherited from upstream whose consumers were removed
     * over the years without the row going with them.
     *
     * Every name here was re-checked against `app/` when this migration was written rather than
     * trusted from the audit. That check kept three rows off the list: `colors_start`,
     * `colors_end` and `colors_exc` are still read by
     * `TmuxTaskRunner::getRandomColor()`, which every disabled pane
     * message goes through. Only the `colors` on/off switch beside them is dead.
     *
     * @var list<string>
     */
    public const array DEAD_SETTINGS = [
        // Legacy form fields with no consumer.
        'end',
        'userdownloadpurgedays',
        'userhostexclusion',
        'partsdeletechunks',
        'showdroppedyencparts',
        'lookupmusic',
        'lookuplanguage',
        'music_identity_shadow',
        'maxmusicprocessed',
        'ffmpeg_duration',
        'bins_kill_timer',
        'post_kill_timer',
        // Rows whose consumers were removed before them.
        'lookupxxx',
        'maxxxxprocessed',
        'checkpasswordedrar',
        'alternate_nntp',
        'addpar2',
        'postdelay',
        'processupdate',
        'nfos',
        'ffmpeg_image_time',
        'colors',
        'sorter',
        'sorter_timer',
        'nzbthreads',
        'debuginfo',
        'showprocesslist',
        'banned',
    ];

    /**
     * Idempotent: a DELETE over a fixed name list is a no-op once the rows are gone, so a
     * rerun costs one statement and changes nothing.
     */
    public function up(): void
    {
        DB::table('settings')->whereIn('name', self::DEAD_SETTINGS)->delete();
    }

    /**
     * Deliberately irreversible.
     *
     * Recreating these rows would restore values no code path reads, so a rollback would put
     * the database back into a state that differs from this one only in rows that do nothing.
     * The seeder no longer carries them either, so there is no defensible value to restore.
     */
    public function down(): void
    {
        // No-op: these rows have no reader, so there is nothing to restore them for.
    }
};
