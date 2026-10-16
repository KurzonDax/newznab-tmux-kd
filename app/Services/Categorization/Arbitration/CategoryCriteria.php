<?php

declare(strict_types=1);

namespace App\Services\Categorization\Arbitration;

use App\Services\DecisionModel\Questions\ChoiceQuestion;
use App\Services\DecisionModel\Questions\NoulQuestion;
use JsonException;
use RuntimeException;

/**
 * The versioned criteria the category questions are asked with, read from the
 * criteria file: the instructions and option descriptions sent to the model,
 * and the map from the model's option keys to category ids.
 */
final readonly class CategoryCriteria
{
    /** The root option that means "none of the roots". */
    public const string OTHER_ROOT = 'other';

    /**
     * @param  array<string, mixed>  $criteria  The decoded criteria file.
     */
    private function __construct(
        public string $version,
        private array $criteria,
    ) {}

    public static function fromFile(string $path): self
    {
        $contents = is_file($path) ? file_get_contents($path) : false;
        if ($contents === false) {
            throw new RuntimeException("The category criteria file [{$path}] cannot be read.");
        }

        try {
            $criteria = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("The category criteria file [{$path}] is not valid JSON.", previous: $e);
        }

        return self::fromArray($criteria);
    }

    public static function fromArray(mixed $criteria): self
    {
        if (! is_array($criteria)
            || ! is_string($criteria['version'] ?? null) || $criteria['version'] === ''
            || ! is_string($criteria['root']['instructions'] ?? null)
            || ! is_array($criteria['root']['criteria'] ?? null)
            || ! is_string($criteria['readable']['instructions'] ?? null)
            || ! is_string($criteria['adult']['instructions'] ?? null)
            || ! is_array($criteria['sub'] ?? null)
            || ! is_array($criteria['category_ids'] ?? null)
            || ! is_array($criteria['root_of_id'] ?? null)) {
            throw new RuntimeException('The category criteria are missing a version, a question or an id map.');
        }

        return new self($criteria['version'], $criteria);
    }

    public function rootQuestion(): ChoiceQuestion
    {
        return new ChoiceQuestion($this->criteria['root']['instructions'], $this->criteria['root']['criteria']);
    }

    public function readableQuestion(): NoulQuestion
    {
        return new NoulQuestion($this->criteria['readable']['instructions']);
    }

    public function adultQuestion(): NoulQuestion
    {
        return new NoulQuestion($this->criteria['adult']['instructions']);
    }

    public function hasRoot(string $root): bool
    {
        return isset($this->criteria['root']['criteria'][$root]);
    }

    /**
     * The subcategory question of a root, or null when the root has none.
     */
    public function subcategoryQuestion(string $root): ?ChoiceQuestion
    {
        $sub = $this->criteria['sub'][$root] ?? null;

        if (! is_array($sub) || ! is_string($sub['instructions'] ?? null) || ! is_array($sub['criteria'] ?? null)) {
            return null;
        }

        return new ChoiceQuestion($sub['instructions'], $sub['criteria']);
    }

    /**
     * The yes-or-no question whether a release of a root is foreign, or null
     * when the root has none.
     */
    public function foreignQuestion(string $root): ?NoulQuestion
    {
        $foreign = $this->criteria['sub'][$root]['foreign'] ?? null;

        if (! is_array($foreign) || ! is_string($foreign['instructions'] ?? null) || ! is_array($foreign['criteria'] ?? null)) {
            return null;
        }

        return new NoulQuestion($foreign['instructions'], array_filter([
            'true' => $foreign['criteria']['true'] ?? null,
            'false' => $foreign['criteria']['false'] ?? null,
        ], is_string(...)));
    }

    /**
     * Whether a confident foreign answer wins over a confident subcategory
     * choice in a root; when it does not, foreign is taken only when no choice is.
     */
    public function foreignBeforeChoice(string $root): bool
    {
        return ($this->criteria['sub'][$root]['foreign']['before_choice'] ?? false) === true;
    }

    /**
     * The root category id a root key stands for, or null when the file maps none to it.
     */
    public function rootCategoryId(string $root): ?int
    {
        $thousands = array_search($root, $this->criteria['root_of_id'], true);

        return $thousands === false ? null : ((int) $thousands) * 1000;
    }

    /**
     * The category id a subcategory key of a root resolves to, or null when
     * the file offers no such key.
     */
    public function categoryId(string $root, string $leaf): ?int
    {
        $id = $this->criteria['category_ids'][$root][$leaf] ?? null;

        return is_int($id) ? $id : null;
    }
}
