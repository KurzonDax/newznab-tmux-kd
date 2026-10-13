<?php

declare(strict_types=1);

namespace App\Services\DecisionModel;

use App\Services\DecisionModel\Exceptions\MalformedDecisionAnswer;
use App\Services\DecisionModel\Questions\DecisionQuestion;

/**
 * One kind of decision the model is asked for.
 *
 * A use case owns everything specific to its decision: the state it describes
 * a subject with, the questions and criteria it asks, and what the answers
 * mean. The client, the runner and the decision log are shared and know none
 * of it, so adding a use case means implementing this contract and registering
 * the implementation with {@see DecisionUseCaseRegistry}.
 */
interface DecisionUseCase
{
    /**
     * The name decisions are logged and looked up under.
     */
    public function name(): string;

    /**
     * The version of the criteria the questions are built from, logged with
     * every decision so answers can be compared like for like.
     */
    public function criteriaVersion(): string;

    /**
     * The state the model is asked about, exactly as it is sent and logged.
     *
     * @return string|array<string, mixed>
     */
    public function state(object $subject): string|array;

    /**
     * The questions of the next request, given the responses so far. An empty
     * map means there is nothing more to ask.
     *
     * @param  list<DecisionResponse>  $responses
     * @return array<string, DecisionQuestion> Question id => question.
     */
    public function questions(object $subject, array $responses): array;

    /**
     * What the answers mean for the subject.
     *
     * @param  list<DecisionResponse>  $responses
     *
     * @throws MalformedDecisionAnswer when an answer the verdict depends on is missing or unusable
     */
    public function interpret(object $subject, array $responses): DecisionVerdict;
}
