<?php

declare(strict_types=1);

namespace Tests\Unit\Categorization;

use App\Models\Category;
use App\Services\Categorization\Arbitration\CategoryArbitrationSubject;
use App\Services\Categorization\Arbitration\CategoryArbitrationUseCase;
use App\Services\Categorization\Arbitration\CategoryVerdict;
use App\Services\Categorization\ReleaseContext;
use App\Services\DecisionModel\DecisionAnswer;
use App\Services\DecisionModel\DecisionResponse;
use App\Services\DecisionModel\Exceptions\MalformedDecisionAnswer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * What the category use case sends for a release and what it makes of the
 * model's answers, against the criteria file in the repository.
 */
final class CategoryArbitrationUseCaseTest extends TestCase
{
    private const float THRESHOLD = 0.80;

    public function test_it_reports_the_criteria_file_version(): void
    {
        $useCase = new CategoryArbitrationUseCase;

        $this->assertSame('category_arbitration', $useCase->name());
        $this->assertSame(
            'v2 2026-10-12: leaf priority order, no webdl leaf, dated adult scenes = x264, HDR+HEVC = uhd, spaced film names',
            $useCase->criteriaVersion(),
        );
    }

    public function test_the_state_is_the_name_as_posted_with_the_newsgroup_and_poster(): void
    {
        $subject = $this->subject(new ReleaseContext(
            releaseName: 'Invented Show 03 1080p',
            groupId: 7,
            groupName: 'alt.binaries.invented',
            poster: 'poster@example.test',
            preExtractionName: '[InventedSubs] Invented.Show.-.03.(1080p)',
        ));

        $this->assertSame(
            [
                'release_name' => '[InventedSubs] Invented.Show.-.03.(1080p)',
                'newsgroup' => 'alt.binaries.invented',
                'poster' => 'poster@example.test',
            ],
            (new CategoryArbitrationUseCase)->state($subject),
        );
    }

    public function test_the_state_omits_empty_fields(): void
    {
        $subject = $this->subject(new ReleaseContext(releaseName: 'Invented Film 2019', groupId: 0));

        $this->assertSame(['release_name' => 'Invented Film 2019'], (new CategoryArbitrationUseCase)->state($subject));
    }

    public function test_the_first_request_asks_the_root_and_the_two_yes_or_no_questions(): void
    {
        $questions = (new CategoryArbitrationUseCase)->questions($this->subject(), []);

        $this->assertSame(['root', 'readable', 'adult'], array_keys($questions));

        $root = $questions['root']->toArray();
        $this->assertSame('choice', $root['type']);
        $this->assertStringStartsWith('Which kind of content is this Usenet release?', $root['instructions']);
        $this->assertSame(['tv', 'movies', 'audio', 'xxx', 'games', 'pc', 'books', 'other'], array_keys($root['criteria'] ?? []));

        $this->assertSame(
            ['type' => 'noul', 'instructions' => 'Does the release name describe adult pornographic content?'],
            $questions['adult']->toArray(),
        );
        $this->assertSame('noul', $questions['readable']->toArray()['type']);
    }

    public function test_the_second_request_asks_the_subcategory_of_the_answered_root(): void
    {
        $questions = (new CategoryArbitrationUseCase)->questions($this->subject(), [$this->rootResponse('books', 0.4)]);

        $this->assertSame(['sub'], array_keys($questions));
        $sub = $questions['sub']->toArray();
        $this->assertSame('choice', $sub['type']);
        $this->assertSame('Which Books subcategory fits this release?', $sub['instructions']);
        $this->assertSame(['magazines', 'comics', 'technical', 'foreign', 'ebook', 'unknown'], array_keys($sub['criteria'] ?? []));
    }

    public function test_nothing_more_is_asked_after_the_other_root_or_after_two_requests(): void
    {
        $useCase = new CategoryArbitrationUseCase;

        $this->assertSame([], $useCase->questions($this->subject(), [$this->rootResponse('other', 0.99)]));
        $this->assertSame([], $useCase->questions($this->subject(), [$this->rootResponse('tv', 0.9), $this->subResponse('hd', 0.9)]));
        $this->assertSame([], $useCase->questions($this->subject(), [new DecisionResponse(['root' => DecisionAnswer::missing()])]));
    }

