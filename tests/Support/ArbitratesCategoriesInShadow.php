<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Categorization\Arbitration\CategoryArbiter;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;

/**
 * Category arbitration in shadow mode for a test that stores releases: the
 * model is asked about every unsure name, always answers "Other", and each
 * question leaves one decision row behind for the caller to link.
 */
trait ArbitratesCategoriesInShadow
{
    /** @var list<array<string, mixed>> */
    private array $decisionLinkFailures = [];

    private int $decisionLinkUpdates = 0;

    private function arbitrateCategoriesInShadow(): void
    {
        foreach (['decision_model_criteria', 'decision_model_decisions'] as $table) {
            if (! Schema::hasTable($table)) {
                ProductionTables::fromAuthority()->create($table, null);
            }
        }

        foreach (['decision_model_mode' => 'shadow', 'decision_model_arbitrate_below' => '0.70'] as $name => $value) {
            DB::table('settings')->where('name', $name)->delete();
            DB::table('settings')->insert(['name' => $name, 'value' => $value]);
        }

        config([
            'decision-model.enabled' => true,
            'decision-model.base_url' => 'https://decision.example.test',
            'decision-model.api_key' => 'invented-token',
            'decision-model.retry_attempts' => 1,
        ]);

        Http::fake(['https://decision.example.test/*' => Http::response(['answers' => [
            'root' => ['choice' => 'other', 'probabilities' => ['other' => 0.9], 'confidence' => 0.9],
            'readable' => ['noul' => 0.9],
            'adult' => ['noul' => 0.1],
        ]])]);
        Sleep::fake();
        // An arbiter resolved earlier keeps the mode it read then.
        app()->forgetInstance(CategoryArbiter::class);

        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            if ($event->message === 'decision_model.attach_subject_failed') {
                $this->decisionLinkFailures[] = $event->context;
            }
        });
        DB::listen(function ($query): void {
            if (preg_match('/^update\s+["`]?decision_model_decisions/i', $query->sql) === 1) {
                $this->decisionLinkUpdates++;
            }
        });
    }

    /**
     * Make every attempt to link a decision fail in the database, after the
     * decision itself and the release were written.
     */
    private function failDecisionLinking(): void
    {
        DB::statement("CREATE TRIGGER fail_decision_linking BEFORE UPDATE OF subject_id ON decision_model_decisions
            BEGIN SELECT RAISE(ABORT, 'invented decision-log failure'); END");
    }
}
