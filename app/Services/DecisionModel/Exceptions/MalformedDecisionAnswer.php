<?php

declare(strict_types=1);

namespace App\Services\DecisionModel\Exceptions;

/**
 * The service responded, but an answer a use case depends on is missing,
 * failed, or names something the criteria do not offer.
 */
final class MalformedDecisionAnswer extends DecisionModelException {}
