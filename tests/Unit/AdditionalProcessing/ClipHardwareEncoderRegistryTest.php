<?php

declare(strict_types=1);

namespace Tests\Unit\AdditionalProcessing;

use App\Services\AdditionalProcessing\ClipHardware\ClipHardwareEncoderRegistry;
use App\Services\AdditionalProcessing\ClipHardware\QsvClipHardwareEncoder;
use App\Services\AdditionalProcessing\ClipHardware\VaapiClipHardwareEncoder;
use Tests\TestCase;

class ClipHardwareEncoderRegistryTest extends TestCase
{
    public function test_it_resolves_each_registered_backend_by_id(): void
    {
        $registry = new ClipHardwareEncoderRegistry;

        $this->assertInstanceOf(VaapiClipHardwareEncoder::class, $registry->resolve('vaapi'));
        $this->assertInstanceOf(QsvClipHardwareEncoder::class, $registry->resolve('qsv'));
        $this->assertNull($registry->resolve('nvenc'));
    }
}
