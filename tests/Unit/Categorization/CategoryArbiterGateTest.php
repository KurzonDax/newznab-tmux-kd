<?php

declare(strict_types=1);

namespace Tests\Unit\Categorization;

use App\Models\Category;
use App\Models\DecisionModelDecision;
use App\Services\Categorization\Arbitration\CategoryArbiter;
use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\Pipes\CategorizationPassable;
use App\Services\Categorization\ReleaseContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/**
 * Each condition that decides whether the model is asked at all, one at a
 * time: every test starts from a release that is asked about and changes one
 * thing.
 */
final class CategoryArbiterGateTest extends TestCase
{
    use IsolatedSqliteDatabase;

    private const string READABLE_NAME = 'An Invented Evening With Nobody In Particular';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();

        ProductionTables::fromAuthority()->create('decision_model_criteria', null);
        ProductionTables::fromAuthority()->create('decision_model_decisions', null);

        DB::table('settings')->insert([
            ['name' => 'decision_model_mode', 'value' => 'shadow'],
            ['name' => 'decision_model_apply_threshold', 'value' => '0.80'],
            ['name' => 'decision_model_arbitrate_below', 'value' => '0.70'],
        ]);

        config([
            'decision-model.enabled' => true,
            'decision-model.base_url' => 'https://decision.example.test',
            'decision-model.api_key' => 'invented-token',
            'decision-model.retry_attempts' => 1,
        ]);

        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['answers' => [
            'root' => ['choice' => 'other', 'probabilities' => ['other' => 0.9], 'confidence' => 0.9],
            'readable' => ['noul' => 0.9],
            'adult' => ['noul' => 0.1],
        ]])]);
        Sleep::fake();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_an_unmatched_release_with_a_readable_name_is_asked_about(): void
    {
        $this->arbitrate($this->passable());

        Http::assertSentCount(1);
        $this->assertSame(1, DecisionModelDecision::query()->count());
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function modesThatAskNothing(): array
    {
        return [
            'off' => ['off'],
            'blank' => [''],
            'no setting row' => [null],
            'a mode that does not exist' => ['always'],
        ];
    }

    #[DataProvider('modesThatAskNothing')]
    public function test_nothing_is_asked_unless_the_mode_is_shadow_or_apply(?string $mode): void
    {
        $this->setting('decision_model_mode', $mode);

        $this->arbitrate($this->passable());

        $this->assertNothingAsked();
    }

    public function test_the_apply_mode_asks(): void
    {
        $this->setting('decision_model_mode', 'apply');

        $this->arbitrate($this->passable());

        Http::assertSentCount(1);
    }

    public function test_nothing_is_asked_while_the_client_is_disabled(): void
    {
        config(['decision-model.enabled' => false]);

        $this->arbitrate($this->passable());

        $this->assertNothingAsked();
    }

    public function test_nothing_is_asked_without_a_base_url(): void
    {
        config(['decision-model.base_url' => '']);

        $this->arbitrate($this->passable());

        $this->assertNothingAsked();
    }

    public function test_a_release_locked_to_misc_is_not_asked_about(): void
    {
        $passable = $this->passable();
        $passable->lockToMisc();

        $this->arbitrate($passable);

        $this->assertNothingAsked();
    }

    public function test_a_hashed_result_is_not_asked_about(): void
    {
        $this->arbitrate($this->passable(result: new CategorizationResult(Category::OTHER_HASHED, 0.5, 'invented_hash_rule')));

        $this->assertNothingAsked();
    }

    public function test_a_result_at_the_arbitration_limit_is_not_asked_about(): void
    {
        $this->arbitrate($this->passable(result: new CategorizationResult(Category::MOVIE_OTHER, 0.70, 'invented_rule')));

        $this->assertNothingAsked();
    }

    public function test_a_result_just_under_the_arbitration_limit_is_asked_about(): void
    {
        $this->arbitrate($this->passable(result: new CategorizationResult(Category::MOVIE_OTHER, 0.6999, 'invented_rule')));

        Http::assertSentCount(1);
    }

    public function test_the_arbitration_limit_is_the_setting(): void
    {
        $this->setting('decision_model_arbitrate_below', '0.55');

        $this->arbitrate($this->passable(result: new CategorizationResult(Category::TV_OTHER, 0.6, 'invented_rule')));

        $this->assertNothingAsked();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function namesThatAreNotReadable(): array
    {
        return [
            'no words' => ['2019 1080 720 5 1'],
            'a hash' => ['d41d8cd98f00b204e9800998ecf8427e'],
            'empty' => [''],
        ];
    }

    #[DataProvider('namesThatAreNotReadable')]
    public function test_a_release_whose_cleaned_name_is_not_readable_is_not_asked_about(string $name): void
    {
        $this->arbitrate($this->passable(name: $name));

        $this->assertNothingAsked();
    }

    public function test_readability_is_judged_on_the_cleaned_name_not_the_name_as_posted(): void
    {
        $this->arbitrate($this->passable(name: 'd41d8cd98f00b204e9800998ecf8427e', preExtractionName: self::READABLE_NAME));

        $this->assertNothingAsked();
    }

    public function test_a_log_that_cannot_be_written_neither_stops_categorization_nor_applies_the_answer(): void
    {
        $this->setting('decision_model_mode', 'apply');
        Http::fake(['*' => Http::sequence()
            ->push(['answers' => ['root' => ['choice' => 'tv', 'probabilities' => ['tv' => 0.95], 'confidence' => 0.95]]])
            ->push(['answers' => ['sub' => ['choice' => 'hd', 'probabilities' => ['hd' => 0.95], 'confidence' => 0.95]]]),
        ]);
        Schema::drop('decision_model_decisions');
        Log::shouldReceive('warning')->once()->withArgs(
            static fn (string $message): bool => $message === 'categorization.decision_model_failed',
        );
        $passable = $this->passable();

        $this->arbitrate($passable);

        $this->assertSame(
            Category::OTHER_MISC,
            $passable->bestResult->categoryId,
            'A decision that could not be logged is not applied.',
        );
    }

    private function arbitrate(CategorizationPassable $passable): void
    {
        app(CategoryArbiter::class)->arbitrate($passable);
    }

    private function passable(
        string $name = self::READABLE_NAME,
        ?CategorizationResult $result = null,
        ?string $preExtractionName = null,
    ): CategorizationPassable {
        $passable = new CategorizationPassable(new ReleaseContext(
            releaseName: $name,
            groupId: 0,
            preExtractionName: $preExtractionName,
        ));

        if ($result !== null) {
            $passable->bestResult = $result;
        }

        return $passable;
    }

    private function setting(string $name, ?string $value): void
    {
        DB::table('settings')->where('name', $name)->delete();

        if ($value !== null) {
            DB::table('settings')->insert(['name' => $name, 'value' => $value]);
        }
    }

    private function assertNothingAsked(): void
    {
        Http::assertNothingSent();
        $this->assertSame(0, DecisionModelDecision::query()->count());
    }
}
