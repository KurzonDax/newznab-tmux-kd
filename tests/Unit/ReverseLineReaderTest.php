<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ReverseLineReader;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use PHPUnit\Framework\TestCase;
use Tests\Support\LogReadFilter;

class ReverseLineReaderTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = tempnam(sys_get_temp_dir(), 'reverse-log-');
    }

    protected function tearDown(): void
    {
        foreach ([$this->path, $this->path.'.rotated'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    public function test_append_and_rotation_keep_the_open_snapshot(): void
    {
        file_put_contents($this->path, "first\n".str_repeat("middle\n", 20000).'last');
        $lines = (new ReverseLineReader)->lines($this->path);
        self::assertSame('last', $lines->current());
        file_put_contents($this->path, "\nappended", FILE_APPEND);
        rename($this->path, $this->path.'.rotated');
        file_put_contents($this->path, 'replacement');
        $count = 0;
        $oldest = null;
        foreach ($lines as $line) {
            if (in_array($line, ['appended', 'replacement'], true)) {
                self::fail('Read beyond the open snapshot.');
            }
            $oldest = $line;
            $count++;
        }
        self::assertSame(20002, $count);
        self::assertSame('first', $oldest);
    }

    public function test_truncation_discards_incomplete_lines_without_throwing(): void
    {
        file_put_contents($this->path, "old\n".str_repeat('x', 150000)."\nlast");
        $lines = (new ReverseLineReader)->lines($this->path);
        self::assertSame('last', $lines->current());
        file_put_contents($this->path, 'replacement');
        $lines->next();
        self::assertFalse($lines->valid());
    }

    #[WithoutErrorHandler]
    public function test_missing_and_empty_files_are_safe(): void
    {
        self::assertSame([''], iterator_to_array((new ReverseLineReader)->lines($this->path)));
        unlink($this->path);
        self::assertSame([], iterator_to_array((new ReverseLineReader)->lines($this->path)));
    }

    public function test_stopping_at_tail_bounds_bytes_read_and_closes_handle(): void
    {
        if (! in_array('registration-log-count', stream_get_filters(), true)) {
            stream_filter_register('registration-log-count', LogReadFilter::class);
        }
        LogReadFilter::$bytes = 0;
        LogReadFilter::$closed = false;
        $handle = fopen($this->path, 'wb');
        $chunk = str_repeat("synthetic\n", 1000);
        for ($index = 0; $index < 10; $index++) {
            fwrite($handle, $chunk);
        }
        fwrite($handle, 'newest');
        fclose($handle);
        $newest = (function (): ?string {
            $lines = (new ReverseLineReader)->lines('php://filter/read=registration-log-count/resource='.$this->path);

            return $lines->current();
        })();
        self::assertSame('newest', $newest);
        self::assertLessThanOrEqual(2 * 65536, LogReadFilter::$bytes);
        self::assertGreaterThan(0, LogReadFilter::$bytes);
        self::assertTrue(LogReadFilter::$closed);
    }

    public function test_reconstructs_utf8_split_at_a_block_boundary(): void
    {
        $line = str_repeat('a', 65535).'雪'.str_repeat('b', 65534);
        file_put_contents($this->path, $line);
        self::assertSame([$line], iterator_to_array((new ReverseLineReader)->lines($this->path)));
        file_put_contents($this->path, str_repeat('x', 400000)."\nend");
        self::assertSame(['end', null], iterator_to_array((new ReverseLineReader)->lines($this->path)));
    }
}
