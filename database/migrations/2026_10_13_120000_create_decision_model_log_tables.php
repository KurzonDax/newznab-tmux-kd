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
        // One row per use case and criteria version, so a decision row carries an id
        // instead of repeating the use case name and the version sentence.
        Schema::create('decision_model_criteria', function (Blueprint $table) {
            $table->id();
            $table->string('use_case', 64);
            $table->string('version', 255);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['use_case', 'version'], 'ux_decision_model_criteria');
        });

        // One row per decision, whatever the use case. The subject is named by type
        // and id rather than a foreign key so any kind of subject can be logged, and
        // a subject with no stored row yet (a release being created) can be too.
        Schema::create('decision_model_decisions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('decision_model_criteria_id');
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('mode', 16);
            $table->json('state');
            $table->json('answers')->nullable();
            $table->json('pipeline_answer')->nullable();
            $table->json('interpretation')->nullable();
            $table->boolean('applied')->default(false);
            $table->string('failure_reason', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id'], 'ix_dmd_subject');
            $table->index(['decision_model_criteria_id', 'created_at'], 'ix_dmd_criteria_created');
            $table->foreign('decision_model_criteria_id', 'FK_dmd_criteria')
                ->references('id')->on('decision_model_criteria')
                ->restrictOnDelete()->cascadeOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('decision_model_decisions');
        Schema::dropIfExists('decision_model_criteria');
    }
};
