<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\AdditionalProcessing\ArchiveExtractionService;
use Symfony\Component\Process\Process;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ObfuscationRecovery\SyntheticPosting;
use Tests\TestCase;

final class RecoveryArchiveFixtureTest extends TestCase
{
    use IsolatedSqliteDatabase;

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_existing_archive_inspector_reads_cached_volume_prefixes_without_extraction(): void
    {
        $this->bootIsolatedDatabase();
        $fixture = SyntheticPosting::rar([1533600, 1533600, 1533600, 816800]);
        $archives = app(ArchiveExtractionService::class);
        foreach ($fixture['volumes'] as $volume) {
            $listing = $archives->listRecoveredPrefix(substr($volume, 0, 16384));
            $this->assertFalse($listing['hasPassword']);
            $this->assertCount(1, $listing['files']);
            $this->assertSame('fixture.bin', $listing['files'][0]['name']);
            $this->assertSame(5417320, $listing['files'][0]['size']);
            $this->assertNull($archives->recoveredStoredNfo(substr($volume, 0, 16384)));
        }
    }

    public function test_stored_nfo_requires_complete_bounded_bytes_and_matching_crc(): void
    {
        $this->bootIsolatedDatabase();
        $fixture = SyntheticPosting::rar([1024], 'info.nfo');
        $archives = app(ArchiveExtractionService::class);
        $this->assertSame($fixture['content'], $archives->recoveredStoredNfo($fixture['volumes'][0]));
        $this->assertNull($archives->recoveredStoredNfo(substr($fixture['volumes'][0], 0, 128)));
        $damaged = $fixture['volumes'][0];
        $damaged[200] = chr(ord($damaged[200]) ^ 1);
        $this->assertNull($archives->recoveredStoredNfo($damaged));
    }

    public function test_generated_volumes_verify_and_extract_with_the_real_archive_tool(): void
    {
        $this->assertTrue(is_executable('/usr/bin/unrar'), 'Archive fixture validation is a required integration layer.');
        $root = $this->makeTempDirectory('recovery-archive-fixture');
        $fixture = SyntheticPosting::rar([1533600, 1533600, 1533600, 816800]);
        foreach ($fixture['volumes'] as $index => $volume) {
            file_put_contents($root.sprintf('/fixture.part%02d.rar', $index + 1), $volume);
        }
        $verify = new Process(['/usr/bin/unrar', 't', '-p-', '-idq', $root.'/fixture.part01.rar']);
        $verify->setTimeout(10)->mustRun();
        $output = $root.'/output';
        mkdir($output, 0700);
        $extract = new Process(['/usr/bin/unrar', 'x', '-p-', '-idq', '-o-', $root.'/fixture.part01.rar', $output.'/']);
        $extract->setTimeout(10)->mustRun();
        $this->assertSame(5417320, filesize($output.'/fixture.bin'));
        $this->assertSame('c9c88fab31c37d1a0a05fbbd429a53cc', hash_file('md5', $output.'/fixture.bin'));
        $this->assertSame(hash('sha256', $fixture['content']), hash_file('sha256', $output.'/fixture.bin'));
    }
}
