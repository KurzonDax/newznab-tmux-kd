<?php

declare(strict_types=1);

namespace Tests\Unit\Extensions;

use Tests\TestCase;

class HelperCoverUrlTest extends TestCase
{
    public function test_unzip_gzip_file_returns_uncompressed_contents(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'nntmux-gzip-');
        $this->assertIsString($path);

        file_put_contents($path, gzencode('gzip fixture contents'));

        try {
            $this->assertSame('gzip fixture contents', unzipGzipFile($path));
        } finally {
            @unlink($path);
        }
    }
}
