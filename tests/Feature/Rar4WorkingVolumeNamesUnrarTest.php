<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AdditionalProcessing\ArchiveExtractionService;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ObfuscationRecovery\SyntheticPosting;
use Tests\TestCase;
use Tests\Unit\AdditionalProcessing\CreatesProcessingConfiguration;

/**
 * unrar decides which next-volume name it looks for, so this runs the real
 * binary on a three-volume stored RAR4 set with the new-numbering flag clear,
 * with hand-written headers: no rar binary is needed. It skips itself without
 * unrar.
 */
class Rar4WorkingVolumeNamesUnrarTest extends TestCase
{
    use CreatesProcessingConfiguration;

    private const string ENTRY = 'movie.mp4';

    private const int PAYLOAD_BYTES = 150_000;

    /** A third of the payload plus each volume's headers: 59 bytes and the entry name. */
    private const int VOLUME_BYTES = 50_068;

    private string $payload;

    /** @var list<string> */
    private array $volumes;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::unrarBinary() === null) {
            $this->markTestSkipped('unrar is required for this test.');
        }

        $set = SyntheticPosting::rar(
            array_fill(0, 3, self::VOLUME_BYTES),
            self::ENTRY,
            random_bytes(self::PAYLOAD_BYTES),
            newNumbering: false,
        );
        $this->payload = $set['content'];
        $this->volumes = $set['volumes'];
    }

    #[Test]
    public function old_style_names_return_the_whole_file(): void
    {
        $extracted = $this->extract(
            ['preview-archive.rar', 'preview-archive.r00', 'preview-archive.r01'],
            $this->volumes,
        );

        $this->assertSame($this->payload, $extracted);
    }

    #[Test]
    public function old_style_names_return_the_bytes_held_when_the_last_volume_is_cut_short(): void
    {
        $volumes = $this->volumes;
        $volumes[2] = substr($volumes[2], 0, -30_000);

        $extracted = $this->extract(['preview-archive.rar', 'preview-archive.r00', 'preview-archive.r01'], $volumes);

        $this->assertGreaterThan(100_000, strlen($extracted));
        $this->assertLessThan(self::PAYLOAD_BYTES, strlen($extracted));
        $this->assertStringStartsWith($extracted, $this->payload);
    }

    #[Test]
    public function part_names_stop_at_the_first_volume(): void
    {
        $extracted = $this->extract(
            ['preview-archive.part001.rar', 'preview-archive.part002.rar', 'preview-archive.part003.rar'],
            $this->volumes,
        );

        $this->assertSame(substr($this->payload, 0, 50_000), $extracted);
    }

    /**
     * @param  list<string>  $names
     * @param  list<string>  $volumes
     */
    private function extract(array $names, array $volumes): string
    {
        $directory = $this->makeTempDirectory('rar4-working-volumes').'/';
        foreach ($names as $index => $name) {
            file_put_contents($directory.$name, $volumes[$index]);
        }

        $service = new ArchiveExtractionService($this->makeConfig(['unrarPath' => self::unrarBinary()]));
        $path = $service->extractSpecificFileToPath($directory.$names[0], self::ENTRY, $directory.'unrar/', keepBroken: true);

        $this->assertNotNull($path);

        return (string) file_get_contents($path);
    }

    private static function unrarBinary(): ?string
    {
        $path = trim((string) shell_exec('command -v unrar 2>/dev/null'));

        return $path === '' ? null : $path;
    }
}
