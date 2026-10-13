<?php

declare(strict_types=1);

namespace App\Services\DecisionModel;

use App\Services\DecisionModel\Exceptions\DecisionModelUnavailable;
use App\Services\DecisionModel\Questions\DecisionQuestion;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Client for the self-hosted decision model's System One endpoint: a state
 * plus typed questions in, one answer per question out.
 *
 * It knows nothing about what is being decided. A use case builds the state
 * and the questions and reads the answers; see {@see DecisionUseCase}.
 *
 * The breaker counts consecutive failed tries (429, 5xx, connection errors) and
 * lives on the instance, so the container's shared instance stops a whole
 * process calling a service that is down; it stays open until the process
 * ends. The service runs one request at a time and queues the rest, so nothing
 * here sends requests side by side.
 */
final class DecisionModelClient
{
    private const string ENDPOINT = 'v1/systemone';

    /** First retry delay; each later retry waits twice as long. */
    private const int BACKOFF_MILLISECONDS = 250;

    private int $consecutiveFailures = 0;

    private bool $circuitOpen = false;

    /**
     * Whether a request could be sent at all: the client is enabled and has an
     * address to send to.
     */
    public function isUsable(): bool
    {
        return (bool) config('decision-model.enabled', false) && $this->baseUrl() !== '';
    }

    /**
     * Ask the model the given questions about one state.
     *
     * An answer carrying an `error`, and a question the service left out of
     * its response, come back as failed answers: only that question failed.
     *
     * @param  string|array<string, mixed>  $state
     * @param  array<string, DecisionQuestion>  $questions  Question id => question.
     *
     * @throws DecisionModelUnavailable when the client is unusable, the breaker is open, the request failed after its retries, or the body is not an answers map
     */
    public function ask(string|array $state, array $questions): DecisionResponse
    {
        if (! $this->isUsable()) {
            throw new DecisionModelUnavailable('disabled');
        }

        if ($this->circuitOpen) {
            throw new DecisionModelUnavailable('circuit_open');
        }

        $payload = [
            'state' => $state,
            'questions' => array_map(static fn (DecisionQuestion $question): array => $question->toArray(), $questions),
        ];

        $model = (string) config('decision-model.model', '');
        if ($model !== '') {
            $payload['model'] = $model;
        }

        $body = $this->send($payload);

        if (! is_array($body) || ! is_array($body['answers'] ?? null)) {
            throw new DecisionModelUnavailable('malformed_response');
        }

        $answers = [];
        foreach (array_keys($questions) as $id) {
            $answer = $body['answers'][$id] ?? null;
            $answers[$id] = is_array($answer) ? DecisionAnswer::fromArray($answer) : DecisionAnswer::missing();
        }

        return new DecisionResponse($answers);
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws DecisionModelUnavailable
     */
    private function send(array $payload): mixed
    {
        $attempts = max(1, (int) config('decision-model.retry_attempts', 3));

        for ($attempt = 1; ; $attempt++) {
            try {
                $response = $this->http()->post(self::ENDPOINT, $payload);
            } catch (ConnectionException) {
                $response = null;
            }

            if ($response?->successful()) {
                $this->consecutiveFailures = 0;

                return $response->json();
            }

            $reason = $response === null ? 'connection_failed' : 'http_'.$response->status();

            // A request the service rejected outright (a bad key, a bad body) says
            // nothing about whether it is up: it is neither retried nor counted.
            if ($response !== null && $response->status() !== 429 && ! $response->serverError()) {
                throw new DecisionModelUnavailable($reason);
            }

            $this->recordFailure();

            if ($this->circuitOpen || $attempt >= $attempts) {
                throw new DecisionModelUnavailable($reason);
            }

            Sleep::usleep(self::BACKOFF_MILLISECONDS * (2 ** ($attempt - 1)) * 1000);
        }
    }

    private function http(): PendingRequest
    {
        $request = Http::baseUrl($this->baseUrl())
            ->acceptJson()
            ->asJson()
            ->timeout(max(1, (int) config('decision-model.timeout_seconds', 30)));

        $key = (string) config('decision-model.api_key', '');

        return $key === '' ? $request : $request->withToken($key);
    }

    private function baseUrl(): string
    {
        return rtrim(trim((string) config('decision-model.base_url', '')), '/');
    }

    private function recordFailure(): void
    {
        $this->consecutiveFailures++;
        $threshold = max(1, (int) config('decision-model.circuit_breaker_failures', 3));
        $this->circuitOpen = $this->consecutiveFailures >= $threshold;
    }
}
