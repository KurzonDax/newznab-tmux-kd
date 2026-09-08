<?php

namespace Tests\Unit\ObfuscationRecovery;

use App\Enums\ObfuscationRecoveryProfile;
use App\Services\ObfuscationRecovery\RecoveryConfig;
use PHPUnit\Framework\TestCase;

class RecoveryConfigTest extends TestCase
{
    public function test_default_configuration_does_not_admit_either_profile(): void
    {
        $config = RecoveryConfig::fromValues([]);

        $this->assertFalse($config->admits('both', ObfuscationRecoveryProfile::Media));
        $this->assertFalse($config->admits('both', ObfuscationRecoveryProfile::Rar));
        $this->assertSame(2, $config->threads);
        $this->assertSame(144, $config->retentionHours);
        $this->assertSame(20 * 1048576, $config->mediaCandidateBytes);
        $this->assertSame(40 * 1048576, $config->rarCandidateBytes);
        $this->assertSame(4 * 1048576, $config->enrichmentReleaseBytes);
    }

    public function test_group_selection_is_the_only_profile_allowlist(): void
    {
        $config = RecoveryConfig::fromValues(['obfuscation_recovery_enabled' => '1']);

        foreach ([null, '', 'disabled', 'unknown', 'MEDIA'] as $selection) {
            $this->assertFalse($config->admits($selection, ObfuscationRecoveryProfile::Media));
            $this->assertFalse($config->admits($selection, ObfuscationRecoveryProfile::Rar));
        }
        $this->assertTrue($config->admits('media', ObfuscationRecoveryProfile::Media));
        $this->assertFalse($config->admits('media', ObfuscationRecoveryProfile::Rar));
        $this->assertTrue($config->admits('rar', ObfuscationRecoveryProfile::Rar));
        $this->assertFalse($config->admits('rar', ObfuscationRecoveryProfile::Media));
        $this->assertTrue($config->admits('both', ObfuscationRecoveryProfile::Media));
        $this->assertTrue($config->admits('both', ObfuscationRecoveryProfile::Rar));
    }

    public function test_corrupt_enablement_cannot_round_into_permission(): void
    {
        foreach (['1.1', '1e0', 'yes', 'true', '01', '', null] as $enabled) {
            $config = RecoveryConfig::fromValues(['obfuscation_recovery_enabled' => $enabled]);
            $this->assertFalse($config->admits('both', ObfuscationRecoveryProfile::Media));
        }
    }

    public function test_nonpositive_limits_fail_small_and_do_not_mean_unlimited(): void
    {
        $config = RecoveryConfig::fromValues([
            'obfuscation_recovery_enabled' => 1,
            'obfuscation_recovery_threads' => 0,
            'obfuscation_recovery_media_candidate_mib' => 0,
            'obfuscation_recovery_rar_candidate_mib' => -1,
            'obfuscation_recovery_retention_hours' => 0,
            'obfuscation_recovery_enrichment_release_mib' => 0,
        ]);

        $this->assertSame(1, $config->threads);
        $this->assertSame(144, $config->retentionHours);
        $this->assertFalse($config->admits('both', ObfuscationRecoveryProfile::Media));
        $this->assertFalse($config->admits('both', ObfuscationRecoveryProfile::Rar));
        $this->assertSame(0, $config->enrichmentReleaseBytes);
        $this->assertContains('media_construction_suspended', $config->diagnostics);
        $this->assertContains('rar_construction_suspended', $config->diagnostics);
    }

    public function test_structural_limits_win_over_larger_operator_limits(): void
    {
        $config = RecoveryConfig::fromValues([
            'obfuscation_recovery_threads' => 1000,
            'obfuscation_recovery_media_candidate_mib' => 200,
            'obfuscation_recovery_rar_candidate_mib' => 400,
            'obfuscation_recovery_enrichment_release_mib' => 40,
        ]);

        $this->assertSame(60, $config->threads);
        $this->assertSame(20 * 1048576, $config->mediaCandidateBytes);
        $this->assertSame(40 * 1048576, $config->rarCandidateBytes);
        $this->assertSame(4 * 1048576, $config->enrichmentReleaseBytes);
        $this->assertSame(200, $config->configuredValues['obfuscation_recovery_media_candidate_mib']);
    }
}
