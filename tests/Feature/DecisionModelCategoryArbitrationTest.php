<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DecisionModelDecision;
use App\Services\Categorization\Arbitration\CategoryArbiter;
use App\Services\Categorization\CategorizationService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/**
 * Category arbitration by the decision model, through the whole pipeline as
 * every caller reaches it. The service is always faked.
 */
final class DecisionModelCategoryArbitrationTest extends TestCase
{
    use IsolatedSqliteDatabase;

    private const string ENDPOINT = 'https://decision.example.test/v1/systemone';

    private const string CRITERIA_VERSION = 'v2 2026-10-12: leaf priority order, no webdl leaf, dated adult scenes = x264, HDR+HEVC = uhd, spaced film names';

    /** A readable name no categorizer matches: the pipeline files it Misc at 0.0. */
    private const string UNMATCHED_NAME = 'An.Invented.Evening.With.Nobody.In.Particular';

    private const int PLAIN_GROUP_ID = 31;

    private const int TV_FORCED_GROUP_ID = 32;

    private const int TV_NAMED_FORCED_GROUP_ID = 33;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();

        $tables = ProductionTables::fromAuthority();
        $tables->create('decision_model_criteria', null);
        $tables->create('decision_model_decisions', null);
        $tables->create('releases_groups', null);
        $tables->create('usenet_groups', [
            'id',
            'name',
            'route_obfuscated_names',
            'obfuscated_default_root_categories_id',
            'forced_root_categories_id',
        ]);

        DB::table('usenet_groups')->insert([
            ['id' => self::PLAIN_GROUP_ID, 'name' => 'alt.binaries.invented', 'forced_root_categories_id' => null],
            ['id' => self::TV_FORCED_GROUP_ID, 'name' => 'alt.binaries.invented.two', 'forced_root_categories_id' => Category::TV_ROOT],
            ['id' => self::TV_NAMED_FORCED_GROUP_ID, 'name' => 'alt.binaries.invented.tv', 'forced_root_categories_id' => Category::TV_ROOT],
        ]);

        DB::table('settings')->insert([
            ['name' => 'decision_model_mode', 'value' => 'off'],
            ['name' => 'decision_model_apply_threshold', 'value' => '0.80'],
            ['name' => 'decision_model_arbitrate_below', 'value' => '0.70'],
        ]);

        config([
            'decision-model.enabled' => true,
            'decision-model.base_url' => 'https://decision.example.test',
            'decision-model.api_key' => 'invented-token',
            'decision-model.retry_attempts' => 3,
            'decision-model.circuit_breaker_failures' => 3,
        ]);

        Http::preventStrayRequests();
        Sleep::fake();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_off_sends_nothing_and_returns_the_pipeline_category(): void
    {
        Http::fake();

        $result = $this->categorize(self::UNMATCHED_NAME, debug: true);

        $this->assertSame(Category::OTHER_MISC, $result['categories_id']);
        $this->assertSame(0.0, $result['debug']['final_confidence']);
        $this->assertArrayNotHasKey('DecisionModel', $result['debug']['all_results']);
        $this->assertArrayNotHasKey('decision_id', $result);
        Http::assertNothingSent();
        $this->assertSame(0, DecisionModelDecision::query()->count());
    }

