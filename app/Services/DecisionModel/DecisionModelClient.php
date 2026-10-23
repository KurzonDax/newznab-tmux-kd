<?php

declare(strict_types=1);

namespace App\Services\DecisionModel;

use App\Services\DecisionModel\Exceptions\DecisionModelUnavailable;
use App\Services\DecisionModel\Questions\DecisionQuestion;
use App\Services\ProviderGateway\ProviderGateway;
use App\Services\ProviderGateway\ShortCircuit;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;

/**
 * Client for the self-hosted decision model's System One endpoint: a state
 * plus typed questions in, one answer per question out.
 *
 * It knows nothing about what is being decided. A use case builds the state
 * and the questions and reads the answers; see {@see DecisionUseCase}.
 *
 * Requests go through the provider gateway, which retries 429, 5xx and connection
 * errors with doubling backoff. Its breaker for this client counts consecutive
 * failed tries and belongs to the instance, so the container's shared instance
 * stops a whole process calling a service that is down; it stays open until the
 * process ends. The service runs one request at a time and queues the rest, so
 * nothing here sends requests side by side.
 */
final class DecisionModelClient
{
    private const string ENDPOINT = 'v1/systemone';

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

        if (app(ProviderGateway::class)->isOpen('decision_model', $this)) {
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
        try {
            $response = $this->http()->post(self::ENDPOINT, $payload);
        } catch (ConnectionException $exception) {
            throw new DecisionModelUnavailable($exception instanceof ShortCircuit ? 'circuit_open' : 'connection_failed');
        }

        if (! $response->successful()) {
            // A rejected request (a bad key, a bad body) was neither retried nor counted.
            throw new DecisionModelUnavailable('http_'.$response->status());
        }

        return $response->json();
    }

    private function http(): PendingRequest
    {
        $request = app(ProviderGateway::class)->http('decision_model', owner: $this)
            ->baseUrl($this->baseUrl())
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
}
