<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Nzb\Par2Inventory;

/**
 * The phantom-trailing-file fixtures from #939: one complete set that declares a 13th file it never
 * posted, and variants that each break exactly one part of the rule.
 *
 * The base set: the index at ordinal 1, `part1` to `part5` at 2 to 6, and the recovery volumes
 * `vol00+01`, `vol01+02`, `vol03+04`, `vol07+08`, `vol15+16`, `vol31+11` at 7 to 12, every subject
 * declaring 13 and quoting its filename. Subjects are written the way the NZB writer stores them:
 * the binary name, then a ` (1/N)` segment counter.
 */
final class PhantomTrailingSets
{
    public const int DECLARED = 13;

    public const int HELD = 12;

    /** @return array<int, string> Ordinal => filename. */
    public static function baseFiles(): array
    {
        return [
            1 => 'Show.Name.par2',
            2 => 'Show.Name.part1.rar',
            3 => 'Show.Name.part2.rar',
            4 => 'Show.Name.part3.rar',
            5 => 'Show.Name.part4.rar',
            6 => 'Show.Name.part5.rar',
            7 => 'Show.Name.vol00+01.par2',
            8 => 'Show.Name.vol01+02.par2',
            9 => 'Show.Name.vol03+04.par2',
            10 => 'Show.Name.vol07+08.par2',
            11 => 'Show.Name.vol15+16.par2',
            12 => 'Show.Name.vol31+11.par2',
        ];
    }

    /**
     * Binary names (`[n/N] - "file" yEnc`), as `binaries.name` holds them.
     *
     * @param  array<int, string>  $files  Ordinal => filename, in posting order.
     * @return list<string>
     */
    public static function binaryNames(array $files, int $declared = self::DECLARED): array
    {
        $names = [];

        foreach ($files as $ordinal => $filename) {
            $names[] = sprintf('[%d/%d] - "%s" yEnc', $ordinal, $declared, $filename);
        }

        return $names;
    }

    /**
     * Stored NZB subjects: the binary name plus the writer's ` (1/N)` segment counter.
     *
     * @param  array<int, string>  $files  Ordinal => filename, in posting order.
     * @return list<string>
     */
    public static function subjects(array $files, int $declared = self::DECLARED, int $segments = 1): array
    {
        return array_map(
            static fn (string $name): string => $name.' (1/'.$segments.')',
            self::binaryNames($files, $declared),
        );
    }

    /** @return list<string> */
    public static function base(): array
    {
        return self::subjects(self::baseFiles());
    }

    /** The last volume is not a remainder: `vol31+32`. */
    public static function lastVolumeNotARemainder(): array
    {
        return self::subjects(self::withLastVolume('Show.Name.vol31+32.par2'));
    }

    /** The last volume's count equals the previous one: `vol31+16`. Still a remainder. */
    public static function remainderEqualToPrevious(): array
    {
        return self::subjects(self::withLastVolume('Show.Name.vol31+16.par2'));
    }

    /** `part3` (ordinal 4) absent; the volume series is intact. */
    public static function ordinalGap(): array
    {
        $files = self::baseFiles();
        unset($files[4]);

        return self::subjects($files);
    }

    /** One subject declares `[k/14]`, the rest 13. */
    public static function disagreeingTotal(): array
    {
        $subjects = self::base();
        $subjects[2] = str_replace('[3/13]', '[3/14]', $subjects[2]);

        return $subjects;
    }

    /** `part2` posted twice at ordinal 3. */
    public static function duplicatedOrdinal(): array
    {
        $subjects = self::base();
        array_splice($subjects, 3, 0, [$subjects[2]]);

        return $subjects;
    }

    /** Every subject declares 14 over the same twelve files. */
    public static function declaresTwoMore(): array
    {
        return self::subjects(self::baseFiles(), 14);
    }

    /** The index at 1, the six volumes at 2 to 7, `part1` to `part5` at 8 to 12. */
    public static function volumesBeforeParts(): array
    {
        $base = self::baseFiles();
        $files = [1 => $base[1]];

        foreach ([7, 8, 9, 10, 11, 12, 2, 3, 4, 5, 6] as $position => $ordinal) {
            $files[$position + 2] = $base[$ordinal];
        }

        return self::subjects($files);
    }

    /** One subject without a filename {@see Par2Inventory::filename()} can read. */
    public static function unreadableFilename(): array
    {
        $subjects = self::base();
        $subjects[3] = '[4/13] - Show.Name.part3.rar yEnc (1/1)';

        return $subjects;
    }

    /** `vol08+08` in place of `vol07+08`: ordinals and counts intact, the series has a gap. */
    public static function seriesGap(): array
    {
        $files = self::baseFiles();
        $files[10] = 'Show.Name.vol08+08.par2';

        return self::subjects($files);
    }

    /** An even split: `vol00+20` ... `vol98+19` at 7 to 12. */
    public static function evenSplit(): array
    {
        $files = self::baseFiles();
        $volumes = ['vol00+20', 'vol20+20', 'vol40+20', 'vol60+19', 'vol79+19', 'vol98+19'];

        foreach ($volumes as $offset => $volume) {
            $files[7 + $offset] = 'Show.Name.'.$volume.'.par2';
        }

        return self::subjects($files);
    }

    /** A single recovery volume: the index, five parts and `vol00+01`, declaring 8. */
    public static function singleRecoveryVolume(): array
    {
        return self::subjects(array_slice(self::baseFiles(), 0, 7, true), 8);
    }

    /**
     * An NZB document holding the given subjects, each with `$present` segments.
     *
     * Pair it with subjects built for the declared segment count; `$present` below that leaves
     * segments missing from every file.
     *
     * @param  list<string>  $subjects
     */
    public static function nzb(array $subjects, int $present = 1, string $messageIdPrefix = 'seg'): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">'."\n";

        foreach ($subjects as $index => $subject) {
            $xml .= '  <file poster="p@example.org" date="1700000000" subject="'.htmlspecialchars($subject, ENT_QUOTES | ENT_XML1).'">'."\n"
                .'    <groups><group>alt.binaries.test</group></groups>'."\n    <segments>\n";

            for ($number = 1; $number <= $present; $number++) {
                $xml .= '      <segment bytes="900" number="'.$number.'">'.$messageIdPrefix.$index.'p'.$number.'@host</segment>'."\n";
            }

            $xml .= "    </segments>\n  </file>\n";
        }

        return $xml.'</nzb>'."\n";
    }

    /** @return array<int, string> */
    private static function withLastVolume(string $filename): array
    {
        $files = self::baseFiles();
        $files[12] = $filename;

        return $files;
    }
}
