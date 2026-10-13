<?php

declare(strict_types=1);

namespace App\Services\DecisionModel\Exceptions;

/**
 * The service gave no usable response: it is disabled, its breaker is open,
 * the request failed, or the body was not the expected shape.
 */
final class DecisionModelUnavailable extends DecisionModelException {}
