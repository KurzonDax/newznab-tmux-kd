<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use App\Enums\ObfuscationRecoveryProfile;
use App\Models\Settings;
use App\Support\SettingNumber;

final readonly class RecoveryConfig
{
    public const array DEFAULTS = [
        'obfuscation_recovery_enabled' => 0,
        'obfuscation_recovery_threads' => 2,
        'obfuscation_recovery_media_candidate_mib' => 20,
        'obfuscation_recovery_rar_candidate_mib' => 40,
        'obfuscation_recovery_retention_hours' => 144,
        'obfuscation_recovery_enrichment_enabled' => 1,
        'obfuscation_recovery_enrichment_release_mib' => 4,
    ];

    /**
     * @param  array<string, int>  $configuredValues
     * @param  list<string>  $diagnostics
     */
    private function __construct(
        public bool $enabled,
        public int $threads,
        public int $mediaCandidateBytes,
        public int $rarCandidateBytes,
        public int $retentionHours,
        public bool $enrichmentEnabled,
        public int $enrichmentReleaseBytes,
        public array $configuredValues,
        public array $diagnostics,
    ) {}

    public static function fromSettings(): self
    {
        $values = $diagnostics = [];
        foreach (self::DEFAULTS as $key => $default) {
            $raw = Settings::settingValue($key);
            if (in_array($key, ['obfuscation_recovery_enabled', 'obfuscation_recovery_enrichment_enabled'], true)) {
                $values[$key] = $raw;
            } else {
                $values[$key] = SettingNumber::int($key, $default);
                if ($raw !== null && $raw !== '' && ! is_numeric($raw)) {
                    $diagnostics[] = $key.'_invalid_numeric';
                }
            }
        }

        return self::fromValues($values, $diagnostics);
    }

    /** @param array<string, mixed> $values
     * @param  list<string>  $diagnostics
     */
    public static function fromValues(array $values, array $diagnostics = []): self
    {
        $resolved = [];
        foreach (self::DEFAULTS as $key => $default) {
            $value = $values[$key] ?? null;
            $resolved[$key] = is_numeric($value) ? (int) $value : $default;
        }

        foreach (['media', 'rar'] as $profile) {
            if ($resolved['obfuscation_recovery_'.$profile.'_candidate_mib'] <= 0) {
                $diagnostics[] = $profile.'_construction_suspended';
            }
        }

        return new self(
            enabled: in_array($values['obfuscation_recovery_enabled'] ?? null, [1, '1', true], true),
            threads: max(1, min(60, $resolved['obfuscation_recovery_threads'])),
            mediaCandidateBytes: max(0, min(20, $resolved['obfuscation_recovery_media_candidate_mib'])) * 1048576,
            rarCandidateBytes: max(0, min(40, $resolved['obfuscation_recovery_rar_candidate_mib'])) * 1048576,
            retentionHours: $resolved['obfuscation_recovery_retention_hours'] > 0 ? $resolved['obfuscation_recovery_retention_hours'] : 144,
            enrichmentEnabled: in_array($values['obfuscation_recovery_enrichment_enabled'] ?? 1, [1, '1', true], true),
            enrichmentReleaseBytes: max(0, min(4, $resolved['obfuscation_recovery_enrichment_release_mib'])) * 1048576,
            configuredValues: $resolved,
            diagnostics: $diagnostics,
        );
    }

    public function admits(?string $selection, ObfuscationRecoveryProfile $profile): bool
    {
        $selected = ObfuscationRecoveryProfile::tryFrom($selection ?? '') ?? ObfuscationRecoveryProfile::Disabled;

        return $this->enabled && $selected->permits($profile)
            && match ($profile) {
                ObfuscationRecoveryProfile::Media => $this->mediaCandidateBytes > 0,
                ObfuscationRecoveryProfile::Rar => $this->rarCandidateBytes > 0,
                default => false,
            };
    }
}
