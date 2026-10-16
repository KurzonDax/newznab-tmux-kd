<?php

declare(strict_types=1);

namespace Tests\Unit\Categorization;

use App\Models\Category;
use App\Services\Categorization\Arbitration\CategoryArbitrationSubject;
use App\Services\Categorization\Arbitration\CategoryArbitrationUseCase;
use App\Services\Categorization\Arbitration\CategoryVerdict;
use App\Services\Categorization\MediaInfoRefinementService;
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
            'v3 2026-10-16: name-only state, content-type subcategories with music video kept, foreign = no English audio (separate yes/no for tv and movies), subcategory accepted on its probability',
            $useCase->criteriaVersion(),
        );
    }

    public function test_the_state_is_the_name_as_posted(): void
    {
        $subject = $this->subject(new ReleaseContext(
            releaseName: 'Invented Show 03 1080p',
            groupId: 7,
            groupName: 'alt.binaries.invented',
            poster: 'poster@example.test',
            preExtractionName: '[InventedSubs] Invented.Show.-.03.(1080p)',
        ));

        $this->assertSame(
            ['release_name' => '[InventedSubs] Invented.Show.-.03.(1080p)'],
            (new CategoryArbitrationUseCase)->state($subject),
        );
    }

    public function test_the_newsgroup_and_poster_are_never_sent(): void
    {
        $useCase = new CategoryArbitrationUseCase;

        foreach ([
            new ReleaseContext(releaseName: 'Invented Film 2019', groupId: 7, groupName: 'alt.binaries.invented'),
            new ReleaseContext(releaseName: 'Invented Film 2019', groupId: 0, poster: 'poster@example.test'),
        ] as $context) {
            $this->assertSame(['release_name' => 'Invented Film 2019'], $useCase->state($this->subject($context)));
        }
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

    /**
     * @return array<string, array{string, list<string>, list<string>}>
     */
    public static function secondRequests(): array
    {
        return [
            'tv' => ['tv', ['sub', 'foreign'], ['anime', 'sport', 'documentary', 'other']],
            'movies' => ['movies', ['sub', 'foreign'], ['3d', 'other']],
            'audio' => ['audio', ['sub'], ['audiobook', 'podcast', 'video', 'foreign', 'other']],
            'xxx' => ['xxx', ['sub'], ['vr', 'imageset', 'pack', 'clipshd', 'clipssd', 'other']],
            'games' => ['games', ['sub'], ['ps4', 'ps3', 'psvita', 'psp', 'xboxone', 'xbox360', 'xbox', 'wiiu', 'wii', '3ds', 'nds', 'other']],
            'pc' => ['pc', ['sub'], ['phone_ios', 'phone_android', 'phone_other', 'mac', 'games', 'iso', '0day', 'other']],
            'books' => ['books', ['sub'], ['magazines', 'comics', 'technical', 'foreign', 'ebook', 'unknown']],
        ];
    }

    /**
     * @param  list<string>  $expectedQuestions
     * @param  list<string>  $expectedOptions
     */
    #[DataProvider('secondRequests')]
    public function test_the_second_request_asks_the_subcategory_and_for_films_and_television_whether_it_is_foreign(
        string $root,
        array $expectedQuestions,
        array $expectedOptions,
    ): void {
        $questions = (new CategoryArbitrationUseCase)->questions($this->subject(), [$this->rootResponse($root, 0.9)]);

        $this->assertSame($expectedQuestions, array_keys($questions));
        $this->assertSame($expectedOptions, array_map('strval', array_keys($questions['sub']->toArray()['criteria'] ?? [])));

        if (isset($questions['foreign'])) {
            $foreign = $questions['foreign']->toArray();
            $this->assertSame('noul', $foreign['type']);
            $this->assertSame('Is this film or series in a language other than English, with no English audio?', $foreign['instructions']);
            $this->assertSame(['true', 'false'], array_keys($foreign['criteria'] ?? []));
            $this->assertStringStartsWith('It is in another language and has no English audio', $foreign['criteria']['true'] ?? '');
            $this->assertStringStartsWith('It is in English', $foreign['criteria']['false'] ?? '');
        }
    }

    public function test_nothing_more_is_asked_after_the_other_root_or_after_two_requests(): void
    {
        $useCase = new CategoryArbitrationUseCase;

        $this->assertSame([], $useCase->questions($this->subject(), [$this->rootResponse('other', 0.99)]));
        $this->assertSame([], $useCase->questions($this->subject(), [$this->rootResponse('tv', 0.9), $this->subResponse('anime', 0.9)]));
        $this->assertSame([], $useCase->questions($this->subject(), [new DecisionResponse(['root' => DecisionAnswer::missing()])]));
    }

    /**
     * @return array<string, array{string, float, string|null, float|null, int|null, string}>
     */
    public static function interpretations(): array
    {
        return [
            'root and leaf exactly at the threshold are accepted' => ['tv', 0.80, 'anime', 0.80, Category::TV_ANIME, 'accepted'],
            'root just below the threshold keeps the pipeline result' => ['tv', 0.7999, 'anime', 0.99, null, 'root_below_threshold'],
            'the other root keeps the pipeline result' => ['other', 0.99, null, null, null, 'root_other'],
            'a leaf below the threshold falls to the root Other' => ['movies', 0.93, '3d', 0.7999, Category::MOVIE_OTHER, 'accepted'],
            'a confident music video is taken' => ['audio', 0.93, 'video', 0.90, Category::MUSIC_VIDEO, 'accepted'],
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

    /**
     * Each case answers the root tv or movies at 0.93. Foreign is the yes
     * probability, 'failed' for a failed answer, or null when it is missing.
     *
     * @return array<string, array{string, string|null, float, float, float|string|null, int}>
     */
    public static function subcategoryAndForeignInterpretations(): array
    {
        return [
            'tv takes a confident choice before foreign' => ['tv', 'anime', 0.85, 0.85, 0.90, Category::TV_ANIME],
            'tv falls to foreign when the choice is other' => ['tv', 'other', 0.90, 0.90, 0.90, Category::TV_FOREIGN],
            'tv with neither above the threshold falls to TV Other' => ['tv', 'sport', 0.79, 0.79, 0.79, Category::TV_OTHER],
            'movies take foreign before a confident choice' => ['movies', '3d', 0.90, 0.90, 0.90, Category::MOVIE_FOREIGN],
            'movies take the choice when not foreign' => ['movies', '3d', 0.90, 0.90, 0.10, Category::MOVIE_3D],
            'movies with neither above the threshold fall to Movies Other' => ['movies', 'other', 0.95, 0.95, 0.79, Category::MOVIE_OTHER],
            'tv with a failed foreign answer takes the choice' => ['tv', 'anime', 0.85, 0.85, 'failed', Category::TV_ANIME],
            'movies with a failed foreign answer fall to Movies Other' => ['movies', 'other', 0.95, 0.95, 'failed', Category::MOVIE_OTHER],
            'movies with a missing foreign answer take the choice' => ['movies', '3d', 0.90, 0.90, null, Category::MOVIE_3D],
            'the choice is judged by its probability, not its confidence' => ['tv', 'anime', 0.92, 0.75, null, Category::TV_ANIME],
            'tv falls to foreign when the choice is below the threshold' => ['tv', 'sport', 0.60, 0.95, 0.85, Category::TV_FOREIGN],
            'tv falls to foreign when the subcategory answer is missing' => ['tv', null, 0.0, 0.0, 0.85, Category::TV_FOREIGN],
        ];
    }

    #[DataProvider('subcategoryAndForeignInterpretations')]
    public function test_it_interprets_the_subcategory_and_foreign_answers(
        string $root,
        ?string $leaf,
        float $leafProbability,
        float $leafConfidence,
        float|string|null $foreign,
        int $expectedCategoryId,
    ): void {
        $verdict = (new CategoryArbitrationUseCase)->interpret($this->subject(), [
            $this->rootResponse($root, 0.93),
            $this->secondResponse($leaf, $leafProbability, $leafConfidence, $foreign),
        ]);

        $this->assertSame($expectedCategoryId, $verdict->categoryId);
        $this->assertSame('accepted', $verdict->reason);
    }

    public function test_an_adult_choice_is_judged_by_its_probability_not_its_confidence(): void
    {
        $verdict = (new CategoryArbitrationUseCase)->interpret($this->subject(), [
            $this->rootResponse('xxx', 0.93),
            $this->secondResponse('clipshd', 0.79, 0.95),
        ]);

        $this->assertSame(Category::XXX_OTHER, $verdict->categoryId);
    }

    public function test_the_root_is_still_judged_by_its_confidence_not_its_probability(): void
    {
        $verdict = (new CategoryArbitrationUseCase)->interpret($this->subject(), [
            $this->rootResponse('tv', 0.79, probability: 0.95),
            $this->secondResponse('anime', 0.95, 0.95, 0.95),
        ]);

        $this->assertNull($verdict->categoryId);
        $this->assertSame('root_below_threshold', $verdict->reason);
    }

    public function test_a_choice_without_its_probability_falls_to_the_root_other(): void
    {
        $verdict = (new CategoryArbitrationUseCase)->interpret($this->subject(), [
            $this->rootResponse('xxx', 0.93),
            new DecisionResponse(['sub' => DecisionAnswer::fromArray(['choice' => 'vr', 'probabilities' => ['other' => 0.1], 'confidence' => 0.99])]),
        ]);

        $this->assertSame(Category::XXX_OTHER, $verdict->categoryId);
    }

    /**
     * Media probing refuses to promote feature-length high-definition video
     * out of Audio Other, so only the model's answer files such a concert as
     * a music video.
     */
    public function test_a_feature_length_hd_concert_is_filed_as_a_music_video_by_the_model_alone(): void
    {
        $media = ['videowidth' => 1920, 'videoheight' => 1080, 'videoduration' => '1h:30m:00s'];
        $refinement = new MediaInfoRefinementService;

        $verdict = (new CategoryArbitrationUseCase)->interpret(
            $this->subject(new ReleaseContext(releaseName: 'Invented Band Live At Invented Arena 2019 1080p BluRay x264', groupId: 0)),
            [$this->rootResponse('audio', 0.93), $this->secondResponse('video', 0.90, 0.70)],
        );

        $this->assertSame(Category::MUSIC_VIDEO, $verdict->categoryId);
        $this->assertNull($refinement->decisionFor(Category::MUSIC_VIDEO, $media, null), 'Probing leaves the music video alone.');
        $this->assertNull($refinement->decisionFor(Category::MUSIC_OTHER, $media, null), 'Probing cannot recover it from Audio Other.');
    }

    public function test_a_subcategory_answer_that_failed_falls_to_the_root_other(): void
    {
        $verdict = (new CategoryArbitrationUseCase)->interpret($this->subject(), [
            $this->rootResponse('tv', 0.95),
            new DecisionResponse(['sub' => DecisionAnswer::fromArray(['error' => 'too_long'])]),
        ]);

        $this->assertSame(Category::TV_OTHER, $verdict->categoryId);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unofferedConfidentSubcategories(): array
    {
        return [
            'an invented key' => ['games', 'ps9'],
            'other where the root offers unknown instead' => ['books', 'other'],
        ];
    }

    #[DataProvider('unofferedConfidentSubcategories')]
    public function test_a_confident_subcategory_the_criteria_do_not_offer_is_malformed(string $root, string $leaf): void
    {
        try {
            (new CategoryArbitrationUseCase)->interpret($this->subject(), [$this->rootResponse($root, 0.9), $this->subResponse($leaf, 0.99)]);
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

        $different = $useCase->interpret($subject, [$this->rootResponse('movies', 1.0), $this->subResponse('3d', 1.0)]);
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
            [$this->rootResponse('movies', 0.93), $this->secondResponse('3d', 0.61, 0.4, 0.2)],
        );

        $this->assertSame(
            [
                'category_id' => Category::MOVIE_OTHER,
                'reason' => 'accepted',
                'root' => 'movies',
                'root_confidence' => 0.93,
                'leaf' => '3d',
                'leaf_probability' => 0.61,
                'leaf_confidence' => 0.4,
                'foreign' => 0.2,
                'apply_threshold' => 0.8,
            ],
            $verdict->toArray(),
        );
    }

    public function test_the_verdict_logs_no_foreign_value_when_the_question_was_not_asked(): void
    {
        $verdict = (new CategoryArbitrationUseCase)->interpret(
            $this->subject(),
            [$this->rootResponse('xxx', 0.93), $this->secondResponse('vr', 0.91, 0.85)],
        );

        $this->assertSame(
            ['leaf' => 'vr', 'leaf_probability' => 0.91, 'leaf_confidence' => 0.85, 'foreign' => null],
            array_intersect_key($verdict->toArray(), array_flip(['leaf', 'leaf_probability', 'leaf_confidence', 'foreign'])),
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
            $questions = $useCase->questions($this->subject(), [$this->rootResponse($root, 0.9)]);
            $options = array_keys($questions['sub']->toArray()['criteria'] ?? []);
            $this->assertNotSame([], $options);

            if (isset($questions['foreign'])) {
                $verdict = $useCase->interpret($this->subject(), [$this->rootResponse($root, 0.9), $this->secondResponse(null, 0.0, 0.0, 0.9)]);

                $this->assertNotNull($verdict->categoryId, "{$root}/foreign has no category id.");
                $this->assertSame($rootCategoryId, Category::rootCategoryFor($verdict->categoryId), "{$root}/foreign resolves outside its root.");
            }

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

    private function rootResponse(string $root, float $confidence, ?float $probability = null): DecisionResponse
    {
        return new DecisionResponse([
            'root' => DecisionAnswer::fromArray(['choice' => $root, 'probabilities' => [$root => $probability ?? $confidence], 'confidence' => $confidence]),
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

    /**
     * The second request's answers: the subcategory with its probability and
     * confidence set apart (none when the leaf is null), and the foreign yes
     * probability, 'failed' for a failed answer, or none when null.
     */
    private function secondResponse(?string $leaf, float $probability, float $confidence, float|string|null $foreign = null): DecisionResponse
    {
        $answers = [];
        if ($leaf !== null) {
            $answers['sub'] = DecisionAnswer::fromArray(['choice' => $leaf, 'probabilities' => [$leaf => $probability], 'confidence' => $confidence]);
        }
        if ($foreign !== null) {
            $answers['foreign'] = DecisionAnswer::fromArray(is_float($foreign) ? ['noul' => $foreign] : ['error' => $foreign]);
        }

        return new DecisionResponse($answers);
    }
}
