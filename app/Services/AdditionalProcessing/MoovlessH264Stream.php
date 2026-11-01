<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing;

/**
 * The H.264 keyframes and decoder settings readable from an MP4 head with no
 * moov: mdat is walked as length-prefixed NAL units, skipping the audio chunks
 * between them, and the settings come from mdat itself or from the options text
 * x264 writes in its first SEI. Works only on the bytes given and runs no
 * processes; {@see VideoFrameExtractor::extractMoovlessFrame()} does the decoding.
 */
final readonly class MoovlessH264Stream
{
    /** The head holds the first 16 MB of the file; the largest measured was 11.5 MB. */
    public const int HEAD_BYTES = 16 * 1_048_576;

    private const int NAL_SLICE = 1;

    private const int NAL_KEYFRAME_SLICE = 5;

    private const int NAL_SEI = 6;

    private const int NAL_SPS = 7;

    private const int NAL_PPS = 8;

    private const int NAL_DELIMITER = 9;

    private const int MAX_UNIT_BYTES = 8_000_000;

    private const int MAX_SLICE_HEAD_BYTES = 4_000_000;

    private const int RESYNC_WINDOW_BYTES = 4_000_000;

    private const int CHAIN_UNITS = 3;

    private const int MAX_KEYFRAME_GROUPS = 30;

    private const int TEXT_WINDOW_BYTES = 1_048_576;

    private const string START_CODE = "\x00\x00\x00\x01";

    /** Text values that describe a stream the rebuilt settings cannot decode. */
    private const array REFUSED_UNLESS_ZERO = ['interlaced', 'cqm', 'fake_interlaced'];

    /**
     * Range limits for untrusted text: the shift loops below would not end on a
     * huge bframes.
     *
     * @var array<string, array{int, int}>
     */
    private const array VALID_RANGES = [
        'bframes' => [0, 16],
        'ref' => [1, 16],
        'b_pyramid' => [0, 2],
        'chroma_qp_offset' => [-12, 12],
        'crf' => [0, 51],
        'qp' => [0, 51],
    ];

    /** Luma rows cropped from the bottom, by macroblock rows: 1080, 540 and 360 high. */
    private const array BOTTOM_CROP_BY_ROWS = [68 => 8, 34 => 4, 23 => 8];

    /**
     * @param  list<array{type: int, offset: int, length: int}>  $units
     * @param  list<string>  $keyframeGroups
     * @param  array<string, string>|null  $options  x264 text tokens; null on the in-band route
     */
    private function __construct(
        private string $head,
        private array $units,
        private array $keyframeGroups,
        private ?array $options,
    ) {}

    /**
     * Null when the head has no mdat before a moov, no keyframe, or no route to
     * decoder settings.
     */
    public static function fromHead(string $head): ?self
    {
        $payloadOffset = (new Mp4MoovSplicer)->mdatPayloadOffset($head);
        if ($payloadOffset === null) {
            return null;
        }

        $units = self::walk($head, $payloadOffset);
        $groups = self::keyframeGroupsOf($head, $units);
        if ($groups === []) {
            return null;
        }

        $unitTypes = array_column($units, 'type');
        if (in_array(self::NAL_SPS, $unitTypes, true) && in_array(self::NAL_PPS, $unitTypes, true)) {
            return new self($head, $units, $groups, null);
        }

        $options = self::encoderOptions($head, $payloadOffset);

        return $options === null ? null : new self($head, $units, $groups, $options);
    }

    /**
     * True on the x264 text route, where the frame size must still be found.
     */
    public function usesEncoderText(): bool
    {
        return $this->options !== null;
    }

    public function usesTwoPassRateControl(): bool
    {
        return ($this->options['rc'] ?? '') === '2pass';
    }

    /**
     * Every distinct type 7 and 8 unit, in order, as Annex-B.
     */
    public function inBandSettings(): string
    {
        $settings = [];
        foreach ($this->units as $unit) {
            if (self::isSettingsUnit($unit)) {
                $settings[] = self::annexB($this->head, $unit);
            }
        }

        return implode('', array_unique($settings));
    }

    /**
     * Each keyframe group as Annex-B, first to last.
     *
     * @return list<string>
     */
    public function keyframeGroups(): array
    {
        return $this->keyframeGroups;
    }

    /**
     * Every slice in order as Annex-B. The in-band route keeps its settings in
     * place, so it needs nothing written before this.
     */
    public function sliceStream(): string
    {
        $stream = '';
        foreach ($this->units as $unit) {
            if ($unit['type'] === self::NAL_SLICE || $unit['type'] === self::NAL_KEYFRAME_SLICE
                || ($this->options === null && self::isSettingsUnit($unit))
            ) {
                $stream .= self::annexB($this->head, $unit);
            }
        }

        return $stream;
    }

    public function picInitQp(): int
    {
        if ($this->number('stitchable') !== 0.0) {
            return 26;
        }

        return match ($this->options['rc'] ?? '') {
            'cqp' => (int) $this->number('qp'),
            'crf' => (int) $this->number('crf'),
            default => 26,
        };
    }

    /**
     * SPS and PPS rebuilt from the x264 text, as Annex-B.
     */
    public function rebuiltSettings(int $width, int $rows, ?int $picInitQp = null): string
    {
        return self::START_CODE.self::rbspUnit(0x67, $this->spsBits($width, $rows))
            .self::START_CODE.self::rbspUnit(0x68, $this->ppsBits($picInitQp ?? $this->picInitQp()));
    }

    private function spsBits(int $width, int $rows): string
    {
        $pyramid = (int) $this->number('b_pyramid');
        $bframes = (int) $this->number('bframes');
        $pyramidFactor = ($pyramid !== 0 ? 1 : 0) + 1;

        $reorder = $pyramid !== 0 ? 2 : ($bframes !== 0 ? 1 : 0);
        $dpb = min(16, max((int) $this->number('ref'), 1 + $reorder, $pyramid !== 0 ? 4 : 1));
        $numRef = $dpb - ($pyramid === 1 ? 1 : 0);

        $log2FrameNum = 4;
        while ((1 << $log2FrameNum) <= ($dpb * $pyramidFactor) + 1) {
            $log2FrameNum++;
        }

        $bits = self::u(8, 100).self::u(8, 0).self::u(8, 51)
            .self::ue(0).self::ue(1).self::ue(0).self::ue(0)
            .self::u(1, 0).self::u(1, 0)
            .self::ue($log2FrameNum - 4);

        if ($bframes !== 0) {
            $log2PocLsb = 4;
            while ((1 << $log2PocLsb) <= ($bframes + 2) * $pyramidFactor * 2 * 2) {
                $log2PocLsb++;
            }
            $bits .= self::ue(0).self::ue($log2PocLsb - 4);
        } else {
            $bits .= self::ue(2);
        }

        $macroblockWidth = intdiv($width + 15, 16);
        $bits .= self::ue($numRef).self::u(1, 0)
            .self::ue($macroblockWidth - 1).self::ue($rows - 1)
            .self::u(1, 1).self::u(1, 1);

        $rightCrop = ($macroblockWidth * 16) - $width;
        $bottomCrop = self::BOTTOM_CROP_BY_ROWS[$rows] ?? 0;
        if ($rightCrop !== 0 || $bottomCrop !== 0) {
            $bits .= self::u(1, 1).self::ue(0).self::ue(intdiv($rightCrop, 2)).self::ue(0).self::ue(intdiv($bottomCrop, 2));
        } else {
            $bits .= self::u(1, 0);
        }

        return $bits.self::u(1, 0);
    }

    private function ppsBits(int $picInitQp): string
    {
        $chromaQpOffset = (int) $this->number('chroma_qp_offset');

        $bits = self::ue(0).self::ue(0)
            .self::u(1, $this->number('cabac') !== 0.0 ? 1 : 0).self::u(1, 0)
            .self::ue(0)
            .self::ue((int) $this->number('ref') - 1).self::ue(0)
            .self::u(1, $this->number('weightp') > 0 ? 1 : 0)
            .self::u(2, $this->number('weightb') !== 0.0 ? 2 : 0)
            .self::se($picInitQp - 26).self::se(0).self::se($chromaQpOffset)
            .self::u(1, 1)
            .self::u(1, $this->number('constrained_intra') !== 0.0 ? 1 : 0)
            .self::u(1, 0);

        if ($this->number('8x8dct') !== 0.0) {
            $bits .= self::u(1, 1).self::u(1, 0).self::se($chromaQpOffset);
        }

        return $bits;
    }

    /**
     * An x264 option value, read through a float and truncated. It stays a float
     * so a value past any integer can be compared rather than cast.
     */
    private function number(string $key): float
    {
        return self::truncated($this->options ?? [], $key);
    }

    /**
     * Absent keys are 0, except ref, which is 1.
     *
     * @param  array<string, string>  $options
     */
    private static function truncated(array $options, string $key): float
    {
        $value = (float) ($options[$key] ?? ($key === 'ref' ? 1 : 0));

        return $value < 0 ? ceil($value) : floor($value);
    }

    /**
     * @return array<string, string>|null
     */
    private static function encoderOptions(string $head, int $payloadOffset): ?array
    {
        $window = substr($head, $payloadOffset, self::TEXT_WINDOW_BYTES);
        if (preg_match('/x264 - core \d+.{0,300}?options: ([^\x00]{50,3000})/s', $window, $matches) !== 1) {
            return null;
        }

        $options = [];
        foreach (explode(' ', $matches[1]) as $token) {
            $parts = explode('=', $token, 2);
            if (count($parts) === 2) {
                $options[$parts[0]] = $parts[1];
            }
        }

        foreach (self::REFUSED_UNLESS_ZERO as $key) {
            if (($options[$key] ?? '0') !== '0') {
                return null;
            }
        }

        foreach (self::VALID_RANGES as $key => [$minimum, $maximum]) {
            $value = self::truncated($options, $key);
            if ($value < $minimum || $value > $maximum) {
                return null;
            }
        }

        return $options;
    }

    /**
     * @return list<array{type: int, offset: int, length: int}>
     */
    private static function walk(string $head, int $offset): array
    {
        $units = [];
        $size = strlen($head);
        $inSync = false;

        while ($offset + 5 <= $size) {
            $length = self::plausibleLength($head, $offset);
            if ($length === null || ! ($inSync || self::startsChain($head, $offset) || self::isSliceHead($head, $offset))) {
                $inSync = false;
                $offset = self::nextSyncPoint($head, $offset);
                if ($offset === null) {
                    break;
                }

                continue;
            }

            if ($offset + 4 + $length > $size) {
                if ($inSync && $size - $offset < self::RESYNC_WINDOW_BYTES) {
                    break;
                }
                $inSync = false;
                $offset++;

                continue;
            }

            $units[] = ['type' => ord($head[$offset + 4]) & 0x1F, 'offset' => $offset + 4, 'length' => $length];
            $offset += 4 + $length;
            $inSync = true;
        }

        return $units;
    }

    /**
     * The unit length when the bytes at $offset look like a NAL unit. A length of
     * at most 8,000,000 always starts with a zero byte, which lets the resync
     * scan jump between zero bytes.
     */
    private static function plausibleLength(string $head, int $offset): ?int
    {
        if ($offset + 5 > strlen($head) || $head[$offset] !== "\x00") {
            return null;
        }

        $length = (ord($head[$offset + 1]) << 16) | (ord($head[$offset + 2]) << 8) | ord($head[$offset + 3]);
        if ($length < 1 || $length > self::MAX_UNIT_BYTES) {
            return null;
        }

        $header = ord($head[$offset + 4]);
        if (($header & 0x80) !== 0) {
            return null;
        }

        $refIdc = $header & 0x60;

        return match ($header & 0x1F) {
            self::NAL_SLICE, self::NAL_SPS, self::NAL_PPS => $length,
            self::NAL_KEYFRAME_SLICE => $refIdc !== 0 ? $length : null,
            self::NAL_SEI, self::NAL_DELIMITER => $refIdc === 0 ? $length : null,
            default => null,
        };
    }

    /**
     * Plausible here and at the next units, or the walk runs past the head first.
     */
    private static function startsChain(string $head, int $offset): bool
    {
        for ($unit = 0; $unit < self::CHAIN_UNITS; $unit++) {
            if ($unit > 0 && $offset + 5 > strlen($head)) {
                return true;
            }

            $length = self::plausibleLength($head, $offset);
            if ($length === null) {
                return false;
            }
            $offset += 4 + $length;
        }

        return true;
    }

    /**
     * A slice whose first_mb_in_slice is 0, which a single plausible unit can
     * prove on its own when nothing around it does.
     */
    private static function isSliceHead(string $head, int $offset): bool
    {
        $length = self::plausibleLength($head, $offset);
        if ($length === null || $length < 16 || $length > self::MAX_SLICE_HEAD_BYTES) {
            return false;
        }

        $type = ord($head[$offset + 4]) & 0x1F;

        return ($type === self::NAL_SLICE || $type === self::NAL_KEYFRAME_SLICE)
            && self::startsWithFirstMacroblock($head, $offset + 4);
    }

    private static function nextSyncPoint(string $head, int $offset): ?int
    {
        $last = $offset + self::RESYNC_WINDOW_BYTES;
        $candidate = $offset + 1;

        while (($candidate = strpos($head, "\x00", $candidate)) !== false && $candidate <= $last) {
            if (self::startsChain($head, $candidate) || self::isSliceHead($head, $candidate)) {
                return $candidate;
            }
            $candidate++;
        }

        return null;
    }

    /**
     * @param  list<array{type: int, offset: int, length: int}>  $units
     * @return list<string>
     */
    private static function keyframeGroupsOf(string $head, array $units): array
    {
        $groups = [];
        $open = false;

        foreach ($units as $unit) {
            if ($unit['type'] === self::NAL_SLICE) {
                $open = false;
            }
            if ($unit['type'] !== self::NAL_KEYFRAME_SLICE) {
                continue;
            }

            if (! $open || self::startsWithFirstMacroblock($head, $unit['offset'])) {
                if (count($groups) === self::MAX_KEYFRAME_GROUPS) {
                    break;
                }
                $groups[] = '';
            }

            $groups[array_key_last($groups)] .= self::annexB($head, $unit);
            $open = true;
        }

        return $groups;
    }

    /**
     * The byte after the type byte has its top bit set: first_mb_in_slice is 0.
     */
    private static function startsWithFirstMacroblock(string $head, int $headerOffset): bool
    {
        return isset($head[$headerOffset + 1]) && (ord($head[$headerOffset + 1]) & 0x80) !== 0;
    }

    /**
     * @param  array{type: int, offset: int, length: int}  $unit
     */
    private static function annexB(string $head, array $unit): string
    {
        return self::START_CODE.substr($head, $unit['offset'], $unit['length']);
    }

    /**
     * @param  array{type: int, offset: int, length: int}  $unit
     */
    private static function isSettingsUnit(array $unit): bool
    {
        return $unit['type'] === self::NAL_SPS || $unit['type'] === self::NAL_PPS;
    }

    private static function u(int $width, int $value): string
    {
        return str_pad(decbin($value), $width, '0', STR_PAD_LEFT);
    }

    private static function ue(int $value): string
    {
        $code = decbin($value + 1);

        return str_repeat('0', strlen($code) - 1).$code;
    }

    private static function se(int $value): string
    {
        return self::ue($value > 0 ? (2 * $value) - 1 : -2 * $value);
    }

    /**
     * Adds RBSP trailing bits and emulation prevention to a unit's bit string.
     */
    private static function rbspUnit(int $header, string $bits): string
    {
        $bits .= '1';
        $bits = str_pad($bits, (int) ceil(strlen($bits) / 8) * 8, '0');

        $rbsp = '';
        foreach (str_split($bits, 8) as $byte) {
            $rbsp .= chr((int) bindec($byte));
        }

        return chr($header).(string) preg_replace('/\x00\x00(?=[\x00-\x03])/', "\x00\x00\x03", $rbsp);
    }
}
