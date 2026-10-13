<?php

declare(strict_types=1);

namespace Tests\Unit\DecisionModel;

use App\Models\DecisionModelCriteria;
use App\Models\DecisionModelDecision;
use App\Services\DecisionModel\DecisionLog;
use App\Services\DecisionModel\DecisionMode;
use App\Services\DecisionModel\DecisionModelClient;
use App\Services\DecisionModel\DecisionRunner;
use App\Services\DecisionModel\DecisionUseCase;
use App\Services\DecisionModel\DecisionUseCaseRegistry;
use App\Services\DecisionModel\DecisionVerdict;
use App\Services\DecisionModel\Exceptions\MalformedDecisionAnswer;
use App\Services\DecisionModel\Questions\NoulQuestion;
use App\Services\DecisionModel\Questions\ScoreQuestion;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use stdClass;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/**
 * A second use case, written here against the contract alone, runs through the
 * shared client, runner and log without any of them changing.
 */
final class DecisionRunnerTest extends TestCase
{
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();

        ProductionTables::fromAuthority()->create('decision_model_criteria', null);
        ProductionTables::fromAuthority()->create('decision_model_decisions', null);

        config([
            'decision-model.enabled' => true,
            'decision-model.base_url' => 'https://decision.example.test',
            'decision-model.api_key' => 'invented-token',
            'decision-model.retry_attempts' => 1,
        ]);

