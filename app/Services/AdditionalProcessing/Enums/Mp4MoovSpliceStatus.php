<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\Enums;

enum Mp4MoovSpliceStatus: string
{
    case Spliced = 'spliced';
    case NeedMore = 'need-more';
    case Missing = 'missing';
}