    /**
     * @return array<string, array{string, float, string|null, float|null, int|null, string}>
     */
    public static function interpretations(): array
    {
        return [
            'root and leaf exactly at the threshold are accepted' => ['tv', 0.80, 'hd', 0.80, Category::TV_HD, 'accepted'],
            'root just below the threshold keeps the pipeline result' => ['tv', 0.7999, 'hd', 0.99, null, 'root_below_threshold'],
            'the other root keeps the pipeline result' => ['other', 0.99, null, null, null, 'root_other'],
            'a leaf below the threshold falls to the root Other' => ['movies', 0.93, 'bluray', 0.7999, Category::MOVIE_OTHER, 'accepted'],
            'a confident leaf is taken' => ['xxx', 0.93, 'vr', 0.91, Category::XXX_VR, 'accepted'],
            'a missing subcategory answer falls to the root Other' => ['audio', 0.93, null, null, Category::MUSIC_OTHER, 'accepted'],
            'the books root falls to Books Unknown' => ['books', 0.88, null, null, Category::BOOKS_UNKNOWN, 'accepted'],
            'an unsure leaf the criteria do not offer falls to the root Other' => ['games', 0.9, 'ps9', 0.5, Category::GAME_OTHER, 'accepted'],
            'a key starting with a digit resolves' => ['pc', 0.9, '0day', 0.9, Category::PC_0DAY, 'accepted'],
        ];
    }

    #[DataProvider('interpretations')]
    public function test_it_interprets_the_answers(
        string $root,
        float $rootConfidence,
        ?string $leaf,
        ?float $leafConfidence,
        ?int $expectedCategoryId,
        string $expectedReason,
    ): void {
        $responses = [$this->rootResponse($root, $rootConfidence)];
        if ($leaf !== null && $leafConfidence !== null) {
            $responses[] = $this->subResponse($leaf, $leafConfidence);
        }

        $verdict = (new CategoryArbitrationUseCase)->interpret($this->subject(), $responses);

        $this->assertInstanceOf(CategoryVerdict::class, $verdict);
        $this->assertSame($expectedCategoryId, $verdict->categoryId);
        $this->assertSame($expectedReason, $verdict->reason);
        $this->assertSame($expectedCategoryId !== null, $verdict->accepted());
        $this->assertSame($rootConfidence, $verdict->confidence);
        $this->assertSame($root, $verdict->root);
    }

    public function test_a_subcategory_answer_that_failed_falls_to_the_root_other(): void
    {
        $verdict = (new CategoryArbitrationUseCase)->interpret($this->subject(), [
            $this->rootResponse('tv', 0.95),
            new DecisionResponse(['sub' => DecisionAnswer::fromArray(['error' => 'too_long'])]),
        ]);

        $this->assertSame(Category::TV_OTHER, $verdict->categoryId);
    }

    public function test_a_confident_subcategory_the_criteria_do_not_offer_is_malformed(): void
    {
        try {
            (new CategoryArbitrationUseCase)->interpret($this->subject(), [$this->rootResponse('games', 0.9), $this->subResponse('ps9', 0.99)]);
            $this->fail('A subcategory with no category id must not be interpreted.');
        } catch (MalformedDecisionAnswer $failure) {
            $this->assertSame('subcategory_without_category', $failure->reason);
        }
    }

    public function test_the_forced_root_wins_over_a_different_root_whatever_the_confidence(): void
    {
        $subject = $this->subject(new ReleaseContext(
            releaseName: 'Invented Show 2019',
            groupId: 7,
            forcedRootCategoryId: Category::TV_ROOT,
        ));
        $useCase = new CategoryArbitrationUseCase;

        $different = $useCase->interpret($subject, [$this->rootResponse('movies', 1.0), $this->subResponse('hd', 1.0)]);
        $this->assertNull($different->categoryId);
        $this->assertSame('forced_root', $different->reason);

        $same = $useCase->interpret($subject, [$this->rootResponse('tv', 0.9), $this->subResponse('anime', 0.9)]);
        $this->assertSame(Category::TV_ANIME, $same->categoryId);
        $this->assertSame('accepted', $same->reason);
    }

