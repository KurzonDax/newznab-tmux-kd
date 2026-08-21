<?php

declare(strict_types=1);

namespace Tests\Unit\AdditionalProcessing;

use App\Services\AdditionalProcessing\AudioTagExtractor;
use Mhor\MediaInfo\Attribute\Mode;
use Mhor\MediaInfo\Container\MediaInfoContainer;
use Mhor\MediaInfo\Type\Audio;
use Mhor\MediaInfo\Type\General;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AudioTagExtractorTest extends TestCase
{
    #[Test]
    public function it_reads_tags_from_the_general_track(): void
    {
        $tags = (new AudioTagExtractor)->extract($this->container([
            'format' => new Mode('MPEG Audio', 'MPEG Audio'),
            'album' => 'Test Album',
            'album_performer' => 'Album Artist',
            'performer' => 'Test Artist',
            'track_name' => 'Track One',
            'track_name_position' => '3',
            'track_name_total' => '12',
            'genre' => 'Electronic',
            'recorded_date' => '2019-04-01',
        ]), 'audiofile.MP3');

        $this->assertNotNull($tags);
        $this->assertSame('Test Album', $tags['album']);
        $this->assertSame('Album Artist', $tags['album_performer']);
        $this->assertSame('Test Artist', $tags['performer']);
        $this->assertSame('Track One', $tags['track_name']);
        $this->assertSame(3, $tags['track_position']);
        $this->assertSame(12, $tags['track_position_total']);
        $this->assertSame('Electronic', $tags['genre']);
        $this->assertSame('2019-04-01', $tags['recorded_date']);
        $this->assertSame(2019, $tags['recorded_year']);
        $this->assertSame('MPEG Audio', $tags['audio_format']);
        $this->assertSame('audiofile.MP3', $tags['source_file']);
    }

    #[Test]
    public function tags_carried_only_on_audio_tracks_are_never_read(): void
    {
        $container = $this->container([]);
        $audio = new Audio;
        $audio->set('album', 'Test Album');
        $audio->set('performer', 'Test Artist');
        $container->add($audio);

        $this->assertNull((new AudioTagExtractor)->extract($container, 'audiofile.MP3'));
    }

    #[Test]
    public function an_untagged_file_yields_nothing(): void
    {
        $tags = (new AudioTagExtractor)->extract($this->container([
            'format' => new Mode('FLAC', 'FLAC'),
            'file_size' => '4096',
        ]), 'audiofile.FLAC');

        $this->assertNull($tags);
    }

    #[Test]
    public function a_performer_alone_is_enough_to_record(): void
    {
        $tags = (new AudioTagExtractor)->extract($this->container([
            'performer' => 'Test Artist',
        ]), 'audiofile.MP3');

        $this->assertNotNull($tags);
        $this->assertSame('Test Artist', $tags['performer']);
        $this->assertNull($tags['album']);
    }

    /**
     * @return list<array{0: string, 1: int|null}>
     */
    public static function recordedDates(): array
    {
        return [
            ['2019', 2019],
            ['2019-04-01', 2019],
            ['2019-04-01 00:00:00 UTC', 2019],
            ['UTC 2019-04-01', 2019],
            ['not a date', null],
        ];
    }

    #[Test]
    #[DataProvider('recordedDates')]
    public function the_recorded_year_is_derived_from_whatever_shape_the_date_takes(string $recordedDate, ?int $expected): void
    {
        $tags = (new AudioTagExtractor)->extract($this->container([
            'album' => 'Test Album',
            'recorded_date' => $recordedDate,
        ]), 'audiofile.MP3');

        $this->assertNotNull($tags);
        $this->assertSame($recordedDate, $tags['recorded_date']);
        $this->assertSame($expected, $tags['recorded_year']);
    }

    /**
     * @return list<array{0: string}>
     */
    public static function musicBrainzAlbumKeys(): array
    {
        return [['MusicBrainz_Album_Id'], ['MUSICBRAINZ_ALBUMID'], ['musicbrainz album id'], ['musicbrainz_album_id']];
    }

    #[Test]
    #[DataProvider('musicBrainzAlbumKeys')]
    public function musicbrainz_identifiers_are_recognised_whatever_the_tagger_called_them(string $key): void
    {
        $tags = (new AudioTagExtractor)->extract($this->container([
            'album' => 'Test Album',
            $key => 'd1b2c3d4-0000-4000-8000-abcdefabcdef',
        ]), 'audiofile.MP3');

        $this->assertNotNull($tags);
        $this->assertSame('d1b2c3d4-0000-4000-8000-abcdefabcdef', $tags['musicbrainz_album_id']);
    }

    #[Test]
    public function every_musicbrainz_column_is_filled_from_its_aliases(): void
    {
        $tags = (new AudioTagExtractor)->extract($this->container([
            'album' => 'Test Album',
            'MUSICBRAINZ_ARTISTID' => '11111111-1111-4111-8111-111111111111',
            'MUSICBRAINZ_TRACKID' => '22222222-2222-4222-8222-222222222222',
            'musicbrainz_release_group_id' => '33333333-3333-4333-8333-333333333333',
            'MusicBrainz Album Id' => '44444444-4444-4444-8444-444444444444',
        ]), 'audiofile.MP3');

        $this->assertNotNull($tags);
        $this->assertSame('11111111-1111-4111-8111-111111111111', $tags['musicbrainz_artist_id']);
        $this->assertSame('22222222-2222-4222-8222-222222222222', $tags['musicbrainz_track_id']);
        $this->assertSame('33333333-3333-4333-8333-333333333333', $tags['musicbrainz_release_group_id']);
        $this->assertSame('44444444-4444-4444-8444-444444444444', $tags['musicbrainz_album_id']);
    }

    #[Test]
    public function a_musicbrainz_value_that_is_not_a_uuid_is_rejected_but_still_kept_in_raw_tags(): void
    {
        $tags = (new AudioTagExtractor)->extract($this->container([
            'album' => 'Test Album',
            'MusicBrainz_Album_Id' => 'not-a-uuid',
        ]), 'audiofile.MP3');

        $this->assertNotNull($tags);
        $this->assertNull($tags['musicbrainz_album_id']);
        $this->assertSame('not-a-uuid', $tags['raw_tags']['musicbrainz_album_id']);
    }

    #[Test]
    public function musicbrainz_identifiers_nested_under_an_extra_block_are_still_found(): void
    {
        $tags = (new AudioTagExtractor)->extract($this->container([
            'album' => 'Test Album',
            'extra' => ['MusicBrainz_Album_Id' => '55555555-5555-4555-8555-555555555555'],
        ]), 'audiofile.MP3');

        $this->assertNotNull($tags);
        $this->assertSame('55555555-5555-4555-8555-555555555555', $tags['musicbrainz_album_id']);
    }

    #[Test]
    public function raw_tags_keep_every_other_tag_but_never_a_file_path(): void
    {
        $tags = (new AudioTagExtractor)->extract($this->container([
            'complete_name' => '/tmp/nntmux/1234/audiofile.MP3',
            'folder_name' => '/tmp/nntmux/1234',
            'file_name' => 'audiofile',
            'file_name_extension' => 'audiofile.MP3',
            'file_last_modification_date' => new \DateTime('2019-04-01 12:00:00'),
            'album' => 'Test Album',
            'comment' => 'ripped by someone',
            'format' => new Mode('MPEG Audio', 'MPEG Audio'),
        ]), 'audiofile.MP3');

        $this->assertNotNull($tags);
        $this->assertSame('ripped by someone', $tags['raw_tags']['comment']);
        $this->assertSame('MPEG Audio', $tags['raw_tags']['format']);
        foreach (['complete_name', 'folder_name', 'file_name', 'file_name_extension', 'file_last_modification_date'] as $key) {
            $this->assertArrayNotHasKey($key, $tags['raw_tags']);
        }
    }

    #[Test]
    public function over_long_values_are_truncated_to_their_column_width(): void
    {
        $tags = (new AudioTagExtractor)->extract($this->container([
            'album' => str_repeat('a', 400),
            'genre' => str_repeat('g', 200),
            'recorded_date' => str_repeat('1', 64),
        ]), str_repeat('f', 400).'.MP3');

        $this->assertNotNull($tags);
        $this->assertSame(255, mb_strlen((string) $tags['album']));
        $this->assertSame(100, mb_strlen((string) $tags['genre']));
        $this->assertSame(32, mb_strlen((string) $tags['recorded_date']));
        $this->assertSame(255, mb_strlen((string) $tags['source_file']));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function container(array $attributes): MediaInfoContainer
    {
        $general = new General;
        foreach ($attributes as $key => $value) {
            $general->set($this->formatAttribute($key), $value);
        }

        $container = new MediaInfoContainer;
        $container->setGeneral($general);

        return $container;
    }

    /**
     * Mirror how php-mediainfo names attributes when it builds a track type,
     * so fixtures are keyed exactly the way production data arrives.
     */
    private function formatAttribute(string $attribute): string
    {
        return trim(str_replace('__', '_', str_replace(' ', '_', strtolower($attribute))), '_');
    }
}
