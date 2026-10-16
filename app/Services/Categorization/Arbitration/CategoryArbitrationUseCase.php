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
 * Asks the decision model which category a release belongs in, from its name alone.
 *
 * Request one asks the root category and two yes-or-no questions (is the name
 * readable, is it adult); unless the root is "other", request two asks that
 * root's subcategory and, for roots whose criteria carry one, whether the
 * release is foreign. The readable and adult answers are logged for comparison
 * and take no part in the verdict.
 *
 * The root is taken on its confidence, the subcategory on the probability of
 * the chosen option. The subcategory only says what kind of content the
 * release is; media probing settles format and quality afterwards.
 *
 * A root answer that is missing, failed or names no root the criteria offer is
 * malformed, and so is a confident subcategory the criteria map to no category.
 * A subcategory or foreign answer that is missing or failed is not: the root
 * is still known, and the release goes to that root's Other category.
 */
final class CategoryArbitrationUseCase implements DecisionUseCase
{
    public const string NAME = 'category_arbitration';

    private const string ROOT = 'root';

    private const string READABLE = 'readable';

    private const string ADULT = 'adult';

    private const string SUBCATEGORY = 'sub';

    private const string FOREIGN = 'foreign';

    /** The subcategory option that names no content type, leaving the root's Other category. */
    private const string OTHER_LEAF = 'other';

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
     * the criteria describe names in that shape. The poster says nothing about
     * the content, and the pipeline's group rules have already used the newsgroup.
     *
     * @return array<string, string>
     */
    public function state(object $subject): array
    {
        return ['release_name' => $this->subject($subject)->context->preExtractionName];
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

        return array_filter([
            self::SUBCATEGORY => $criteria->subcategoryQuestion((string) $root->choice),
            self::FOREIGN => $criteria->foreignQuestion((string) $root->choice),
        ]);
    }

    public function interpret(object $subject, array $responses): CategoryVerdict
    {
        $subject = $this->subject($subject);
        $criteria = $this->criteria();

        $rootAnswer = $this->usableRoot($responses) ?? throw new MalformedDecisionAnswer('root_answer_unusable');
        $root = (string) $rootAnswer->choice;
        $confidence = (float) $rootAnswer->confidence;

        $secondResponse = $responses[1] ?? null;
        $leafAnswer = $secondResponse?->usableAnswer(self::SUBCATEGORY);
        $leaf = $leafAnswer?->choice;
        $leafProbability = $leaf === null ? null : ($leafAnswer->probabilities[$leaf] ?? null);
        $leafConfidence = $leafAnswer?->confidence;
        $foreignProbability = $secondResponse?->usableAnswer(self::FOREIGN)?->noul;

        $verdict = static fn (?int $categoryId, string $reason): CategoryVerdict => new CategoryVerdict(
            $categoryId,
            $reason,
            $root,
            $confidence,
            $leaf,
            $leafProbability,
            $leafConfidence,
            $foreignProbability,
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

        $choiceCategoryId = null;
        if ($leaf !== null && $leafProbability !== null && $leafProbability >= $subject->applyThreshold) {
            $choiceCategoryId = $criteria->categoryId($root, $leaf) ?? throw new MalformedDecisionAnswer('subcategory_without_category');
            if ($leaf === self::OTHER_LEAF) {
                $choiceCategoryId = null;
            }
        }

        $foreignCategoryId = null;
        if ($criteria->foreignQuestion($root) !== null && $foreignProbability !== null && $foreignProbability >= $subject->applyThreshold) {
            $foreignCategoryId = $criteria->categoryId($root, self::FOREIGN) ?? throw new MalformedDecisionAnswer('foreign_without_category');
        }

        $categoryId = $criteria->foreignBeforeChoice($root)
            ? $foreignCategoryId ?? $choiceCategoryId
            : $choiceCategoryId ?? $foreignCategoryId;

        return $verdict($categoryId ?? $otherCategoryId, CategoryVerdict::ACCEPTED);
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
