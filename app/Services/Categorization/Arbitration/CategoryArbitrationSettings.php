<?php

declare(strict_types=1);

namespace App\Services\Categorization\Arbitration;

use App\Models\Settings;
use App\Services\DecisionModel\DecisionMode;
use App\Support\SettingNumber;

/**
 * The three admin settings that steer category arbitration, resolved to their
 * final values.
 *
 * Both thresholds are confidences between 0 and 1. A blank, missing,
 * non-numeric or out-of-range stored value resolves to the coded default.
 */
final readonly class CategoryArbitrationSettings
{
    public const float DEFAULT_APPLY_THRESHOLD = 0.80;

    public const float DEFAULT_ARBITRATE_BELOW = 0.70;

    /**
     * @param  float  $applyThreshold  A model answer is accepted at or above this confidence.
     * @param  float  $arbitrateBelow  A pipeline result is sent to the model below this confidence.
     */
    public function __construct(
        public DecisionMode $mode,
        public float $applyThreshold = self::DEFAULT_APPLY_THRESHOLD,
        public float $arbitrateBelow = self::DEFAULT_ARBITRATE_BELOW,
    ) {}

    /**
     * One query while the mode is off; the thresholds are only read when they
     * can matter.
     */
    public static function read(): self
    {
        $mode = DecisionMode::fromSetting(Settings::settingValue('decision_model_mode'));

        if ($mode === DecisionMode::Off) {
            return new self($mode);
        }

        return new self(
            $mode,
            self::confidence('decision_model_apply_threshold', self::DEFAULT_APPLY_THRESHOLD),
            self::confidence('decision_model_arbitrate_below', self::DEFAULT_ARBITRATE_BELOW),
        );
    }

    private static function confidence(string $setting, float $default): float
    {
        $value = SettingNumber::float($setting, $default);

        return $value >= 0.0 && $value <= 1.0 ? $value : $default;
    }
}
