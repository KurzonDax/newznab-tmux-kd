<?php

declare(strict_types=1);

namespace Tests\Unit\AdditionalProcessing;

use App\Models\Release;
use App\Services\AdditionalProcessing\ArchiveExtractionService;
use App\Services\AdditionalProcessing\Enums\PasswordVerdict;
use App\Services\AdditionalProcessing\State\ReleaseProcessingContext;
use App\Services\Releases\ReleaseBrowseService;
use dariusiii\rarinfo\ArchiveInfo;
use dariusiii\rarinfo\Par2Info;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Post-processing opens an encrypted archive with the password its release's NZB carries.
 */
class ArchiveExtractionServiceNzbPasswordTest extends TestCase
{
    use CreatesProcessingConfiguration;

    private const string PASSWORD = 'it\'s $x';

    private const string QUOTED_PASSWORD = '-p\'it\'\\\'\'s $x\'';

    /** @var list<string> */
    private array $processCommands = [];

    /** @var list<string> */
    private array $plainCommands = [];

    private string $lastTmp = '';

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_a_data_encrypted_rar_is_extracted_with_the_nzb_password(): void
    {
        $context = $this->context(self::PASSWORD);

        $result = $this->tools($this->dataEncryptedRar(), exitCodes: [3])
            ->processCompressedData('ARCHIVE', $context, $this->tmp());

        $this->assertCount(1, $this->processCommands);
        $this->assertStringContainsString('" e -ai -ep -c- -id -inul -kb -or '.self::QUOTED_PASSWORD.' -r -y "', $this->processCommands[0]);
        $this->assertStringNotContainsString('-p-', $this->processCommands[0]);
        $this->assertSame([], $this->plainCommands);
        $this->assertTrue($result['success']);
        $this->assertFalse($result['hasPassword']);
        $this->assertSame(ReleaseBrowseService::PASSWD_NONE, $result['passwordStatus']);
        $this->assertFalse($context->nzbPasswordRejected);
    }

    public function test_unrar_rejecting_the_nzb_password_leaves_the_rar_passworded(): void
    {
        $context = $this->context(self::PASSWORD);

        $result = $this->tools($this->dataEncryptedRar(listed: false), exitCodes: [11])
            ->processCompressedData('ARCHIVE', $context, $this->tmp());

        $this->assertFalse($result['success']);
        $this->assertTrue($result['hasPassword']);
        $this->assertSame(ReleaseBrowseService::PASSWD_RAR, $result['passwordStatus']);
        $this->assertTrue($context->nzbPasswordRejected);
    }

    public function test_without_a_password_the_rar_runs_todays_command(): void
    {
        $context = $this->context(null);

        $this->tools($this->dataEncryptedRar())->processCompressedData('ARCHIVE', $context, $tmp = $this->tmp());

        $this->assertSame([], $this->processCommands);
        $this->assertCount(1, $this->plainCommands);
        $this->assertMatchesRegularExpression(
            '#^"/usr/bin/unrar" e -ai -ep -c- -id -inul -kb -or -p- -r -y "'.preg_quote($tmp, '#').'[^"]+\.rar" "'.preg_quote($tmp, '#').'unrar/"$#',
            $this->plainCommands[0],
        );
        $this->assertFalse($context->nzbPasswordRejected);
    }

