<?php

declare(strict_types=1);

namespace App\Enums;

enum ClipGenerationDeclineReason: string
{
    case PolicyDisabled = 'clip_policy_disabled';
    case DiskGuarded = 'clip_disk_guarded';
    case BelowDurationFloor = 'clip_below_duration_floor';
    case StoreFailed = 'clip_store_failed';
    case ProbeFailed = 'clip_probe_failed';
    case UnsafeVideoCodec = 'clip_unsafe_video_codec';
    case UnsafeAudioCodec = 'clip_unsafe_audio_codec';
    case RemuxFailed = 'clip_remux_failed';
    case EmptyOutput = 'clip_empty_output';
}