    public function test_off_returns_only_the_category_without_debug(): void
    {
        Http::fake();

        $this->assertSame(['categories_id' => Category::OTHER_MISC], $this->categorize(self::UNMATCHED_NAME));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function modesThatAsk(): array
    {
        return ['shadow' => ['shadow'], 'apply' => ['apply']];
    }

    #[DataProvider('modesThatAsk')]
    public function test_a_disabled_client_sends_nothing_whatever_the_mode(string $mode): void
    {
        $this->mode($mode);
        config(['decision-model.enabled' => false]);
        Http::fake();

        $this->assertSame(Category::OTHER_MISC, $this->categorize(self::UNMATCHED_NAME)['categories_id']);

        Http::assertNothingSent();
        $this->assertSame(0, DecisionModelDecision::query()->count());
    }

    public function test_shadow_logs_the_decision_and_returns_the_pipeline_category(): void
    {
        $this->mode('shadow');
        $this->fakeAnswers(['tv', 0.93], ['hd', 0.9]);

        $result = $this->categorize(self::UNMATCHED_NAME, debug: true, releaseId: 4107);

        $this->assertSame(Category::OTHER_MISC, $result['categories_id']);
        $this->assertSame('no_match', $result['debug']['matched_by']);
        $this->assertArrayNotHasKey('DecisionModel', $result['debug']['all_results']);
        Http::assertSentCount(2);

        $row = DecisionModelDecision::query()->with('criteria')->sole();
        $this->assertSame($row->id, $result['decision_id']);
        $this->assertSame('category_arbitration', $row->criteria?->use_case);
        $this->assertSame(self::CRITERIA_VERSION, $row->criteria?->version);
        $this->assertSame(['release', 4107, 'shadow', false, null], [
            $row->subject_type, $row->subject_id, $row->mode, $row->applied, $row->failure_reason,
        ]);
        $this->assertSame(
            ['release_name' => self::UNMATCHED_NAME, 'newsgroup' => 'alt.binaries.invented', 'poster' => 'poster@example.test'],
            $row->state,
        );
        $this->assertSame(
            [
                [
                    'root' => ['choice' => 'tv', 'probabilities' => ['tv' => 0.93], 'confidence' => 0.93],
                    'readable' => ['noul' => 0.97],
                    'adult' => ['noul' => 0.02],
                ],
                ['sub' => ['choice' => 'hd', 'probabilities' => ['hd' => 0.9], 'confidence' => 0.9]],
            ],
            $row->answers,
        );
        $this->assertSame(
            ['category_id' => Category::OTHER_MISC, 'confidence' => 0, 'matched_by' => 'no_match'],
            $row->pipeline_answer,
        );
        $this->assertSame(Category::TV_HD, $row->interpretation['category_id'] ?? null);
        $this->assertSame('accepted', $row->interpretation['reason'] ?? null);
    }

    public function test_apply_returns_the_model_category_and_logs_an_applied_row(): void
    {
        $this->mode('apply');
        $this->fakeAnswers(['tv', 0.93], ['hd', 0.9]);

        $result = $this->categorize(self::UNMATCHED_NAME, debug: true);

        $this->assertSame(Category::TV_HD, $result['categories_id']);
        $this->assertSame(Category::TV_HD, $result['debug']['final_category']);
        $this->assertSame(0.93, $result['debug']['final_confidence']);
        $this->assertSame('decision_model', $result['debug']['matched_by']);

        $entry = $result['debug']['all_results']['DecisionModel'];
        $this->assertSame(
            [Category::TV_HD, 0.93, 'decision_model', true, 'accepted'],
            [$entry['category_id'], $entry['confidence'], $entry['matched_by'], $entry['applied'], $entry['reason']],
        );
        $this->assertSame(
            ['category_id' => Category::OTHER_MISC, 'confidence' => 0.0, 'matched_by' => 'no_match'],
            $entry['suppressed'],
        );
        $this->assertSame('tv', $entry['answers'][0]['root']['choice']);
        $this->assertSame(0.97, $entry['answers'][0]['readable']['noul']);
        $this->assertSame('hd', $entry['answers'][1]['sub']['choice']);

        $row = DecisionModelDecision::query()->sole();
        $this->assertSame(['apply', true, null], [$row->mode, $row->applied, $row->subject_id]);
        $this->assertSame(Category::OTHER_MISC, $row->pipeline_answer['category_id'] ?? null);
    }

    public function test_apply_without_debug_returns_only_the_category(): void
    {
        $this->mode('apply');
        $this->fakeAnswers(['books', 0.88], ['ebook', 0.81]);

        $result = $this->categorize(self::UNMATCHED_NAME);

        $this->assertSame(
            ['categories_id' => Category::BOOKS_EBOOK, 'decision_id' => DecisionModelDecision::query()->sole()->id],
            $result,
        );
    }

    public function test_apply_files_an_unsure_leaf_in_the_root_other_category(): void
    {
        $this->mode('apply');
        $this->fakeAnswers(['movies', 0.91], ['bluray', 0.55]);

        $this->assertSame(Category::MOVIE_OTHER, $this->categorize(self::UNMATCHED_NAME)['categories_id']);
        $this->assertTrue(DecisionModelDecision::query()->sole()->applied);
    }

    public function test_apply_keeps_the_pipeline_category_when_the_model_is_unsure_of_the_root(): void
    {
        $this->mode('apply');
        $this->fakeAnswers(['tv', 0.79], ['hd', 0.99]);

        $result = $this->categorize(self::UNMATCHED_NAME, debug: true);

        $this->assertSame(Category::OTHER_MISC, $result['categories_id']);
        $this->assertSame('no_match', $result['debug']['matched_by']);

        $entry = $result['debug']['all_results']['DecisionModel'];
        $this->assertSame([null, false, 'root_below_threshold'], [$entry['category_id'], $entry['applied'], $entry['reason']]);
        $this->assertSame('tv', $entry['answers'][0]['root']['choice']);
        $this->assertArrayNotHasKey('suppressed', $entry);

        $row = DecisionModelDecision::query()->sole();
        $this->assertFalse($row->applied);
        $this->assertSame('root_below_threshold', $row->interpretation['reason'] ?? null);
    }

    public function test_apply_keeps_the_pipeline_category_when_the_model_answers_other(): void
    {
        $this->mode('apply');
        $this->fakeAnswers(['other', 0.97]);

        $this->assertSame(Category::OTHER_MISC, $this->categorize(self::UNMATCHED_NAME)['categories_id']);

        Http::assertSentCount(1);
        $this->assertFalse(DecisionModelDecision::query()->sole()->applied);
    }

    public function test_apply_overrules_a_weak_pipeline_guess(): void
    {
        $this->mode('apply');
        $this->fakeAnswers(['audio', 0.9], ['video', 0.86]);

        $result = $this->categorize('An Invented Evening Live 2019');

        $this->assertSame(Category::MUSIC_VIDEO, $result['categories_id']);

        $row = DecisionModelDecision::query()->sole();
        $this->assertSame(
            ['category_id' => Category::MOVIE_OTHER, 'confidence' => 0.5, 'matched_by' => 'year_only_movie'],
            $row->pipeline_answer,
        );
    }

    public function test_a_confident_pipeline_result_never_reaches_the_client(): void
    {
        $this->mode('apply');
        Http::fake();

        $result = $this->categorize('Invented.Show.S01E02.720p.HDTV.x264-GRP', debug: true);

        $this->assertSame(Category::TV_ROOT, Category::rootCategoryFor($result['categories_id']));
        $this->assertGreaterThanOrEqual(0.70, $result['debug']['final_confidence']);
        $this->assertArrayNotHasKey('decision_id', $result);
        Http::assertNothingSent();
        $this->assertSame(0, DecisionModelDecision::query()->count());
    }

    public function test_the_release_name_is_sent_as_posted_with_its_dots_and_group_tag(): void
    {
        $this->mode('shadow');
        $this->fakeAnswers(['other', 0.9]);

        $this->categorize('[InventedTag] An.Invented_Evening.With.Nobody.In.Particular');

        Http::assertSent(static fn (Request $request): bool => $request->url() === self::ENDPOINT
            && $request['state'] === [
                'release_name' => '[InventedTag] An.Invented_Evening.With.Nobody.In.Particular',
                'newsgroup' => 'alt.binaries.invented',
                'poster' => 'poster@example.test',
            ]);
        $this->assertSame(
            '[InventedTag] An.Invented_Evening.With.Nobody.In.Particular',
            DecisionModelDecision::query()->sole()->state['release_name'] ?? null,
        );
    }

    public function test_the_second_request_asks_the_subcategory_of_the_answered_root_about_the_same_state(): void
    {
        $this->mode('shadow');
        $this->fakeAnswers(['xxx', 0.6], ['x264', 0.7]);

        $this->categorize(self::UNMATCHED_NAME);

        $requests = Http::recorded()->map(static fn (array $pair): Request => $pair[0])->values();
        $this->assertCount(2, $requests);
        $this->assertSame(['root', 'readable', 'adult'], array_keys($requests[0]['questions']));
        $this->assertSame(['sub'], array_keys($requests[1]['questions']));
        $this->assertStringStartsWith('Which Adult subcategory fits this release?', $requests[1]['questions']['sub']['instructions']);
        $this->assertSame($requests[0]['state'], $requests[1]['state']);
    }

    public function test_a_failing_service_returns_the_pipeline_category_and_logs_the_failure(): void
    {
        $this->mode('apply');
        Http::fake(['*' => Http::response('down', 500)]);

        $result = $this->categorize(self::UNMATCHED_NAME, debug: true);

        $this->assertSame(Category::OTHER_MISC, $result['categories_id']);
        $this->assertSame('no_match', $result['debug']['matched_by']);
        $this->assertSame(
            [false, 'http_500', []],
            array_values(array_intersect_key($result['debug']['all_results']['DecisionModel'], array_flip(['applied', 'reason', 'answers']))),
        );
        Http::assertSentCount(3);

        $row = DecisionModelDecision::query()->sole();
        $this->assertSame(['http_500', false, 'apply', null, null], [
            $row->failure_reason, $row->applied, $row->mode, $row->answers, $row->interpretation,
        ]);
        $this->assertSame(self::UNMATCHED_NAME, $row->state['release_name'] ?? null);
        $this->assertSame(Category::OTHER_MISC, $row->pipeline_answer['category_id'] ?? null);
    }

    public function test_an_open_breaker_logs_its_reason_without_another_request(): void
    {
        $this->mode('apply');
        Http::fake(['*' => Http::response('down', 503)]);

        $this->categorize(self::UNMATCHED_NAME);
        $second = $this->categorize('Another.Invented.Evening.With.Nobody.In.Particular');

        $this->assertSame(Category::OTHER_MISC, $second['categories_id']);
        Http::assertSentCount(3);
        $this->assertSame(
            ['http_503', 'circuit_open'],
            DecisionModelDecision::query()->orderBy('id')->pluck('failure_reason')->all(),
        );
    }

    public function test_a_failed_subcategory_request_returns_the_pipeline_category(): void
    {
        $this->mode('apply');
        config(['decision-model.retry_attempts' => 1]);
        Http::fake(['*' => Http::sequence()
            ->push(['answers' => $this->rootAnswers('tv', 0.95)])
            ->push('down', 502),
        ]);

        $this->assertSame(Category::OTHER_MISC, $this->categorize(self::UNMATCHED_NAME)['categories_id']);

        $row = DecisionModelDecision::query()->sole();
        $this->assertSame(['http_502', false], [$row->failure_reason, $row->applied]);
        $this->assertSame('tv', $row->answers[0]['root']['choice'] ?? null);
    }

    public function test_a_subcategory_answer_that_failed_files_the_release_in_the_root_other_category(): void
    {
        $this->mode('apply');
        Http::fake(['*' => Http::sequence()
            ->push(['answers' => $this->rootAnswers('tv', 0.95)])
            ->push(['answers' => ['sub' => ['error' => 'too_long', 'message' => 'The question exceeds the token cap.']]]),
        ]);

        $this->assertSame(Category::TV_OTHER, $this->categorize(self::UNMATCHED_NAME)['categories_id']);

        $row = DecisionModelDecision::query()->sole();
        $this->assertSame([true, null], [$row->applied, $row->failure_reason]);
        $this->assertSame('too_long', $row->answers[1]['sub']['error'] ?? null);
    }

    public function test_a_malformed_root_answer_returns_the_pipeline_category_and_logs_the_reason(): void
    {
        $this->mode('apply');
        Http::fake(['*' => Http::response(['answers' => [
            'root' => ['error' => 'too_long', 'message' => 'The question exceeds the token cap.'],
            'readable' => ['noul' => 0.9],
            'adult' => ['noul' => 0.1],
        ]])]);

        $this->assertSame(Category::OTHER_MISC, $this->categorize(self::UNMATCHED_NAME)['categories_id']);

        Http::assertSentCount(1);
        $row = DecisionModelDecision::query()->sole();
        $this->assertSame(['root_answer_unusable', false], [$row->failure_reason, $row->applied]);
        $this->assertSame('too_long', $row->answers[0]['root']['error'] ?? null);
    }

    public function test_a_forced_root_result_is_never_sent_to_the_model(): void
    {
        $this->mode('apply');
        Http::fake();

        $result = $this->categorize(self::UNMATCHED_NAME, groupId: self::TV_FORCED_GROUP_ID, debug: true);

        $this->assertSame(Category::TV_OTHER, $result['categories_id']);
        $this->assertSame('group_forced_root', $result['debug']['matched_by']);
        Http::assertNothingSent();
    }

    public function test_a_weak_result_inside_a_forced_root_never_leaves_that_root(): void
    {
        $this->mode('apply');
        $this->fakeAnswers(['movies', 0.99], ['hd', 0.99]);

        $result = $this->categorize(self::UNMATCHED_NAME, groupId: self::TV_NAMED_FORCED_GROUP_ID, debug: true);

        $this->assertSame(Category::TV_OTHER, $result['categories_id']);
        $this->assertSame('group_name_tv', $result['debug']['matched_by']);

        $row = DecisionModelDecision::query()->sole();
        $this->assertFalse($row->applied);
        $this->assertSame('forced_root', $row->interpretation['reason'] ?? null);
    }

    public function test_a_weak_result_inside_a_forced_root_takes_a_leaf_of_that_root(): void
    {
        $this->mode('apply');
        $this->fakeAnswers(['tv', 0.9], ['documentary', 0.85]);

        $result = $this->categorize(self::UNMATCHED_NAME, groupId: self::TV_NAMED_FORCED_GROUP_ID);

        $this->assertSame(Category::TV_DOCU, $result['categories_id']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function obfuscatedNamesAsPosted(): array
    {
        return [
            'a short stem with a container' => ['asf5.mkv'],
            'a rar part with a part counter' => ['25FYEKgpdJyLVBrQ.part03.rar - [04/85]'],
        ];
    }

    #[DataProvider('obfuscatedNamesAsPosted')]
    public function test_a_name_posted_as_an_obfuscated_file_is_never_sent_to_the_model(string $name): void
    {
        Http::fake();
        $pipeline = $this->categorize($name, debug: true);
        $this->assertLessThan(0.70, $pipeline['debug']['final_confidence'], 'The pipeline is unsure, so only the name decides.');
        $this->assertFalse($pipeline['debug']['locked_to_misc']);

        // The arbiter keeps the mode it read; a fresh one reads the new mode.
        $this->app->forgetInstance(CategoryArbiter::class);
        $this->mode('apply');
        $this->fakeAnswers(['tv', 0.95], ['hd', 0.95]);

        $this->assertSame($pipeline['categories_id'], $this->categorize($name)['categories_id']);
        Http::assertNothingSent();
        $this->assertSame(0, DecisionModelDecision::query()->count());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function titlesAsPosted(): array
    {
        return [
            'words' => ['An Invented Evening'],
            'a dotted acronym before a word' => ['F.B.I. Stories'],
        ];
    }

    #[DataProvider('titlesAsPosted')]
    public function test_a_name_posted_as_a_title_is_sent_to_the_model(string $name): void
    {
        $this->mode('shadow');
        $this->fakeAnswers(['other', 0.9]);

        $this->categorize($name);

        Http::assertSentCount(1);
        $this->assertSame(1, DecisionModelDecision::query()->count());
    }

    public function test_a_dotted_acronym_alone_is_not_readable_once_cleaned(): void
    {
        $this->mode('apply');
        $this->fakeAnswers(['tv', 0.95], ['hd', 0.95]);

        $this->categorize('F.B.I. (2025)');

        Http::assertNothingSent();
        $this->assertSame(0, DecisionModelDecision::query()->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function categorize(
        string $name,
        int $groupId = self::PLAIN_GROUP_ID,
        bool $debug = false,
        ?int $releaseId = null,
    ): array {
        return (new CategorizationService)->determineCategory($groupId, $name, 'poster@example.test', $debug, [], $releaseId);
    }

    private function mode(string $mode): void
    {
        DB::table('settings')->where('name', 'decision_model_mode')->update(['value' => $mode]);
    }

    /**
     * Fake the root request and, when a leaf is given, the subcategory request after it.
     *
     * @param  array{string, float}  $root
     * @param  array{string, float}|null  $leaf
     */
    private function fakeAnswers(array $root, ?array $leaf = null): void
    {
        $sequence = Http::sequence()->push(['answers' => $this->rootAnswers($root[0], $root[1])]);

        if ($leaf !== null) {
            $sequence->push(['answers' => [
                'sub' => ['choice' => $leaf[0], 'probabilities' => [$leaf[0] => $leaf[1]], 'confidence' => $leaf[1]],
            ]]);
        }

        Http::fake([self::ENDPOINT => $sequence]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function rootAnswers(string $root, float $confidence): array
    {
        return [
            'root' => ['choice' => $root, 'probabilities' => [$root => $confidence], 'confidence' => $confidence],
            'readable' => ['noul' => 0.97],
            'adult' => ['noul' => 0.02],
        ];
    }
}