        Http::preventStrayRequests();
        Sleep::fake();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_a_use_case_is_asked_request_by_request_and_its_verdict_is_returned(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['answers' => ['legible' => ['noul' => 0.9]]])
            ->push(['answers' => ['quality' => ['score' => 2, 'legend' => ['poor', 'fair', 'good']]]]),
        ]);

        $run = $this->runner()->run('name_quality', $this->subject('Invented Name 2026'));

        $this->assertFalse($run->failed());
        $this->assertSame('name_quality', $run->useCase);
        $this->assertSame('quality v1', $run->criteriaVersion);
        $this->assertSame('Invented Name 2026', $run->state);
        $this->assertSame(['legible' => 0.9, 'quality' => 2], $run->verdict?->toArray());
        $this->assertSame(
            [
                ['legible' => ['noul' => 0.9]],
                ['quality' => ['score' => 2, 'legend' => ['poor', 'fair', 'good']]],
            ],
            $run->answers(),
        );

        Http::assertSentCount(2);
        Http::assertSent(static fn (Request $request): bool => $request['state'] === 'Invented Name 2026');
    }

    public function test_a_failed_request_ends_the_run_with_its_reason_and_the_answers_so_far(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['answers' => ['legible' => ['noul' => 0.9]]])
            ->push('down', 502),
        ]);

        $run = $this->runner()->run('name_quality', $this->subject('Invented Name 2026'));

        $this->assertTrue($run->failed());
        $this->assertSame('http_502', $run->failureReason);
        $this->assertSame([['legible' => ['noul' => 0.9]]], $run->answers());
    }

    public function test_an_answer_the_use_case_cannot_read_ends_the_run_with_its_reason(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['answers' => ['legible' => ['noul' => 0.9]]])
            ->push(['answers' => ['quality' => ['error' => 'refused']]]),
        ]);

        $run = $this->runner()->run('name_quality', $this->subject('Invented Name 2026'));

        $this->assertTrue($run->failed());
        $this->assertSame('quality_unanswered', $run->failureReason);
    }

    public function test_an_unregistered_use_case_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->runner()->run('never_registered', new stdClass);
    }

    public function test_the_log_holds_one_row_per_decision_and_one_criteria_row_per_version(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['answers' => ['legible' => ['noul' => 0.9]]])
            ->push(['answers' => ['quality' => ['score' => 2]]])
            ->push('down', 500),
        ]);
        $runner = $this->runner();
        $log = new DecisionLog;

        $log->record($runner->run('name_quality', $this->subject('Invented Name 2026')), 'release', 41, ['quality' => 1], DecisionMode::Apply, true);
        $log->record($runner->run('name_quality', $this->subject('Another Invented Name')), 'release', null, null, DecisionMode::Shadow, false);

        $this->assertSame(1, DecisionModelCriteria::query()->count());
        $criteria = DecisionModelCriteria::query()->sole();
        $this->assertSame(['name_quality', 'quality v1'], [$criteria->use_case, $criteria->version]);

        [$decided, $failed] = DecisionModelDecision::query()->orderBy('id')->get()->all();

        $this->assertSame($criteria->id, $decided->decision_model_criteria_id);
        $this->assertSame(['release', 41, 'apply', true], [$decided->subject_type, $decided->subject_id, $decided->mode, $decided->applied]);
        $this->assertSame('Invented Name 2026', $decided->state);
        $this->assertSame([['legible' => ['noul' => 0.9]], ['quality' => ['score' => 2]]], $decided->answers);
        $this->assertSame(['quality' => 1], $decided->pipeline_answer);
        $this->assertSame(['legible' => 0.9, 'quality' => 2], $decided->interpretation);
        $this->assertNull($decided->failure_reason);
        $this->assertNotNull($decided->created_at);
        $this->assertTrue($decided->criteria?->is($criteria));

        $this->assertSame(['release', null, 'shadow', false], [$failed->subject_type, $failed->subject_id, $failed->mode, $failed->applied]);
        $this->assertSame('http_500', $failed->failure_reason);
        $this->assertNull($failed->answers);
        $this->assertNull($failed->pipeline_answer);
        $this->assertNull($failed->interpretation);
    }

    public function test_a_decision_is_logged_after_a_callers_transaction_took_the_criteria_row_back(): void
    {
        Http::fake(['*' => Http::response('down', 500)]);
        $runner = $this->runner();
        $log = new DecisionLog;

        DB::beginTransaction();
        $log->record($runner->run('name_quality', $this->subject('Invented Name 2026')), 'release', 1, null, DecisionMode::Shadow, false);
        DB::rollBack();

        $this->assertSame(0, DecisionModelCriteria::query()->count());

        $row = $log->record($runner->run('name_quality', $this->subject('Invented Name 2026')), 'release', 1, null, DecisionMode::Shadow, false);

        $this->assertSame(1, DecisionModelDecision::query()->count());
        $this->assertSame('quality v1', $row->criteria?->version);
    }

    private function runner(): DecisionRunner
    {
        return new DecisionRunner(new DecisionModelClient, new DecisionUseCaseRegistry([$this->nameQualityUseCase()]));
    }

    private function subject(string $name): stdClass
    {
        $subject = new stdClass;
        $subject->name = $name;

        return $subject;
    }

    private function nameQualityUseCase(): DecisionUseCase
    {
        return new class implements DecisionUseCase
        {
            public function name(): string
            {
                return 'name_quality';
            }

            public function criteriaVersion(): string
            {
                return 'quality v1';
            }

            public function state(object $subject): string
            {
                return (string) ($subject->name ?? '');
            }

            public function questions(object $subject, array $responses): array
            {
                return match (count($responses)) {
                    0 => ['legible' => new NoulQuestion('Can a person read this name?')],
                    1 => ['quality' => new ScoreQuestion('How complete is this name?', ['poor', 'fair', 'good'])],
                    default => [],
                };
            }

            public function interpret(object $subject, array $responses): DecisionVerdict
            {
                $legible = ($responses[0] ?? null)?->usableAnswer('legible')?->noul;
                $quality = ($responses[1] ?? null)?->usableAnswer('quality')?->score
                    ?? throw new MalformedDecisionAnswer('quality_unanswered');

                return new class($legible, $quality) implements DecisionVerdict
                {
                    public function __construct(private readonly ?float $legible, private readonly int|float $quality) {}

                    public function toArray(): array
                    {
                        return ['legible' => $this->legible, 'quality' => $this->quality];
                    }
                };
            }
        };
    }
}
