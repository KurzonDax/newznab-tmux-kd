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
        yield 'an unknown value kept as written' => ['Klingon', 'Klingon'];
        yield 'Multiple languages is a name' => ['Multiple languages', 'Multiple languages'];
        yield 'no speech' => ['zxx', null];
        yield 'undetermined' => ['und', null];
        yield 'multiple' => ['mul', null];
        yield 'local use, first' => ['qaa', null];
        yield 'local use, last' => ['qtz', null];
        yield 'past local use' => ['qua', 'qua'];
        yield 'unknown' => ['unknown', null];
        yield 'Unknown language' => ['Unknown language', null];
        yield 'unk' => ['UNK', null];
        yield 'None' => ['None', null];
        yield 'empty' => ['', null];
        yield 'null' => [null, null];
        yield 'blank' => ['  ', null];
    }

    #[DataProvider('values')]
    public function test_a_value_names_its_language(?string $value, ?string $expected): void
    {
        $this->assertSame($expected, LanguageNames::name($value));
    }

    public function test_a_name_longer_than_the_column_is_cut(): void
    {
        $this->assertSame(str_repeat('x', 64), LanguageNames::name(str_repeat('x', 70)));
    }

    public function test_a_multi_dub_names_each_language_once_in_order(): void
    {
        $this->assertSame(['English', 'Hindi', 'Klingon'],
            LanguageNames::distinct(['en', 'hin', 'English (US)', 'zxx', null, 'Klingon', 'en-GB']));
    }
}
