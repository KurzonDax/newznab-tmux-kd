<?php

declare(strict_types=1);

namespace App\Services\Categorization\Arbitration;

use App\Services\DecisionModel\DecisionVerdict;

/**
 * What the model's answers mean for a release's category.
 */
final readonly class CategoryVerdict implements DecisionVerdict
{
    public const string ACCEPTED = 'accepted';

    public const string ROOT_BELOW_THRESHOLD = 'root_below_threshold';

    public const string ROOT_OTHER = 'root_other';

    public const string FORCED_ROOT = 'forced_root';

    /**
     * @param  int|null  $categoryId  The category the model's answer resolves to, or null when the pipeline's result is kept.
     * @param  string  $reason  One of the constants above.
     * @param  string  $root  The model's root key.
     * @param  float  $confidence  The model's confidence in the root.
     * @param  string|null  $leaf  The model's subcategory key, when it gave one.
     * @param  float|null  $leafProbability  The probability the model gave its subcategory key, which decides whether it is taken.
     * @param  float|null  $foreignProbability  The model's probability that the release is foreign, or null when that was not asked or failed.
     */
    public function __construct(
        public ?int $categoryId,
        public string $reason,
        public string $root,
        public float $confidence,
        public ?string $leaf,
        public ?float $leafProbability,
        public ?float $leafConfidence,
        public ?float $foreignProbability,
        public float $applyThreshold,
    ) {}

    public function accepted(): bool
    {
        return $this->categoryId !== null;
    }

    public function toArray(): array
    {
        return [
            'category_id' => $this->categoryId,
            'reason' => $this->reason,
            'root' => $this->root,
            'root_confidence' => $this->confidence,
            'leaf' => $this->leaf,
            'leaf_probability' => $this->leafProbability,
            'leaf_confidence' => $this->leafConfidence,
            'foreign' => $this->foreignProbability,
            'apply_threshold' => $this->applyThreshold,
        ];
    }
}