    public function test_a_header_encrypted_rar_is_listed_and_extracted_with_the_nzb_password(): void
    {
        $context = $this->context(self::PASSWORD);
        $listing = "UNRAR 7.01 freeware      Copyright (c) 1993-2024 Alexander Roshal\n\n"
            ."Archive: /tmp/x.rar\nDetails: RAR 5, volume, encrypted headers\n\n"
            ."        Name: payload.bin\n        Type: File\n        Size: 3000000\n"
            ." Packed size: 499744\n       mtime: 2026-10-21 20:03:26,841307294\n"
            ."  Attributes: -rw-r--r--\n       Flags: encrypted, split after\n\n"
            ."        Name: subs\n        Type: Directory\n       mtime: 2026-10-21 20:03:26,841307294\n";

        $result = $this->tools($this->headerEncryptedRar(), exitCodes: [1, 3], outputs: [$listing, ''])
            ->processCompressedData('ARCHIVE', $context, $this->tmp());

        $this->assertCount(2, $this->processCommands);
        $this->assertMatchesRegularExpression('#^"/usr/bin/unrar" lt -c- '.preg_quote(self::QUOTED_PASSWORD, '#').' "[^"]+\.rar"$#', $this->processCommands[0]);
        $this->assertStringNotContainsString('-inul', $this->processCommands[0]);
        $this->assertStringContainsString('" e -ai -ep -c- -id -inul -kb -or '.self::QUOTED_PASSWORD.' -r -y "', $this->processCommands[1]);
        $this->assertSame([[
            'name' => 'payload.bin',
            'size' => 3000000,
            'pass' => 1,
            'date' => strtotime('2026-10-21 20:03:26'),
        ]], $result['files']);
        $this->assertTrue($result['success']);
        $this->assertFalse($result['hasPassword']);
        $this->assertSame(ReleaseBrowseService::PASSWD_NONE, $result['passwordStatus']);
        $this->assertSame('r', $result['archiveMarker']);
        $this->assertFalse($result['manifestComplete']);
        $this->assertArrayNotHasKey('dataSummary', $result);
        $this->assertFalse($context->nzbPasswordRejected);
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function rejectedHeaderListings(): array
    {
        return [
            'RAR5 wrong password' => [11, ''],
            'RAR4 wrong password lists no names' => [3, "Archive: /tmp/x.rar\nDetails: RAR 4, encrypted headers\n"],
        ];
    }

    #[DataProvider('rejectedHeaderListings')]
    public function test_a_header_encrypted_rar_whose_listing_fails_stays_passworded(int $exitCode, string $output): void
    {
        $context = $this->context(self::PASSWORD);

        $result = $this->tools($this->headerEncryptedRar(), exitCodes: [$exitCode], outputs: [$output])
            ->processCompressedData('ARCHIVE', $context, $this->tmp());

        $this->assertCount(1, $this->processCommands, 'Nothing is extracted after a rejected listing.');
        $this->assertSame([
            'success' => false,
            'files' => [],
            'hasPassword' => true,
            'passwordStatus' => ReleaseBrowseService::PASSWD_RAR,
        ], $result);
        $this->assertTrue($context->nzbPasswordRejected);
    }

    public function test_a_header_encrypted_rar_without_a_password_stays_passworded_without_running_unrar(): void
    {
        $context = $this->context(null);

        $result = $this->tools($this->headerEncryptedRar())->processCompressedData('ARCHIVE', $context, $this->tmp());

        $this->assertTrue($result['hasPassword']);
        $this->assertSame([], $this->processCommands);
        $this->assertSame([], $this->plainCommands);
    }

    /**
     * @return array<string, array{int, bool}>
     */
    public static function zipExitCodes(): array
    {
        return [
            'opened' => [0, false],
            'incorrect password' => [82, true],
            'encryption unzip cannot decrypt' => [81, false],
            'no end of archive in the fetched head' => [9, false],
        ];
    }

    #[DataProvider('zipExitCodes')]
    public function test_a_zip_is_extracted_with_the_nzb_password(int $exitCode, bool $rejected): void
    {
        $context = $this->context(self::PASSWORD);

        $result = $this->tools($this->zip(listed: ! $rejected), exitCodes: [$exitCode])
            ->processCompressedData('ARCHIVE', $context, $this->tmp());

        $this->assertCount(1, $this->processCommands);
        $this->assertStringContainsString('"/usr/bin/unzip" -P \'it\'\\\'\'s $x\' -qq -o "', $this->processCommands[0]);
        $this->assertStringNotContainsString('__NNTMUX_UNZIP_EXIT_CODE__', $this->processCommands[0]);
        $this->assertSame([], $this->plainCommands);
        $this->assertSame($rejected, $result['hasPassword']);
        $this->assertSame($rejected ? ReleaseBrowseService::PASSWD_RAR : ReleaseBrowseService::PASSWD_NONE, $result['passwordStatus']);
        $this->assertSame($rejected, $context->nzbPasswordRejected);
    }

    public function test_without_an_extraction_tool_the_nzb_password_counts_as_rejected(): void
    {
        foreach ([$this->dataEncryptedRar(), $this->zip()] as $archiveInfo) {
            $context = $this->context(self::PASSWORD);
            $service = new ArchiveExtractionService(
                $this->makeConfig([
                    'extractUsingRarInfo' => true,
                    'unrarPath' => '/usr/bin/unrar',
                    'unzipPath' => '/usr/bin/unzip',
                ]),
                $archiveInfo,
                Mockery::mock(Par2Info::class),
                function (string $command): string {
                    $this->plainCommands[] = $command;

                    return '';
                },
                function (string $command): array {
                    $this->processCommands[] = $command;

                    return ['exitCode' => 0, 'output' => ''];
                },
            );

            $result = $service->processCompressedData('ARCHIVE', $context, $this->tmp());

            $this->assertFalse($result['hasPassword']);
            $this->assertTrue($context->nzbPasswordRejected);
        }

        $this->assertSame([], $this->processCommands);
        $this->assertSame([], $this->plainCommands);
    }

    public function test_an_encrypted_7z_with_an_nzb_password_is_not_passworded(): void
    {
        $data = (string) file_get_contents(base_path('tests/Fixtures/SevenZip/visible.7z'));
        $service = new ArchiveExtractionService($this->makeConfig(['processPasswords' => true]));

        $withPassword = $service->processCompressedData($data, $this->context(self::PASSWORD), $this->tmp());
        $withoutPassword = $service->processCompressedData($data, $this->context(null), $this->tmp());

        $this->assertSame(PasswordVerdict::VerifiedEncrypted, $withPassword['verdict']);
        $this->assertNotSame([], $withPassword['files']);
        $this->assertFalse($withPassword['hasPassword']);
        $this->assertSame(ReleaseBrowseService::PASSWD_NONE, $withPassword['passwordStatus']);
        $this->assertTrue($withoutPassword['hasPassword']);
        $this->assertSame(ReleaseBrowseService::PASSWD_RAR, $withoutPassword['passwordStatus']);
    }

    public function test_an_encrypted_verdict_marks_the_release_passworded_only_without_an_nzb_password(): void
    {
        $withPassword = $this->context(self::PASSWORD);
        $withPassword->recordPasswordVerdict(PasswordVerdict::VerifiedEncrypted, 'encrypted-folder');
        $withoutPassword = $this->context(null);
        $withoutPassword->recordPasswordVerdict(PasswordVerdict::VerifiedEncrypted, 'encrypted-folder');

        $this->assertSame(PasswordVerdict::VerifiedEncrypted, $withPassword->passwordVerdict);
        $this->assertFalse($withPassword->releaseHasPassword);
        $this->assertSame(0, $withPassword->passwordStatus);
        $this->assertTrue($withoutPassword->releaseHasPassword);
        $this->assertSame(ReleaseBrowseService::PASSWD_RAR, $withoutPassword->passwordStatus);
    }

    public function test_reset_clears_the_nzb_password_state(): void
    {
        $context = $this->context(self::PASSWORD);
        $context->nzbPasswordRejected = true;

        $context->reset();

        $this->assertNull($context->nzbPassword);
        $this->assertFalse($context->nzbPasswordRejected);
    }

    public function test_single_file_extraction_from_a_path_passes_the_password_and_never_reads_ciphertext(): void
    {
        $archiveInfo = $this->mockArchiveInfo();
        $archiveInfo->shouldReceive('open')->andReturnTrue();
        $archiveInfo->shouldReceive('getArchiveFileList')->andReturn([['name' => 'movie.mkv', 'size' => 10, 'pass' => 1]]);
        $archiveInfo->shouldNotReceive('getFileData');
        $service = $this->singleFileTools($archiveInfo);

        $path = $service->extractSpecificFileToPath('/data/a.rar', 'movie.mkv', $this->tmp(), keepBroken: true, password: self::PASSWORD);

        $this->assertNull($path);
        $this->assertSame([
            '"/usr/bin/unrar" e -y -c- -inul '.self::QUOTED_PASSWORD.' -kb "/data/a.rar" "movie.mkv" "'.$this->lastTmp.'"',
            '/usr/bin/unzip -P \'it\'\\\'\'s $x\' -j "/data/a.rar" "movie.mkv" -d "'.$this->lastTmp.'"',
        ], $this->plainCommands);
    }

    public function test_single_file_extraction_from_data_passes_the_password_and_never_reads_ciphertext(): void
    {
        $archiveInfo = $this->mockArchiveInfo();
        $archiveInfo->shouldReceive('setData')->with('ARCHIVE', true)->andReturnTrue();
        $archiveInfo->shouldReceive('getArchiveFileList')->andReturn([
            ['name' => 'movie.mkv', 'size' => 10, 'pass' => 1],
            ['name' => 'release.nfo', 'size' => 10, 'pass' => 0],
        ]);
        $archiveInfo->shouldReceive('getFileData')->once()->with('release.nfo')->andReturn('NFO');
        $archiveInfo->shouldNotReceive('getFileData')->with('movie.mkv');
        $service = $this->singleFileTools($archiveInfo);

        $extracted = $service->extractSpecificFiles('ARCHIVE', ['movie.mkv', 'release.nfo'], $this->tmp(), self::PASSWORD);

        $this->assertSame(['release.nfo' => 'NFO'], $extracted);
        $this->assertCount(2, $this->plainCommands);
        $this->assertMatchesRegularExpression('#^"/usr/bin/unrar" e -y -c- -inul '.preg_quote(self::QUOTED_PASSWORD, '#').' "[^"]+\.rar" "movie\.mkv" "#', $this->plainCommands[0]);
        $this->assertMatchesRegularExpression('#^/usr/bin/unzip -P \'it\'\\\\\'\'s \$x\' -j "[^"]+\.zip" "movie\.mkv" -d "#', $this->plainCommands[1]);
    }

    public function test_single_file_extraction_without_a_password_keeps_todays_commands(): void
    {
        $archiveInfo = $this->mockArchiveInfo();
        $archiveInfo->shouldReceive('setData')->andReturnFalse();
        $archiveInfo->shouldNotReceive('getArchiveFileList');
        $service = $this->singleFileTools($archiveInfo);

        $service->extractSpecificFile('ARCHIVE', 'movie.mkv', $this->tmp());

        $this->assertMatchesRegularExpression('#^"/usr/bin/unrar" e -y -c- -inul -p- "[^"]+\.rar" "movie\.mkv" "#', $this->plainCommands[0]);
        $this->assertMatchesRegularExpression('#^/usr/bin/unzip -j "[^"]+\.zip" "movie\.mkv" -d "#', $this->plainCommands[1]);
    }

    private function tmp(): string
    {
        return $this->lastTmp = $this->makeTempDirectory('nzb-password-archive').'/';
    }

    private function context(?string $password): ReleaseProcessingContext
    {
        $context = new ReleaseProcessingContext(new Release(['id' => 1, 'guid' => 'fixture-guid']));
        $context->nzbPassword = $password;

        return $context;
    }

    /**
     * @param  list<int>  $exitCodes
     * @param  list<string>  $outputs
     */
    private function tools(ArchiveInfo $archiveInfo, array $exitCodes = [], array $outputs = []): ArchiveExtractionService
    {
        return new ArchiveExtractionService(
            $this->makeConfig([
                'extractUsingRarInfo' => false,
                'unrarPath' => '/usr/bin/unrar',
                'unzipPath' => '/usr/bin/unzip',
            ]),
            $archiveInfo,
            Mockery::mock(Par2Info::class),
            function (string $command): string {
                $this->plainCommands[] = $command;

                return '';
            },
            function (string $command) use (&$exitCodes, &$outputs): array {
                $this->processCommands[] = $command;

                return ['exitCode' => array_shift($exitCodes) ?? 0, 'output' => array_shift($outputs) ?? ''];
            },
        );
    }

    private function singleFileTools(ArchiveInfo $archiveInfo): ArchiveExtractionService
    {
        return new ArchiveExtractionService(
            $this->makeConfig(['unrarPath' => '/usr/bin/unrar', 'unzipPath' => '/usr/bin/unzip']),
            $archiveInfo,
            Mockery::mock(Par2Info::class),
            function (string $command): string {
                $this->plainCommands[] = $command;

                return '';
            },
            function (string $command): array {
                $this->fail('Single-file extraction reads no exit code: '.$command);
            },
        );
    }

    private function mockArchiveInfo(): ArchiveInfo&MockInterface
    {
        $archiveInfo = Mockery::mock(ArchiveInfo::class);
        $archiveInfo->shouldReceive('setExternalClients')->with([ArchiveInfo::TYPE_RAR => '/usr/bin/unrar']);

        return $archiveInfo;
    }

    private function dataEncryptedRar(bool $listed = true): ArchiveInfo&MockInterface
    {
        return $this->archive(ArchiveInfo::TYPE_RAR, encryptedHeaders: false, listed: $listed);
    }

    private function headerEncryptedRar(): ArchiveInfo&MockInterface
    {
        return $this->archive(ArchiveInfo::TYPE_RAR, encryptedHeaders: true, listed: false);
    }

    private function zip(bool $listed = true): ArchiveInfo&MockInterface
    {
        return $this->archive(ArchiveInfo::TYPE_ZIP, encryptedHeaders: false, listed: $listed);
    }

    private function archive(int $type, bool $encryptedHeaders, bool $listed): ArchiveInfo&MockInterface
    {
        $archiveInfo = $this->mockArchiveInfo();
        $archiveInfo->error = '';
        $archiveInfo->isEncrypted = $encryptedHeaders;
        $archiveInfo->shouldReceive('getReader')->andReturn(null);
        $archiveInfo->shouldReceive('setData')->with('ARCHIVE', true)->andReturnTrue();
        $archiveInfo->shouldReceive('getSummary')->with(true)->andReturn([
            'main_type' => $type,
            'is_encrypted' => $encryptedHeaders ? 1 : 0,
        ]);
        $archiveInfo->shouldReceive('containsArchive')->andReturnFalse();
        if ($listed) {
            $archiveInfo->shouldReceive('getArchiveFileList')->andReturn([['name' => 'payload.bin', 'size' => 3000000, 'pass' => 1]]);
        } else {
            $archiveInfo->shouldNotReceive('getArchiveFileList');
        }

        return $archiveInfo;
    }
}
