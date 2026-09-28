<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\MetadataProcessing\MovieCreditsText;
use PHPUnit\Framework\TestCase;

/** The one split rule the fill migration, the TMDB fallback and the admin edit share. */
final class MovieCreditsTextTest extends TestCase
{
    public function test_a_name_suffix_after_a_comma_joins_the_name_before_it_as_tmdb_spells_it(): void
    {
        $this->assertSame(
            ['Robert Downey Jr.', 'Gwyneth Paltrow', 'Martin Luther King III', 'Davis Love IV', 'Sammy Davis Sr', 'Terrence Howard'],
            MovieCreditsText::names('Robert Downey, Jr., Gwyneth Paltrow, Martin Luther King, III, Davis Love, IV, Sammy Davis, Sr, Terrence Howard'),
        );
    }

    public function test_control_characters_become_spaces_and_empty_parts_are_dropped(): void
    {
        $this->assertSame(['Anna Karina', 'Jean Paul'], MovieCreditsText::names(" Anna\tKarina, ,\x00, Jean\x0BPaul ,"));
    }

    public function test_every_name_is_kept_and_nothing_is_filtered(): void
    {
        $cast = implode(', ', array_map(static fn (int $n): string => 'Actor '.$n, range(1, 14)));

        $this->assertCount(14, MovieCreditsText::names($cast));
        $this->assertSame(['Action', 'Adventure', 'Science F'], MovieCreditsText::names('Action, Adventure, Science F'));
        $this->assertSame(['Jr.'], MovieCreditsText::names('Jr.'));
        $this->assertSame([], MovieCreditsText::names(''));
    }

    public function test_people_have_no_tmdb_id(): void
    {
        $this->assertSame([['name' => 'Jon Favreau', 'tmdb_id' => null]], MovieCreditsText::people('Jon Favreau'));
    }
}
