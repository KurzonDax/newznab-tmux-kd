<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\ClipHardware;

final class VaapiClipHardwareEncoder implements ClipHardwareEncoder
{
    public function id(): string
    {
        return 'vaapi';
    }

    public function build(string $device): ClipHardwareCommandArguments
    {
        return new ClipHardwareCommandArguments(
            inputArguments: [
                '-init_hw_device',
                'vaapi=clip_hw:'.$device,
                '-filter_hw_device',
                'clip_hw',
            ],
            outputArguments: [
                '-vf',
                'format=nv12,hwupload',
                '-c:v',
                'h264_vaapi',
                '-qp',
                '23',
            ],
        );
    }
}
