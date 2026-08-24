<?php

declare(strict_types=1);

namespace App\Services\Categorization;

use App\Models\Category;
use App\Models\Settings;
use App\Models\UsenetGroup;
use App\Services\Categorization\Pipes\AbstractCategorizationPipe;
use App\Services\Categorization\Pipes\CategorizationPassable;
use App\Services\NameFixing\Extractors\ObfuscatedSubjectExtractor;
use App\Services\NameFixing\NzbSplitUnwrapper;
use App\Services\Releases\ForcedRootPolicy;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Pipeline-based categorization service using Laravel Pipeline.
 *
 * This service uses Laravel's Pipeline to orchestrate multiple categorizers
 * to determine the best category for a release. Each categorizer (pipe) is
 * responsible for a specific category domain and returns a result with a
 * confidence score.
 */
class CategorizationPipeline
{
    /**
     * @var Collection<AbstractCategorizationPipe>
     */
    protected Collection $pipes; // @phpstan-ignore missingType.generics

    protected bool $categorizeForeign;

    protected bool $catWebDL;

    protected NzbSplitUnwrapper $nzbSplitUnwrapper;

    protected ObfuscatedSubjectExtractor $obfuscatedSubjectExtractor;

    protected ForcedRootPolicy $forcedRootPolicy;

    /**
     * @param  iterable<AbstractCategorizationPipe>  $pipes
     */
    public function __construct(
        iterable $pipes = [],
        ?NzbSplitUnwrapper $nzbSplitUnwrapper = null,
        ?ObfuscatedSubjectExtractor $obfuscatedSubjectExtractor = null,
        ?ForcedRootPolicy $forcedRootPolicy = null,
    ) {
        $this->pipes = collect($pipes)
            ->sortBy(fn (AbstractCategorizationPipe $p) => $p->getPriority());

        $this->categorizeForeign = (bool) Settings::settingValue('categorizeforeign');
        $this->catWebDL = (bool) Settings::settingValue('catwebdl');
        $this->nzbSplitUnwrapper = $nzbSplitUnwrapper ?? new NzbSplitUnwrapper;
        $this->obfuscatedSubjectExtractor = $obfuscatedSubjectExtractor ?? new ObfuscatedSubjectExtractor;
        $this->forcedRootPolicy = $forcedRootPolicy ?? new ForcedRootPolicy;
    }

    /**
     * Register a categorizer pipe in the pipeline.
     */
    public function addCategorizer(AbstractCategorizationPipe $pipe): self
    {
        $this->pipes->push($pipe);
        $this->pipes = $this->pipes->sortBy(fn (AbstractCategorizationPipe $p) => $p->getPriority());

        return $this;
    }

    /**
     * Determine the category for a release using Laravel Pipeline.
     *
     * @param  int|string  $groupId  The usenet group ID
     * @param  string  $releaseName  The name of the release
     * @param  string|null  $poster  The poster name
     * @param  bool  $debug  Whether to include debug information
     * @param  list<int>  $associatedGroupIds  Every normalized group associated with the release
     * @return array<string, mixed> The categorization result
     */
    public function categorize(
        int|string $groupId,
        string $releaseName,
        ?string $poster = '',
        bool $debug = false,
        array $associatedGroupIds = [],
    ): array {
        $releaseName = $this->nzbSplitUnwrapper->unwrap($releaseName) ?? $releaseName;
        $releaseName = $this->obfuscatedSubjectExtractor->extract($releaseName) ?? $releaseName;

        $groups = UsenetGroup::query()
            ->whereIn('id', $this->forcedRootPolicy->groupIds($groupId, $associatedGroupIds))
            ->get([
                'id',
                'name',
                'route_obfuscated_names',
                'obfuscated_default_root_categories_id',
                'forced_root_categories_id',
            ]);
        $group = $groups->first(
            static fn (UsenetGroup $candidate): bool => (int) $candidate->id === (int) $groupId,
        ) ?? new UsenetGroup;
        $groupName = (string) ($group->name ?? '');
        $obfuscatedDefaultRootCategoryId = $group->obfuscated_default_root_categories_id;
        $forcedRootCategoryId = $this->forcedRootPolicy->select($groupId, $groups);

        $context = new ReleaseContext(
            releaseName: $releaseName,
            groupId: $groupId,
            groupName: $groupName,
            poster: $poster ?? '',
            categorizeForeign: $this->categorizeForeign,
            catWebDL: $this->catWebDL,
            routeObfuscatedNames: (bool) ($group->route_obfuscated_names ?? false),
            obfuscatedDefaultRootCategoryId: $obfuscatedDefaultRootCategoryId === null
                ? null
                : (int) $obfuscatedDefaultRootCategoryId,
            forcedRootCategoryId: $forcedRootCategoryId === null
                ? null
                : (int) $forcedRootCategoryId,
        );

        $passable = new CategorizationPassable($context, $debug);

        /** @var CategorizationPassable $result */
        $result = app(Pipeline::class)
            ->send($passable)
            ->through($this->pipes->values()->all())
            ->thenReturn();

        $this->applyForcedRootCategory($result);

        $this->logCategorization($result);

        return $result->toArray();
    }

