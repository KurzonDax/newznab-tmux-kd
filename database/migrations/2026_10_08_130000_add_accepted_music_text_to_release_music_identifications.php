<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The MusicBrainz text an accepted decision makes searchable (issue #308), stored with the
     * decision: the canonical title (release group, or recording) apart from its search aliases,
     * an accepted edition's own title, the artist credit, the aligned release's track titles and
     * track artist credits (one per line), and the original and edition dates kept separately.
     */
    public function up(): void
    {
        Schema::table('release_music_identifications', function (Blueprint $table): void {
            $table->text('accepted_title')->nullable()->after('musicbrainz_release_group_id');
            $table->text('accepted_edition_title')->nullable()->after('accepted_title');
            $table->text('accepted_aliases')->nullable()->after('accepted_edition_title');
            $table->text('accepted_artist_credit')->nullable()->after('accepted_aliases');
            $table->text('accepted_track_titles')->nullable()->after('accepted_artist_credit');
            $table->text('accepted_track_artist_credits')->nullable()->after('accepted_track_titles');
            $table->string('original_release_date', 10)->nullable()->after('accepted_track_artist_credits');
            $table->string('edition_release_date', 10)->nullable()->after('original_release_date');
        });
    }

    public function down(): void
    {
        Schema::table('release_music_identifications', function (Blueprint $table): void {
            $table->dropColumn([
                'accepted_title', 'accepted_edition_title', 'accepted_aliases', 'accepted_artist_credit',
                'accepted_track_titles', 'accepted_track_artist_credits', 'original_release_date', 'edition_release_date',
            ]);
        });
    }
};
