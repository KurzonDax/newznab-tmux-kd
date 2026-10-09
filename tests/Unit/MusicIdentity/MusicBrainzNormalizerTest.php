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

    public function test_a_credited_artist_keeps_its_id_canonical_name_and_only_artist_name_and_search_hint_aliases(): void
    {
        $credit = [[
            'name' => 'Example Artist',
            'joinphrase' => '',
            'artist' => [
                'id' => '99999999-9999-4999-8999-999999999999',
                'name' => 'Canonical Example Band',
                'aliases' => [
                    ['name' => 'Altname Ensemble', 'type' => 'Artist name'],
                    ['name' => 'Hintword Band', 'type' => 'Search hint'],
                    ['name' => 'CANONICAL EXAMPLE BAND', 'type' => 'Artist name'],
                    ['name' => 'Hintwórd Band', 'type' => 'Search hint'],
                    ['name' => 'Legalname Person', 'type' => 'Legal name'],
                    ['name' => 'Untypedname Group', 'type' => null],
                ],
            ],
        ]];
        $expected = [[
            'artistId' => '99999999-9999-4999-8999-999999999999',
            'name' => 'Canonical Example Band',
            'aliases' => [['name' => 'Altname Ensemble', 'type' => 'artist_name'], ['name' => 'Hintword Band', 'type' => 'search_hint']],
        ]];
        $normalizer = new MusicBrainzNormalizer;

        $release = $normalizer->release(['id' => '11111111-1111-4111-8111-111111111111', 'title' => 'Example Album', 'artist-credit' => $credit]);
        $group = $normalizer->releaseGroup(['id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'title' => 'Example Album', 'artist-credit' => $credit]);

        $this->assertSame($expected, $release['artists'] ?? null);
        $this->assertSame($expected, $group['artists'] ?? null);
        $this->assertSame('Example Artist', $release['artistCredit'], 'the credit string does not change');
        $this->assertSame([], $normalizer->releaseGroup(['id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'title' => 'Example Album'])['artists'] ?? null);
    }

    public function test_a_listed_alias_without_a_usable_name_is_invalid(): void
    {
        $this->expectException(InvalidMusicBrainzResponse::class);

        (new MusicBrainzNormalizer)->releaseGroup(['id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'title' => 'Example Album', 'artist-credit' => [[
            'name' => 'Example Artist',
            'artist' => ['id' => '99999999-9999-4999-8999-999999999999', 'name' => 'Example Artist', 'aliases' => [['name' => ' ', 'type' => 'Search hint']]],
        ]]]);
    }
}
