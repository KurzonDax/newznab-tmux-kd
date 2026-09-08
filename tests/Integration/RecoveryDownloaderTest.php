<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\ObfuscationRecovery\BuildsPortablePublication;
use Tests\TestCase;

final class RecoveryDownloaderTest extends TestCase
{
    use BuildsPortablePublication;

    #[DataProvider('cases')]
    public function test_pinned_clients_download_the_actual_emitted_inventory(string $client, string $case, bool $tied, int $parts, int $files, int $targets): void
    {
        $fixture = $this->buildPortablePublication($case, $tied, $parts, $files, $targets);
        $client = new Process(['python3', base_path('tests/Support/ObfuscationRecovery/fixture_clients.py'),
            $fixture['root'], $fixture['nzb'], (string) $fixture['port'], $client]);
        $client->setTimeout(120)->mustRun();
        $result = json_decode($client->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($files, $result['files']);
        $this->assertSame('matched', $result['whole_file_hashes']);
        $this->assertSame('verified', $result['par2']);
    }

    public static function cases(): array
    {
        $cases = [];
        foreach (['sabnzbd', 'nzbget'] as $client) {
            foreach ([['media1', false, 5, 2, 2], ['media1', true, 5, 2, 3], ['media7', false, 43, 8, 8],
                ['rar4', false, 12, 5, 9], ['rar4', true, 12, 5, 10], ['mkv', false, 0, 2, 2],
                ['mp4', false, 0, 2, 2], ['mixed', false, 0, 3, 3]] as $case) {
                $cases[$client.'-'.$case[0].($case[1] ? '-tied' : '')] = [$client, ...$case];
            }
        }

        return $cases;
    }
}
