<?php

declare(strict_types=1);

namespace Tests\Unit\AdditionalProcessing;

use App\Services\AdditionalProcessing\Enums\Mp4MoovSpliceStatus;
use App\Services\AdditionalProcessing\Mp4MoovSplicer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class Mp4MoovSplicerTest extends TestCase
{
    #[Test]
    public function it_splices_a_valid_tail_moov_onto_an_mdat_first_head(): void
    {
        $head = $this->atom('ftyp', 'isom0000')
            .$this->atom('free', 'padding')
            .pack('N', 4096).'mdat'.'downloaded-video-bytes';
        $moov = $this->validMoov();

        $result = (new Mp4MoovSplicer)->splice($head, 'tail-prefix'.$moov.'trailing-data');

        $this->assertSame(Mp4MoovSpliceStatus::Spliced, $result->status);
        $this->assertNotNull($result->data);
        $this->assertSame(['ftyp', 'free', 'mdat', 'moov'], $this->atomTypes($result->data));

        $mdatOffset = strlen($this->atom('ftyp', 'isom0000').$this->atom('free', 'padding'));
        $this->assertSame(strlen($head) - $mdatOffset, $this->uint32($result->data, $mdatOffset));
        $this->assertStringEndsWith($moov, $result->data);
    }

    #[Test]
    public function it_rejects_a_spurious_moov_fourcc_without_an_mvhd_first_child(): void
    {
        $head = $this->atom('ftyp', 'isom0000').pack('N', 2048).'mdat'.'video';
        $spurious = $this->atom('moov', $this->atom('junk', 'not-movie-metadata'));

        $result = (new Mp4MoovSplicer)->splice($head, $this->atom('mdat', 'noise'.$spurious), true);

        $this->assertSame(Mp4MoovSpliceStatus::Missing, $result->status);
        $this->assertNull($result->data);
    }

    #[Test]
    public function it_rejects_a_zero_sized_tail_moov_candidate(): void
    {
        $head = $this->atom('ftyp', 'isom0000').pack('N', 2048).'mdat'.'video';
        $spurious = pack('N', 0).'moov'.$this->atom('mvhd', 'plausible-header');

        $result = (new Mp4MoovSplicer)->splice($head, 'media-noise'.$spurious, true);

        $this->assertSame(Mp4MoovSpliceStatus::Missing, $result->status);
        $this->assertNull($result->data);
    }

    #[Test]
    public function faststart_mp4_does_not_need_a_tail(): void
    {
        $head = $this->atom('ftyp', 'isom0000')
            .$this->validMoov()
            .pack('N', 2048).'mdat'.'video';

        $this->assertFalse((new Mp4MoovSplicer)->needsTail($head));
    }

    #[Test]
    public function it_rewrites_a_64_bit_mdat_size_without_changing_its_header_form(): void
    {
        $ftyp = $this->atom('ftyp', 'isom0000');
        $head = $ftyp.pack('N', 1).'mdat'.pack('N2', 0, 4096).'downloaded-video-bytes';

        $result = (new Mp4MoovSplicer)->splice($head, $this->validMoov(), true);

        $this->assertSame(Mp4MoovSpliceStatus::Spliced, $result->status);
        $this->assertNotNull($result->data);
        $this->assertSame(1, $this->uint32($result->data, strlen($ftyp)));
        $this->assertSame(strlen($head) - strlen($ftyp), $this->uint64($result->data, strlen($ftyp) + 8));
    }

    #[Test]
    public function it_requests_more_tail_until_the_segment_cap_then_gives_up_cleanly(): void
    {
        $head = $this->atom('ftyp', 'isom0000').pack('N', 2048).'mdat'.'video';
        $tailBeginningInsideMoov = substr($this->validMoov(), 12);
        $splicer = new Mp4MoovSplicer;

        $this->assertSame(
            Mp4MoovSpliceStatus::NeedMore,
            $splicer->splice($head, $tailBeginningInsideMoov)->status,
        );
        $this->assertSame(
            Mp4MoovSpliceStatus::Missing,
            $splicer->splice($head, $tailBeginningInsideMoov, true)->status,
        );
    }

    private function validMoov(): string
    {
        return $this->atom('moov', $this->atom('mvhd', 'movie-header').$this->atom('trak', 'track'));
    }

    private function atom(string $type, string $payload): string
    {
        return pack('N', strlen($payload) + 8).$type.$payload;
    }

    /**
     * @return list<string>
     */
    private function atomTypes(string $data): array
    {
        $types = [];
        $offset = 0;

        while ($offset + 8 <= strlen($data)) {
            $size = $this->uint32($data, $offset);
            $types[] = substr($data, $offset + 4, 4);
            if ($size < 8 || $offset + $size > strlen($data)) {
                break;
            }
            $offset += $size;
        }

        return $types;
    }

    private function uint32(string $data, int $offset): int
    {
        $unpacked = unpack('Nvalue', substr($data, $offset, 4));

        return (int) ($unpacked['value'] ?? 0);
    }

    private function uint64(string $data, int $offset): int
    {
        $unpacked = unpack('Nhigh/Nlow', substr($data, $offset, 8));

        return ((int) ($unpacked['high'] ?? 0) * 4_294_967_296) + (int) ($unpacked['low'] ?? 0);
    }
}
