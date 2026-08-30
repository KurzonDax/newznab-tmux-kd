<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\ClipHardware;

final class QsvClipHardwareEncoder implements ClipHardwareEncoder
{
    public function id(): string
    {
        return 'qsv';
    }

    public function build(string $device): ClipHardwareCommandArguments
    {
        return new ClipHardwareCommandArguments(
            inputArguments: [
                '-init_hw_device',
                'qsv=clip_hw:hw,child_device='.$device,
                '-filter_hw_device',
                'clip_hw',
            ],
            outputArguments: [
                '-vf',
                'format=nv12,hwupload=extra_hw_frames=64',
                '-c:v',
                'h264_qsv',
                '-preset',
                'veryfast',
                '-global_quality',
                '23',
            ],
        );
    }
}