    /**
     * Pin the result to the group's forced root category, when one is configured.
     *
     * This is a finalization step rather than a pipe on purpose:
     * {@see AbstractCategorizationPipe::handle()} skips its body once the
     * running best result reaches 0.95 confidence, so a pipe would silently not
     * fire in exactly the high-confidence cases the override exists for. Running
     * after the pipeline also means every caller of {@see self::categorize()} —
     * release creation, renames, `nntmux:recategorize-releases` — gets the same
     * behaviour.
     *
     * Two results survive: one that already belongs to the forced root (specific
     * beats generic Other), and a hashed or misc-locked name, which stays on the
     * existing obfuscated-routing path.
     */
    protected function applyForcedRootCategory(CategorizationPassable $result): void
    {
        $rootCategoryId = $result->context->forcedRootCategoryId;

        if ($rootCategoryId === null ||
            $result->lockedToMisc ||
            $result->bestResult->categoryId === Category::OTHER_HASHED ||
            Category::rootCategoryFor($result->bestResult->categoryId) === $rootCategoryId) {
            return;
        }

        $categoryId = Category::otherForRootCategory($rootCategoryId);

        if ($categoryId === null) {
            return;
        }

        $organic = $result->bestResult;

        $result->bestResult = new CategorizationResult(
            $categoryId,
            0.95,
            'group_forced_root',
            [
                'root_category_id' => $rootCategoryId,
                'organic_category_id' => $organic->categoryId,
                'organic_match' => $organic->matchedBy,
            ],
        );

        if ($result->debug) {
            $result->allResults['GroupForcedRoot'] = [
                'category_id' => $result->bestResult->categoryId,
                'confidence' => $result->bestResult->confidence,
                'matched_by' => $result->bestResult->matchedBy,
                'suppressed' => [
                    'category_id' => $organic->categoryId,
                    'confidence' => $organic->confidence,
                    'matched_by' => $organic->matchedBy,
                ],
            ];
        }
    }

    protected function logCategorization(CategorizationPassable $result): void
    {
        if (! config('nntmux.categorization.log', false)) {
            return;
        }

        $payload = [
            'release_name' => $result->context->releaseName,
            'group_name' => $result->context->groupName,
            'category_id' => $result->bestResult->categoryId,
            'matched_by' => $result->bestResult->matchedBy,
            'confidence' => $result->bestResult->confidence,
            'locked_to_misc' => $result->lockedToMisc,
            'misc_analysis' => $result->miscAnalysis,
        ];

        if ($result->lockedToMisc || $result->bestResult->matchedBy === 'group_only_low_signal') {
            Log::info('categorization.decision', $payload);
        }

        Log::debug('categorization.trace', $payload + ['all_results' => $result->allResults]);
    }

    /**
     * Get all registered categorizers (pipes).
     *
     * @return Collection<AbstractCategorizationPipe>
     */
    public function getCategorizers(): Collection // @phpstan-ignore missingType.generics
    {
        return $this->pipes;
    }

    /**
     * Create a default pipeline with all standard categorizers.
     */
    public static function createDefault(): self
    {
        return new self([
            new Pipes\MiscPipe,
            new Pipes\GroupObfuscatedRoutingPipe,
            new Pipes\GroupNamePipe,
            new Pipes\XxxPipe,
            new Pipes\TvPipe,
            new Pipes\MoviePipe,
            new Pipes\BookPipe,
            new Pipes\MusicPipe,
            new Pipes\PcPipe,
            new Pipes\ConsolePipe,
            new Pipes\MiscSafetyNetPipe,
        ]);
    }
}
