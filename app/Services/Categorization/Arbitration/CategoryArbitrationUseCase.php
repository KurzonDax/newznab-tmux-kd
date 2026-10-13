<?php

declare(strict_types=1);

namespace App\Services\Categorization\Arbitration;

use App\Models\Category;
use App\Services\DecisionModel\DecisionAnswer;
use App\Services\DecisionModel\DecisionResponse;
use App\Services\DecisionModel\DecisionUseCase;
use App\Services\DecisionModel\Exceptions\MalformedDecisionAnswer;
use InvalidArgumentException;

/**
 * Asks the decision model which category a release belongs in.
 *
 * Request one asks the root category and two yes-or-no questions (is the name
 * readable, is it adult); unless the root is "other", request two asks that
 * root's subcategory. The two yes-or-no answers are logged for comparison and
 * take no part in the verdict.
 *
 * A root answer that is missing, failed or names no root the criteria offer is
 * malformed, and so is a confident subcategory the criteria map to no category.
 * A subcategory answer that is missing or failed is not: the root is still
 * known, and the release goes to that root's Other category.
 */
final class CategoryArbitrationUseCase implements DecisionUseCase
{
    public const string NAME = 'category_arbitration';

    private const string ROOT = 'root';

    private const string READABLE = 'readable';

    private const string ADULT = 'adult';

    private const string SUBCATEGORY = 'sub';

    public function __construct(private ?CategoryCriteria $criteria = null) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function criteriaVersion(): string
    {
        return $this->criteria()->version;
    }

    /**
     * The name is the one handed to the pipeline, before the obfuscated-subject
     * extractor stripped a leading group tag and turned its dots into spaces:
     * the criteria describe names in that shape.
     *
     * @return array<string, string>
     */
    public function state(object $subject): array
    {
        $context = $this->subject($subject)->context;

        return array_filter([
            'release_name' => $context->preExtractionName,
            'newsgroup' => $context->groupName,
            'poster' => $context->poster,
        ], static fn (string $value): bool => $value !== '');
    }

    public function questions(object $subject, array $responses): array
    {
        $criteria = $this->criteria();

        if ($responses === []) {
            return [
                self::ROOT => $criteria->rootQuestion(),
                self::READABLE => $criteria->readableQuestion(),
                self::ADULT => $criteria->adultQuestion(),
            ];
        }

        if (count($responses) > 1) {
            return [];
        }

        $root = $this->usableRoot($responses);
        if ($root === null || $root->choice === CategoryCriteria::OTHER_ROOT) {
            return [];
        }

        $question = $criteria->subcategoryQuestion((string) $root->choice);

        return $question === null ? [] : [self::SUBCATEGORY => $question];
    }

    public function interpret(object $subject, array $responses): CategoryVerdict
    {
        $subject = $this->subject($subject);
        $criteria = $this->criteria();

        $rootAnswer = $this->usableRoot($responses) ?? throw new MalformedDecisionAnswer('root_answer_unusable');
        $root = (string) $rootAnswer->choice;
        $confidence = (float) $rootAnswer->confidence;

        $leafAnswer = ($responses[1] ?? null)?->usableAnswer(self::SUBCATEGORY);
        $leaf = $leafAnswer?->choice;
        $leafConfidence = $leafAnswer?->confidence;

        $verdict = static fn (?int $categoryId, string $reason): CategoryVerdict => new CategoryVerdict(
            $categoryId,
            $reason,
            $root,
            $confidence,
            $leaf,
            $leafConfidence,
            $subject->applyThreshold,
        );

        if ($confidence < $subject->applyThreshold) {
            return $verdict(null, CategoryVerdict::ROOT_BELOW_THRESHOLD);
        }

        if ($root === CategoryCriteria::OTHER_ROOT) {
            return $verdict(null, CategoryVerdict::ROOT_OTHER);
        }

        $rootCategoryId = $criteria->rootCategoryId($root);
        $otherCategoryId = $rootCategoryId === null ? null : Category::otherForRootCategory($rootCategoryId);
        if ($rootCategoryId === null || $otherCategoryId === null) {
            throw new MalformedDecisionAnswer('root_without_category');
        }

        $forcedRootCategoryId = $subject->context->forcedRootCategoryId;
        if ($forcedRootCategoryId !== null && $forcedRootCategoryId !== $rootCategoryId) {
            return $verdict(null, CategoryVerdict::FORCED_ROOT);
        }

        if ($leaf === null || $leafConfidence === null || $leafConfidence < $subject->applyThreshold) {
            return $verdict($otherCategoryId, CategoryVerdict::ACCEPTED);
        }

        return $verdict(
            $criteria->categoryId($root, $leaf) ?? throw new MalformedDecisionAnswer('subcategory_without_category'),
            CategoryVerdict::ACCEPTED,
        );
    }

    /**
     * The root answer when it names a root the criteria offer and carries a
     * confidence; null otherwise.
     *
     * @param  list<DecisionResponse>  $responses
     */
    private function usableRoot(array $responses): ?DecisionAnswer
    {
        $answer = ($responses[0] ?? null)?->usableAnswer(self::ROOT);

        if ($answer === null || $answer->choice === null || $answer->confidence === null
            || ! $this->criteria()->hasRoot($answer->choice)) {
            return null;
        }

        return $answer;
    }

    private function subject(object $subject): CategoryArbitrationSubject
    {
        return $subject instanceof CategoryArbitrationSubject
            ? $subject
            : throw new InvalidArgumentException('Category arbitration decides about a '.CategoryArbitrationSubject::class.'.');
    }

    /**
     * Read on first use, so a process that never asks the model never opens the file.
     */
    private function criteria(): CategoryCriteria
    {
        return $this->criteria ??= CategoryCriteria::fromFile(
            rtrim((string) config('decision-model.criteria_path'), '/').'/'.self::NAME.'.json',
        );
    }
}
