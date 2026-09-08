<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Par2Sidecar\SidecarLinkResolver;
use PHPUnit\Framework\TestCase;

class SidecarLinkResolverTest extends TestCase
{
    public function test_exact_prefix_and_raw_size_name_and_combine_a_complete_pure_sidecar(): void
    {
        $evidence = $this->evidence();
        $descriptor = $this->descriptor();
        $decision = (new SidecarLinkResolver)->resolve([$evidence], [$descriptor], [2 => [
            'pure' => true, 'complete' => true, 'fingerprint' => 'source',
            'descriptors' => [$descriptor], 'owners' => [1],
        ]], $this->eligibility());
        self::assertSame('Example.Title.2014.1080p.WEB-DL.H.264-GRP.mkv', $decision->filename);
        self::assertSame(2, $decision->sourceId);
        self::assertTrue($decision->combine);
    }

    public function test_invalid_geometry_and_changed_inventories_never_name(): void
    {
        foreach ([['raw_size' => 1999999], ['raw_size' => 0], ['prefix_hash' => str_repeat('0', 32)],
            ['decoded_length' => 16383], ['segment_number' => 2], ['segment_offset' => 1],
            ['segment_numbers' => [1, 1, 3]], ['segment_numbers' => [2, 1, 3]],
            ['segment_numbers' => [1, 0, 3]], ['fingerprint' => 'changed']] as $change) {
            $result = (new SidecarLinkResolver)->resolve([$this->evidence($change)], [$this->descriptor()], [], $this->eligibility());
            self::assertNull($result->filename, json_encode($change));
        }
    }

    public function test_missing_segments_allow_naming_but_incomplete_or_mixed_donors_cannot_combine(): void
    {
        $result = (new SidecarLinkResolver)->resolve([$this->evidence(['segment_numbers' => [1, 3], 'observed_segments' => 2])],
            [$this->descriptor()], [], $this->eligibility());
        self::assertNotNull($result->filename);
        self::assertFalse($result->combine);
    }

    public function test_conflicting_names_or_known_full_hashes_and_overflow_decline(): void
    {
        foreach ([[$this->descriptor(), $this->descriptor(['filename' => 'Different.Movie.mkv'])],
            [$this->descriptor(), $this->descriptor(['full_hash' => str_repeat('d', 32)])],
            array_fill(0, 33, $this->descriptor())] as $descriptors) {
            self::assertNull((new SidecarLinkResolver)->resolve([$this->evidence()], $descriptors, [], $this->eligibility())->filename);
        }
    }

    public function test_equivalent_reposts_choose_earliest_then_lowest_id(): void
    {
        $eligibility = $this->eligibility();
        $eligibility[3] = array_replace($eligibility[2], ['postdate' => '2026-09-01']);
        $descriptors = [$this->descriptor(), $this->descriptor(['releases_id' => 3])];
        self::assertSame(3, (new SidecarLinkResolver)->resolve([$this->evidence()], $descriptors, [], $eligibility)->sourceId);
        $eligibility[2]['postdate'] = '2026-09-01';
        self::assertSame(2, (new SidecarLinkResolver)->resolve([$this->evidence()], array_reverse($descriptors), [], $eligibility)->sourceId);
    }

    public function test_single_segment_uses_entire_short_file_and_requires_its_exact_length(): void
    {
        $prefix = $this->evidence(['raw_size' => 10000, 'decoded_length' => 10000,
            'declared_segments' => 1, 'observed_segments' => 1, 'segment_numbers' => [1]]);
        $descriptor = $this->descriptor(['raw_size' => 10000]);
        self::assertNotNull((new SidecarLinkResolver)->resolve([$prefix], [$descriptor], [], $this->eligibility())->filename);
        $prefix['decoded_length'] = 9999;
        self::assertNull((new SidecarLinkResolver)->resolve([$prefix], [$descriptor], [], $this->eligibility())->filename);
    }

    public function test_whole_donor_confidence_is_separate_from_compatible_naming(): void
    {
        $inventory = ['pure' => true, 'complete' => true, 'fingerprint' => 'source', 'descriptors' => [$this->descriptor()], 'owners' => [1]];
        foreach ([['pure' => false], ['complete' => false], ['owners' => [1, 3]], ['owners' => []],
            ['descriptors' => [$this->descriptor(), $this->descriptor(['raw_size' => 99])]]] as $change) {
            $result = (new SidecarLinkResolver)->resolve([$this->evidence()], [$this->descriptor()],
                [2 => array_replace($inventory, $change)], $this->eligibility());
            self::assertNotNull($result->filename);
            self::assertFalse($result->combine);
        }
        foreach ([1, 2] as $id) {
            foreach (['nzb_complete' => false, 'completion' => 99] as $key => $value) {
                $eligible = $this->eligibility();
                $eligible[$id][$key] = $value;
                $result = (new SidecarLinkResolver)->resolve([$this->evidence()], [$this->descriptor()], [2 => $inventory], $eligible);
                self::assertNotNull($result->filename);
                self::assertFalse($result->combine);
            }
            $eligible[$id]['eligible'] = false;
            self::assertNull((new SidecarLinkResolver)->resolve([$this->evidence()], [$this->descriptor()], [2 => $inventory], $eligible)->filename);
        }
        self::assertNull((new SidecarLinkResolver)->resolve([$this->evidence()], [$this->descriptor(['naming_ambiguous' => true])], [], $this->eligibility())->filename);
        self::assertNull((new SidecarLinkResolver)->resolve([$this->evidence()], [$this->descriptor(['fingerprint' => 'stale'])], [], $this->eligibility())->filename);
    }

    private function evidence(array $changes = []): array
    {
        return array_replace([
            'releases_id' => 1, 'nzb_file_index' => 0, 'prefix_hash' => 'aabbccddeeff00112233445566778899',
            'raw_size' => 2000000, 'decoded_length' => 768000, 'segment_number' => 1,
            'segment_offset' => 0, 'observed_segments' => 3, 'declared_segments' => 3,
            'segment_numbers' => [1, 2, 3], 'fingerprint' => 'target',
        ], $changes);
    }

    private function descriptor(array $changes = []): array
    {
        return array_replace([
            'releases_id' => 2, 'set_id' => str_repeat('a', 32), 'file_id' => str_repeat('b', 32),
            'hash16k' => 'aabbccddeeff00112233445566778899', 'raw_size' => 2000000,
            'fingerprint' => 'source', 'full_hash' => str_repeat('c', 32), 'filename' => 'Example.Title.2014.1080p.WEB-DL.H.264-GRP.mkv',
        ], $changes);
    }

    private function eligibility(): array
    {
        return [
            1 => ['eligible' => true, 'completion' => 100, 'postdate' => '2026-09-01', 'fingerprint' => 'target', 'nzb_complete' => true],
            2 => ['eligible' => true, 'completion' => 100, 'postdate' => '2026-09-02', 'fingerprint' => 'source', 'nzb_complete' => true],
        ];
    }
}
