<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\ObfuscationRecovery\BuildsPortablePublication;
use Tests\TestCase;

final class RecoveryPortablePublicationTest extends TestCase
{
    use BuildsPortablePublication;

    #[DataProvider('cases')]
    public function test_generated_articles_pass_actual_capture_transport_materialization_and_nzb_output(string $case, bool $tied, int $parts, int $files, int $targets): void
    {
        $this->buildPortablePublication($case, $tied, $parts, $files, $targets);
    }

    public function test_sparse_acceptance_does_not_claim_to_detect_unobserved_middle_substitution(): void
    {
        $fixture = $this->buildPortablePublication('media7', false, 43, 8, 8, true);
        $oracle = new Process(['python3', base_path('tests/Support/ObfuscationRecovery/fixture_oracle.py'),
            $fixture['root'], $fixture['nzb'], (string) $fixture['port']]);
        $oracle->setTimeout(60)->mustRun();
        $result = json_decode($oracle->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(2, $result['mismatches']);
        $this->assertSame(8, $result['files']);
    }

    public static function cases(): array
    {
        return [['media1', false, 5, 2, 2], ['media1', true, 5, 2, 3], ['media7', false, 43, 8, 8],
            ['media32', false, 593, 33, 33], ['rar4', false, 12, 5, 9], ['rar4', true, 12, 5, 10]];
    }
}
