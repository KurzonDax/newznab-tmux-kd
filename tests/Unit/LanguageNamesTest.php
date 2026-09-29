<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\LanguageNames;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The Audio and Language menus' name rule (Movies SPEC 5.2). */
final class LanguageNamesTest extends TestCase
{
    /** @return iterable<string, array{?string, ?string}> */
    public static function values(): iterable
    {
        yield 'a code' => ['en', 'English'];
        yield 'a code with a region' => ['en-US', 'English'];
        yield 'a name with a region' => ['English (US)', 'English'];
        yield 'a name with an underscore region' => ['French_Canadian', 'French'];
        yield 'a three-letter code' => ['jpn', 'Japanese'];
        yield 'a region code' => ['pt-BR', 'Portuguese'];
        yield 'Mandarin' => ['mandarin', 'Chinese'];
        yield "TMDB's cn" => ['cn', 'Cantonese'];
        yield 'a name as written' => ['English', 'English'];
        yield 'a language intl names in English' => ['Klingon', 'Klingon'];
        yield 'Multiple languages names no language' => ['Multiple languages', null];
        yield 'no speech' => ['zxx', null];
        yield 'undetermined' => ['und', null];
        yield 'multiple' => ['mul', null];
        yield 'local use, first' => ['qaa', null];
        yield 'local use, last' => ['qtz', null];
        yield 'past local use' => ['qua', null];
        yield 'unknown' => ['unknown', null];
        yield 'Unknown language' => ['Unknown language', null];
        yield 'unk' => ['UNK', null];
        yield 'None' => ['None', null];
        yield 'empty' => ['', null];
        yield 'null' => [null, null];
        yield 'blank' => ['  ', null];
        yield 'a code missing from the table' => ['bho', 'Bhojpuri'];
        yield 'a two-letter code missing from the table' => ['mr', 'Marathi'];
        yield 'a macrolanguage member code' => ['arb', 'Arabic'];
        yield 'an ISO 639-2 name' => ['Panjabi', 'Punjabi'];
        yield 'an ISO 639-2 name with a region' => ['Panjabi (IN)', 'Punjabi'];
        yield 'an ISO 639-2 name intl spells differently' => ['Oriya', 'Odia'];
        yield 'an unaccented name with a region' => ['Norwegian Bokmal (NO)', 'Norwegian'];
        yield 'a second ISO 639-2 spelling' => ['Kirghiz', 'Kyrgyz'];
        yield 'a language group is not a language' => ['Bihari', null];
        yield 'an unaccented name' => ['Volapuk', 'Volapük'];
        yield 'a table spelling of an own name' => ['हिंदी', 'Hindi'];
        yield 'an own name in its script' => ['日本語', 'Japanese'];
        yield 'an own name' => ['Deutsch', 'German'];
        yield 'an English name of a code off the ISO 639-2 list' => ['Moroccan Arabic', 'Moroccan Arabic'];
        yield 'an English name of an aliased code' => ['Dari', 'Dari'];
        yield 'a quoted code' => ["'eng'", 'English'];
        yield 'a doubled name' => ['English / English', 'English'];
        yield 'Bengali by its code' => ['bn', 'Bengali'];
        yield 'Bengali by name' => ['Bengali', 'Bengali'];
        yield 'Bengali by name with a region' => ['Bengali (IN)', 'Bengali'];
        yield 'Bengali by its three-letter code' => ['ben', 'Bengali'];
        yield 'Norwegian Bokmål by its three-letter code' => ['nob', 'Norwegian'];
        yield 'a word that is not a language' => ['Original', null];
        yield 'text that is not a language' => ['中文字幕', null];
        yield 'undetermined by name' => ['Undetermined', null];
        yield 'no speech by name' => ['No linguistic content', null];
        yield 'a collective code intl cannot name' => ['cpe', null];
        yield 'a code intl cannot name' => ['har', null];
        yield 'a letter and a region' => ['e (A)', null];
        yield 'part of a name in brackets' => ['Auxiliary Language Association', null];
    }

    #[DataProvider('values')]
    public function test_a_value_names_its_language(?string $value, ?string $expected): void
    {
        $this->assertSame($expected, LanguageNames::name($value));
    }

    public function test_a_value_longer_than_the_column_names_no_language(): void
    {
        $this->assertNull(LanguageNames::name(str_repeat('x', 70)));
    }

    public function test_a_multi_dub_names_each_language_once_in_order(): void
    {
        $this->assertSame(['English', 'Hindi', 'Klingon'],
            LanguageNames::distinct(['en', 'hin', 'English (US)', 'zxx', null, 'Klingon', 'en-GB']));
    }
}
