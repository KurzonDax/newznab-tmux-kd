<?php

declare(strict_types=1);

namespace Tests\Unit\MusicIdentity;

use App\Services\MusicIdentity\Exceptions\InvalidMusicBrainzResponse;
use App\Services\MusicIdentity\Gateways\MusicBrainzNormalizer;
use PHPUnit\Framework\TestCase;

final class MusicBrainzNormalizerTest extends TestCase
{
    public function test_required_count_reports_a_missing_field(): void
    {
        $this->expectException(InvalidMusicBrainzResponse::class);
        $this->expectExceptionMessage('MusicBrainz response missing required field "count".');

        (new MusicBrainzNormalizer)->requiredCount([], 'count');
    }

    public function test_required_count_reports_a_field_with_the_wrong_type(): void
    {
        $this->expectException(InvalidMusicBrainzResponse::class);
        $this->expectExceptionMessage('MusicBrainz response field "count" must be an integer.');

        (new MusicBrainzNormalizer)->requiredCount(['count' => null], 'count');
    }
}
