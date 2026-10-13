<?php

declare(strict_types=1);

namespace App\Services\DecisionModel;

use App\Services\DecisionModel\Exceptions\DecisionModelException;
use App\Services\DecisionModel\Exceptions\DecisionModelUnavailable;

/**
 * Carries one subject through a use case: builds the state, sends each request
 * the use case asks for in turn, and has the use case interpret the answers.
 *
 * A failure of the service or an unusable answer ends the run with a reason
 * instead of an exception, so a caller can log it and keep its own answer.
 */
final readonly class DecisionRunner
{
    /** A use case that keeps asking is broken; stop it rather than loop. */
    private const int MAX_REQUESTS = 5;

    public function __construct(
        private DecisionModelClient $client,
        private DecisionUseCaseRegistry $useCases,
    ) {}

    public function run(string $useCase, object $subject): DecisionRun
    {
        $case = $this->useCases->get($useCase);
        $state = $case->state($subject);
        $responses = [];

        try {
            while (($questions = $case->questions($subject, $responses)) !== []) {
                if (count($responses) >= self::MAX_REQUESTS) {
                    throw new DecisionModelUnavailable('too_many_requests');
                }

                $responses[] = $this->client->ask($state, $questions);
            }

            $verdict = $case->interpret($subject, $responses);
        } catch (DecisionModelException $failure) {
            return new DecisionRun($case->name(), $case->criteriaVersion(), $state, $responses, null, $failure->reason);
        }

        return new DecisionRun($case->name(), $case->criteriaVersion(), $state, $responses, $verdict);
    }
}
