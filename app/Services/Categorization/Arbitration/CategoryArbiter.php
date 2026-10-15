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

    /** A generated word, a number and a twelve-digit timestamp. */
    private const string GENERATED_TIMESTAMP_NAME = '/^[A-Z][a-z]+-\d+-\d{12}$/u';

    /** A part counter such as `[04/85]` and everything after it. */
    private const string PART_COUNTER = '/\s*(?:-\s*)?\[\d+\/\d+\].*$/u';

    private const string ARCHIVE_EXTENSION = '/\.(?:part\d+|vol\d+\+\d+|par2|rar|r\d{2}|7z|\d{3}|zip|bin|mkv|mp4|avi|nzb|iso)$/iu';

    /** Two or more letters each followed by a dot, as in `F.B.I.`. */
    private const string DOTTED_ACRONYM = '/(?<!\p{L})(?:\p{L}\.){2,}/u';

    /** Letters only, all lowercase, all uppercase, or capitalized or camel case. */
    private const string TITLE_WORD = '/^(?=\p{L}{2,}$)(?:\p{Ll}+|\p{Lu}+|\p{Lu}*(?:\p{Lu}\p{Ll}+)+)$/u';

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

        return $this->names->isReadableReleaseTitle($name)
            && ! $this->names->isHashedOrGibberish($name)
            && $this->looksLikeATitle($passable->context->preExtractionName);
    }

    /**
     * Whether the name as posted holds at least one word a person could read.
     *
     * It reads the name before the obfuscated-subject extractor turned dots
     * into spaces and title-cased it, so a container extension is still an
     * extension rather than a word. Obfuscated subjects the shared checks let
     * through — a short mixed-case token, a file stem with a part counter, a
     * generated word with a timestamp — hold no such word.
     */
    private function looksLikeATitle(string $name): bool
    {
        $name = trim($name);

        if (preg_match(self::GENERATED_TIMESTAMP_NAME, $name) === 1) {
            return false;
        }

        $name = (string) preg_replace(self::PART_COUNTER, '', $name);

        do {
            $name = (string) preg_replace(self::ARCHIVE_EXTENSION, '', $name, -1, $removed);
        } while ($removed > 0);

        $name = (string) preg_replace_callback(
            self::DOTTED_ACRONYM,
            static fn (array $match): string => str_replace('.', '', $match[0]),
            $name,
        );

        foreach (preg_split('/[^\p{L}\p{N}]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $piece) {
            if (preg_match(self::TITLE_WORD, $piece) === 1) {
                return true;
            }
        }

        return false;
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
