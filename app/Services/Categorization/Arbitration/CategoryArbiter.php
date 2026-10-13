<?php

declare(strict_types=1);

namespace App\Services\Categorization\Arbitration;

use App\Models\Category;
use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\Pipes\CategorizationPassable;
use App\Services\DecisionModel\DecisionLog;
use App\Services\DecisionModel\DecisionMode;
use App\Services\DecisionModel\DecisionModelClient;
use App\Services\DecisionModel\DecisionRunner;
use App\Services\NameFixing\FileNameCleaner;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks the decision model about a release the pipeline was unsure of.
 *
 * In shadow mode the model's answer is only logged and the passable is left
 * exactly as it was. In apply mode an accepted answer replaces the pipeline's
 * result, and a debug run shows what the model said whether or not it was
 * accepted. Whatever goes wrong — the service is
 * down, its breaker is open, an answer is unusable, the log cannot be written
 * — the pipeline's result stands and nothing is thrown.
 */
final class CategoryArbiter
{
    public const string MATCHED_BY = 'decision_model';

    private const string SUBJECT_TYPE = 'release';

    /**
     * How long a process keeps the settings it read. Categorization runs for
     * every release, so the settings are not read per release; a change in the
     * admin hub reaches a running process within this many seconds.
     */
    private const int SETTINGS_TTL_SECONDS = 60;

    private ?CategoryArbitrationSettings $settings = null;

    private int $settingsReadAt = 0;

    public function __construct(
        private readonly DecisionModelClient $client,
        private readonly DecisionRunner $runner,
        private readonly DecisionLog $log,
        private readonly FileNameCleaner $names = new FileNameCleaner,
    ) {}

    /**
     * Arbitrate the passable's best result in place.
     *
     * @param  int|null  $releaseId  The stored release being categorized, when the caller named one.
     */
    public function arbitrate(CategorizationPassable $passable, ?int $releaseId = null): void
    {
        try {
            $settings = $this->settings();

            if (! $this->shouldAsk($passable, $settings)) {
                return;
            }

            $this->decide($passable, $settings, $releaseId);
        } catch (Throwable $e) {
            Log::warning('categorization.decision_model_failed', [
                'release_name' => $passable->context->releaseName,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The gate. Cheapest checks first: with the mode off this is one
     * comparison, and the name is only examined for a release the pipeline
     * was unsure of.
     */
    private function shouldAsk(CategorizationPassable $passable, CategoryArbitrationSettings $settings): bool
    {
        if ($settings->mode === DecisionMode::Off || ! $this->client->isUsable()) {
            return false;
        }

        if ($passable->lockedToMisc || $passable->bestResult->categoryId === Category::OTHER_HASHED) {
            return false;
        }

        if ($passable->bestResult->confidence >= $settings->arbitrateBelow) {
            return false;
        }

        $name = $passable->context->releaseName;

        return $this->names->isReadableReleaseTitle($name) && ! $this->names->isHashedOrGibberish($name);
    }

    private function decide(CategorizationPassable $passable, CategoryArbitrationSettings $settings, ?int $releaseId): void
    {
        $pipeline = $passable->bestResult;

        $run = $this->runner->run(
            CategoryArbitrationUseCase::NAME,
            new CategoryArbitrationSubject($passable->context, $settings->applyThreshold),
        );

        $verdict = $run->verdict instanceof CategoryVerdict ? $run->verdict : null;
        $accepted = $settings->mode === DecisionMode::Apply && $verdict?->accepted() ? $verdict : null;
        $pipelineAnswer = [
            'category_id' => $pipeline->categoryId,
            'confidence' => $pipeline->confidence,
            'matched_by' => $pipeline->matchedBy,
        ];

        // Logged before the result changes, so no applied decision goes unrecorded.
        $this->log->record($run, self::SUBJECT_TYPE, $releaseId, $pipelineAnswer, $settings->mode, $accepted !== null);

        if ($settings->mode !== DecisionMode::Apply) {
            return;
        }

        if ($passable->debug) {
            $passable->allResults['DecisionModel'] = [
                'category_id' => $verdict?->categoryId,
                'confidence' => $verdict?->confidence,
                'matched_by' => self::MATCHED_BY,
                'applied' => $accepted !== null,
                'reason' => $verdict !== null ? $verdict->reason : $run->failureReason,
                'answers' => $run->answers(),
            ] + ($accepted !== null ? ['suppressed' => $pipelineAnswer] : []);
        }

        if ($accepted?->categoryId !== null) {
            $passable->bestResult = new CategorizationResult($accepted->categoryId, $accepted->confidence, self::MATCHED_BY, [
                'pipeline_category_id' => $pipeline->categoryId,
                'pipeline_confidence' => $pipeline->confidence,
                'pipeline_matched_by' => $pipeline->matchedBy,
            ]);
        }
    }

    private function settings(): CategoryArbitrationSettings
    {
        $now = time();

        if ($this->settings === null || $now - $this->settingsReadAt >= self::SETTINGS_TTL_SECONDS) {
            $this->settings = CategoryArbitrationSettings::read();
            $this->settingsReadAt = $now;
        }

        return $this->settings;
    }
}