    public function test_the_verdict_is_logged_with_both_levels(): void
    {
        $verdict = (new CategoryArbitrationUseCase)->interpret(
            $this->subject(),
            [$this->rootResponse('movies', 0.93), $this->subResponse('bluray', 0.61)],
        );

        $this->assertSame(
            [
                'category_id' => Category::MOVIE_OTHER,
                'reason' => 'accepted',
                'root' => 'movies',
                'root_confidence' => 0.93,
                'leaf' => 'bluray',
                'leaf_confidence' => 0.61,
                'apply_threshold' => 0.8,
            ],
            $verdict->toArray(),
        );
    }

    /**
     * @return array<string, array{DecisionResponse|null}>
     */
    public static function unusableRootAnswers(): array
    {
        return [
            'no response at all' => [null],
            'the root question failed' => [new DecisionResponse(['root' => DecisionAnswer::fromArray(['error' => 'refused'])])],
            'no choice' => [new DecisionResponse(['root' => DecisionAnswer::fromArray(['confidence' => 0.9])])],
            'no confidence' => [new DecisionResponse(['root' => DecisionAnswer::fromArray(['choice' => 'tv'])])],
            'a root the criteria do not offer' => [new DecisionResponse(['root' => DecisionAnswer::fromArray(['choice' => 'radio', 'confidence' => 0.9])])],
        ];
    }

    #[DataProvider('unusableRootAnswers')]
    public function test_an_unusable_root_answer_is_malformed(?DecisionResponse $response): void
    {
        $this->expectException(MalformedDecisionAnswer::class);

        (new CategoryArbitrationUseCase)->interpret($this->subject(), $response === null ? [] : [$response]);
    }

    public function test_every_option_the_criteria_offer_resolves_to_a_category_in_its_root(): void
    {
        $roots = [
            'tv' => Category::TV_ROOT,
            'movies' => Category::MOVIE_ROOT,
            'audio' => Category::MUSIC_ROOT,
            'xxx' => Category::XXX_ROOT,
            'games' => Category::GAME_ROOT,
            'pc' => Category::PC_ROOT,
            'books' => Category::BOOKS_ROOT,
        ];
        $useCase = new CategoryArbitrationUseCase;

        foreach ($roots as $root => $rootCategoryId) {
            $options = array_keys($useCase->questions($this->subject(), [$this->rootResponse($root, 0.9)])['sub']->toArray()['criteria'] ?? []);
            $this->assertNotSame([], $options);

            foreach ($options as $option) {
                $verdict = $useCase->interpret($this->subject(), [$this->rootResponse($root, 0.9), $this->subResponse((string) $option, 0.9)]);

                $this->assertNotNull($verdict->categoryId, "{$root}/{$option} has no category id.");
                $this->assertSame($rootCategoryId, Category::rootCategoryFor($verdict->categoryId), "{$root}/{$option} resolves outside its root.");
            }
        }
    }

    private function subject(?ReleaseContext $context = null): CategoryArbitrationSubject
    {
        return new CategoryArbitrationSubject(
            $context ?? new ReleaseContext(releaseName: 'Invented Name 2019', groupId: 0),
            self::THRESHOLD,
        );
    }

    private function rootResponse(string $root, float $confidence): DecisionResponse
    {
        return new DecisionResponse([
            'root' => DecisionAnswer::fromArray(['choice' => $root, 'probabilities' => [$root => $confidence], 'confidence' => $confidence]),
            'readable' => DecisionAnswer::fromArray(['noul' => 0.9]),
            'adult' => DecisionAnswer::fromArray(['noul' => 0.1]),
        ]);
    }

    private function subResponse(string $leaf, float $confidence): DecisionResponse
    {
        return new DecisionResponse([
            'sub' => DecisionAnswer::fromArray(['choice' => $leaf, 'probabilities' => [$leaf => $confidence], 'confidence' => $confidence]),
        ]);
    }
}
