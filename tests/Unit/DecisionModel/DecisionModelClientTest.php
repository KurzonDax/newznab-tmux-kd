<?php

declare(strict_types=1);

namespace Tests\Unit\DecisionModel;

use App\Services\DecisionModel\DecisionModelClient;
use App\Services\DecisionModel\Exceptions\DecisionModelUnavailable;
use App\Services\DecisionModel\Questions\ChoiceQuestion;
use App\Services\DecisionModel\Questions\NoulQuestion;
use App\Services\DecisionModel\Questions\ScoreQuestion;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

final class DecisionModelClientTest extends TestCase
{
    private const string BASE_URL = 'https://decision.example.test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'decision-model.enabled' => true,
            'decision-model.base_url' => self::BASE_URL,
            'decision-model.api_key' => 'invented-token',
            'decision-model.model' => '',
            'decision-model.timeout_seconds' => 30,
            'decision-model.retry_attempts' => 3,
            'decision-model.circuit_breaker_failures' => 3,
        ]);

        Http::preventStrayRequests();
        Sleep::fake();
    }

    public function test_it_posts_the_state_and_typed_questions_and_returns_typed_answers(): void
    {
        Http::fake([
            self::BASE_URL.'/v1/systemone' => Http::response([
                'answers' => [
                    'kind' => [
                        'choice' => 'tv',
                        'probabilities' => ['tv' => 0.91, 'movies' => 0.09],
                        'confidence' => 0.91,
                    ],
                    'readable' => ['noul' => 0.97],
                    'quality' => ['score' => 2, 'legend' => ['poor', 'fair', 'good']],
                ],
            ]),
        ]);

        $response = (new DecisionModelClient)->ask(
            ['release_name' => 'Invented.Show.S01E02.720p.HDTV.x264-GRP'],
            [
                'kind' => new ChoiceQuestion('Which kind of content is this?', [
                    'tv' => 'Episodic television',
                    'movies' => 'A feature film',
                ]),
                'readable' => new NoulQuestion('Is the name readable?'),
                'quality' => new ScoreQuestion('How good is the name?', ['poor', 'fair', 'good']),
            ],
        );

        $this->assertSame('tv', $response->answer('kind')?->choice);
        $this->assertSame(['tv' => 0.91, 'movies' => 0.09], $response->answer('kind')?->probabilities);
        $this->assertSame(0.91, $response->answer('kind')?->confidence);
        $this->assertSame(0.97, $response->answer('readable')?->noul);
        $this->assertSame(2, $response->answer('quality')?->score);
        $this->assertSame(['poor', 'fair', 'good'], $response->answer('quality')?->legend);
        $this->assertFalse($response->answer('kind')?->failed());

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && $request->url() === self::BASE_URL.'/v1/systemone'
                && $request->hasHeader('Authorization', 'Bearer invented-token')
                && $request->data() === [
                    'state' => ['release_name' => 'Invented.Show.S01E02.720p.HDTV.x264-GRP'],
                    'questions' => [
                        'kind' => [
                            'type' => 'choice',
                            'instructions' => 'Which kind of content is this?',
                            'criteria' => ['tv' => 'Episodic television', 'movies' => 'A feature film'],
                        ],
                        'readable' => ['type' => 'noul', 'instructions' => 'Is the name readable?'],
                        'quality' => [
                            'type' => 'score',
                            'instructions' => 'How good is the name?',
                            'criteria' => ['poor', 'fair', 'good'],
                        ],
                    ],
                ];
        });
    }

    public function test_it_sends_the_model_field_only_when_one_is_configured(): void
    {
        config(['decision-model.model' => 'invented-model']);
        Http::fake(['*' => Http::response(['answers' => ['readable' => ['noul' => 0.5]]])]);

        (new DecisionModelClient)->ask('A plain text state', ['readable' => new NoulQuestion('Is it readable?')]);

        Http::assertSent(static fn (Request $request): bool => $request['model'] === 'invented-model'
            && $request['state'] === 'A plain text state');
    }

    public function test_a_noul_question_sends_its_true_and_false_criteria_when_given(): void
    {
        Http::fake(['*' => Http::response(['answers' => ['adult' => ['noul' => 0.1]]])]);

        (new DecisionModelClient)->ask('state', [
            'adult' => new NoulQuestion('Is it adult?', ['true' => 'Names a studio', 'false' => 'Names a film']),
        ]);

        Http::assertSent(static fn (Request $request): bool => $request['questions']['adult'] === [
            'type' => 'noul',
            'instructions' => 'Is it adult?',
            'criteria' => ['true' => 'Names a studio', 'false' => 'Names a film'],
        ]);
    }

    public function test_an_answer_carrying_an_error_fails_that_question_only(): void
    {
        Http::fake(['*' => Http::response([
            'answers' => [
                'kind' => ['error' => 'too_long', 'message' => 'The question exceeds the token cap.'],
                'readable' => ['noul' => 0.8],
            ],
        ])]);

        $response = (new DecisionModelClient)->ask('state', [
            'kind' => new ChoiceQuestion('Which kind?', ['tv' => 'Television']),
            'readable' => new NoulQuestion('Is it readable?'),
            'adult' => new NoulQuestion('Is it adult?'),
        ]);

        $this->assertTrue($response->answer('kind')?->failed());
        $this->assertSame('too_long', $response->answer('kind')?->error);
        $this->assertNull($response->answer('kind')?->choice);
        $this->assertFalse($response->answer('readable')?->failed());
        $this->assertSame(0.8, $response->answer('readable')?->noul);
        $this->assertTrue($response->answer('adult')?->failed(), 'A question the service left unanswered has failed.');
    }

    public function test_it_retries_a_429_and_returns_the_later_answer(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['error' => 'busy'], 429)
            ->push(['answers' => ['readable' => ['noul' => 0.9]]]),
        ]);

        $response = (new DecisionModelClient)->ask('state', ['readable' => new NoulQuestion('Is it readable?')]);

        $this->assertSame(0.9, $response->answer('readable')?->noul);
        Http::assertSentCount(2);
    }

    public function test_it_retries_a_connection_error(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                throw new ConnectionException('Connection refused');
            }

            return Http::response(['answers' => ['readable' => ['noul' => 0.9]]]);
        });

        $response = (new DecisionModelClient)->ask('state', ['readable' => new NoulQuestion('Is it readable?')]);

        $this->assertSame(0.9, $response->answer('readable')?->noul);
        $this->assertSame(2, $calls);
    }

    public function test_the_breaker_opens_after_consecutive_failures_and_stops_sending(): void
    {
        Http::fake(['*' => Http::response('down', 503)]);
        $client = new DecisionModelClient;

        try {
            $client->ask('state', ['readable' => new NoulQuestion('Is it readable?')]);
            $this->fail('A failed request must not return a response.');
        } catch (DecisionModelUnavailable $failure) {
            $this->assertSame('http_503', $failure->reason);
        }

        Http::assertSentCount(3);

        try {
            $client->ask('state', ['readable' => new NoulQuestion('Is it readable?')]);
            $this->fail('An open breaker must not return a response.');
        } catch (DecisionModelUnavailable $failure) {
            $this->assertSame('circuit_open', $failure->reason);
        }

        Http::assertSentCount(3);
    }

    public function test_a_success_resets_the_failure_count(): void
    {
        config(['decision-model.retry_attempts' => 1]);
        Http::fake(['*' => Http::sequence()
            ->push('down', 500)
            ->push('down', 500)
            ->push(['answers' => ['readable' => ['noul' => 0.9]]])
            ->push('down', 500)
            ->push('down', 500)
            ->push(['answers' => ['readable' => ['noul' => 0.7]]]),
        ]);
        $client = new DecisionModelClient;
        $ask = static function () use ($client): ?float {
            try {
                return $client->ask('state', ['readable' => new NoulQuestion('Is it readable?')])->answer('readable')?->noul;
            } catch (DecisionModelUnavailable) {
                return null;
            }
        };

        $this->assertSame([null, null, 0.9, null, null, 0.7], [$ask(), $ask(), $ask(), $ask(), $ask(), $ask()]);
    }

    public function test_a_client_error_is_not_retried(): void
    {
        Http::fake(['*' => Http::response(['error' => 'unauthorized'], 401)]);

        try {
            (new DecisionModelClient)->ask('state', ['readable' => new NoulQuestion('Is it readable?')]);
            $this->fail('A rejected request must not return a response.');
        } catch (DecisionModelUnavailable $failure) {
            $this->assertSame('http_401', $failure->reason);
        }

        Http::assertSentCount(1);
    }

    public function test_rejected_requests_and_unreadable_bodies_do_not_open_the_breaker(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['error' => 'unauthorized'], 401)
            ->push(['error' => 'unauthorized'], 401)
            ->push(['error' => 'bad request'], 400)
            ->push('not json at all')
            ->push(['answers' => ['readable' => ['noul' => 0.9]]]),
        ]);
        $client = new DecisionModelClient;
        $reasons = [];

        for ($request = 1; $request <= 4; $request++) {
            try {
                $client->ask('state', ['readable' => new NoulQuestion('Is it readable?')]);
            } catch (DecisionModelUnavailable $failure) {
                $reasons[] = $failure->reason;
            }
        }

        $this->assertSame(['http_401', 'http_401', 'http_400', 'malformed_response'], $reasons);
        $this->assertSame(0.9, $client->ask('state', ['readable' => new NoulQuestion('Is it readable?')])->answer('readable')?->noul);
        Http::assertSentCount(5);
    }

    public function test_a_body_without_an_answers_map_is_a_failure(): void
    {
        Http::fake(['*' => Http::response('not json at all')]);

        try {
            (new DecisionModelClient)->ask('state', ['readable' => new NoulQuestion('Is it readable?')]);
            $this->fail('A malformed body must not return a response.');
        } catch (DecisionModelUnavailable $failure) {
            $this->assertSame('malformed_response', $failure->reason);
        }
    }

    public function test_it_is_unusable_and_sends_nothing_while_disabled_or_without_a_base_url(): void
    {
        Http::fake();

        config(['decision-model.enabled' => false]);
        $this->assertFalse((new DecisionModelClient)->isUsable());
        $this->assertDisabled(new DecisionModelClient);

        config(['decision-model.enabled' => true, 'decision-model.base_url' => '']);
        $this->assertFalse((new DecisionModelClient)->isUsable());
        $this->assertDisabled(new DecisionModelClient);

        Http::assertNothingSent();

        config(['decision-model.base_url' => self::BASE_URL]);
        $this->assertTrue((new DecisionModelClient)->isUsable());
    }

    private function assertDisabled(DecisionModelClient $client): void
    {
        try {
            $client->ask('state', ['readable' => new NoulQuestion('Is it readable?')]);
            $this->fail('A disabled client must not return a response.');
        } catch (DecisionModelUnavailable $failure) {
            $this->assertSame('disabled', $failure->reason);
        }
    }
}
