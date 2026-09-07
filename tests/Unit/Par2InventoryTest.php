<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Nzb\Par2Inventory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class Par2InventoryTest extends TestCase
{
    #[DataProvider('deferredInventories')]
    public function test_unknown_or_mixed_inventory_never_proves_par2_only(string $xml, int $total, int $declared, bool $truncate = false): void
    {
        $path = tempnam(sys_get_temp_dir(), 'parity-');
        self::assertNotFalse($path);
        try {
            $gzip = gzencode($xml);
            file_put_contents($path, $truncate ? substr($gzip, 0, -8) : $gzip);
            self::assertFalse((new Par2Inventory)->inspect($path, $total, $declared)['par2_only']);
        } finally {
            unlink($path);
        }
    }

    /** @return array<string, array{string, int, int, bool}> */
    public static function deferredInventories(): array
    {
        return [
            'empty' => ['<nzb/>', 1, 1, false],
            'malformed' => ['<nzb><file subject="a.par2"/>', 1, 1, false],
            'unknown name' => ['<nzb><file subject="a title mentioning .par2"/></nzb>', 1, 1, false],
            'archive suffix' => ['<nzb><file subject="movie.par2.rar"/></nzb>', 1, 1, false],
            'mixed' => ['<nzb><file subject="a.par2"/><file subject="a.nfo"/></nzb>', 2, 2, false],
            'missing declared files' => ['<nzb><file subject="a.par2"/></nzb>', 1, 2, false],
            'stale promoted count' => ['<nzb><file subject="[1/2] &quot;a.par2&quot;"/></nzb>', 1, 1, false],
            'unknown counts' => ['<nzb><file subject="a.par2"/></nzb>', 0, 0, false],
            'truncated gzip' => ['<nzb><file subject="a.par2"/></nzb>', 1, 1, true],
            'entity' => ['<!DOCTYPE nzb [<!ENTITY name "a.par2">]><nzb><file subject="&name;"/></nzb>', 1, 1, false],
            'ambiguous quotes' => ['<nzb><file subject="&quot;a.par2&quot; &quot;b.rar&quot;"/></nzb>', 1, 1, false],
            'duplicate file' => ['<nzb><file subject="a.par2"/><file subject="a.par2"/></nzb>', 2, 2, false],
            'oversize' => ['<nzb><!--'.str_repeat('x', Par2Inventory::MAX_BYTES).'--><file subject="a.par2"/></nzb>', 1, 1, false],
        ];
    }

    public function test_a_complete_parity_inventory_is_positive_evidence(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'parity-');
        self::assertNotFalse($path);
        try {
            file_put_contents($path, gzencode('<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb"><file subject="[1/2] &quot;Example.par2&quot; yEnc"/><file subject="[2/2] &quot;Example.vol001+02.PAR2&quot; yEnc"/></nzb>'));
            $result = (new Par2Inventory)->inspect($path, 2, 2);
            self::assertTrue($result['par2_only']);
            self::assertSame(2, $result['files']);
            self::assertSame(64, strlen($result['digest']));
        } finally {
            unlink($path);
        }
    }

    public function test_the_application_writers_external_doctype_does_not_require_network_resolution(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'parity-');
        self::assertNotFalse($path);
        try {
            file_put_contents($path, gzencode('<?xml version="1.0"?><!DOCTYPE nzb PUBLIC "-//newzBin//DTD NZB 1.1//EN" "http://www.newzbin.com/DTD/nzb/nzb-1.1.dtd"><nzb><file subject="a.par2"/></nzb>'));
            self::assertTrue((new Par2Inventory)->inspect($path, 1, 1)['par2_only']);
        } finally {
            unlink($path);
        }
    }
}
