<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('release_music_identifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('releases_id');
            $table->unsignedInteger('release_audio_evidence_id');
            $table->char('evidence_hash', 64);
            $table->string('state', 32);
            $table->unsignedSmallInteger('score')->default(0);
            $table->string('band', 16);
            $table->string('accepted_scope', 32)->nullable();
            $table->char('musicbrainz_recording_id', 36)->nullable();
            $table->char('musicbrainz_release_id', 36)->nullable();
            $table->char('musicbrainz_release_group_id', 36)->nullable();
            $table->json('reasons');
            $table->json('feature_contributions');
            $table->smallInteger('runner_up_margin')->nullable();
            $table->unsignedSmallInteger('attempt_count')->default(1);
            $table->string('lease_token', 64)->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->text('last_operational_error')->nullable();
            $table->string('algorithm_version', 64);
            $table->string('resolver_version', 64);
            $table->string('normalizer_version', 64);
            $table->string('scorer_version', 64);
            $table->string('policy_version', 64);
            $table->timestamp('mirror_replicated_at')->nullable();
            $table->timestamp('mirror_searched_at')->nullable();
            $table->timestamp('acoustid_looked_up_at')->nullable();
            $table->unsignedBigInteger('supersedes_id')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['releases_id', 'evidence_hash', 'algorithm_version'],
                'release_music_identity_version',
            );
            $table->index(['state', 'next_attempt_at'], 'release_music_identity_retry');
            $table->foreign('releases_id', 'FK_rmi_releases')->references('id')->on('releases')
                ->onDelete('cascade')->onUpdate('cascade');
            $table->foreign('release_audio_evidence_id', 'FK_rmi_rae')->references('id')->on('release_audio_evidence')
                ->onDelete('cascade')->onUpdate('cascade');
            $table->foreign('supersedes_id', 'FK_rmi_supersedes')->references('id')->on('release_music_identifications')
                ->restrictOnDelete()->cascadeOnUpdate();
        });

        Schema::create('release_music_candidate_attempts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('release_music_identification_id');
            $table->unsignedSmallInteger('rank');
            $table->unsignedSmallInteger('score');
            $table->char('musicbrainz_recording_id', 36)->nullable();
            $table->char('musicbrainz_release_id', 36)->nullable();
            $table->char('musicbrainz_release_group_id', 36)->nullable();
            $table->json('display_snapshot');
            $table->json('feature_vector');
            $table->json('score_contributions');
            $table->json('contradictions');
            $table->json('provenance');
            $table->json('response_cache_keys');
            $table->timestamps();

            $table->unique(['release_music_identification_id', 'rank'], 'release_music_candidate_rank');
            $table->foreign('release_music_identification_id', 'FK_rmca_rmi')->references('id')->on('release_music_identifications')
                ->onDelete('cascade')->onUpdate('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('release_music_candidate_attempts');
        Schema::dropIfExists('release_music_identifications');
    }
};
