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
        $this->assertSame(['Jr.'], MovieCreditsText::names('Jr.'));
        $this->assertSame([], MovieCreditsText::names(''));
    }

    public function test_a_genre_is_one_of_tmdbs_movie_genres_so_a_cut_off_name_is_dropped(): void
    {
        $this->assertSame(['Action', 'Adventure'], MovieCreditsText::genres('Action, Adventure, Science F'));
        $this->assertSame(['Drama', 'Fantasy'], MovieCreditsText::genres('Drama, Science Fi, Fantasy, Fa, T'));
    }

    public function test_a_cut_that_landed_after_a_space_gives_no_science_or_tv_genre(): void
    {
        $this->assertSame(
            ['Animation', 'Adventure', 'Comedy', 'Family', 'Fantasy', 'Mystery'],
            MovieCreditsText::genres('Animation, Adventure, Comedy, Family, Fantasy, Mystery, Science'),
        );
        $this->assertSame(['Drama', 'Horror'], MovieCreditsText::genres('Drama, Horror, TV'));
    }

    public function test_a_genre_matches_ignoring_case_and_takes_tmdbs_spelling(): void
    {
        $this->assertSame(['Science Fiction', 'TV Movie', 'Western'], MovieCreditsText::genres('science fiction, TV MOVIE, western'));
    }

    public function test_a_director_text_at_64_characters_loses_its_cut_off_last_director(): void
    {
        $text = 'Pedro Almodóvar, Alejandro González Iñárritu, Alfonso Cuarón, Gu';
        $this->assertSame(64, mb_strlen($text));
        $this->assertGreaterThan(64, strlen($text));

        $this->assertSame(
            [
                ['name' => 'Pedro Almodóvar', 'tmdb_id' => null],
                ['name' => 'Alejandro González Iñárritu', 'tmdb_id' => null],
                ['name' => 'Alfonso Cuarón', 'tmdb_id' => null],
            ],
            MovieCreditsText::directors($text),
        );
    }

    public function test_a_director_text_under_64_characters_keeps_its_last_director_however_many_bytes(): void
    {
        $text = 'Pedro Almodóvar, Alejandro González Iñárritu, Alfonso Cuarón';
        $this->assertLessThan(64, mb_strlen($text));
        $this->assertGreaterThanOrEqual(64, strlen($text));

        $this->assertSame(['Pedro Almodóvar', 'Alejandro González Iñárritu', 'Alfonso Cuarón'], array_column(MovieCreditsText::directors($text), 'name'));
    }

    public function test_a_director_text_cut_just_after_a_comma_keeps_the_whole_name_before_it(): void
    {
        foreach (['Pedro Almodóvar, Alejandro G. Iñárritu, Alfonso Cuarón, Ang Lee,', 'Pedro Almodóvar, Alejandro G Iñárritu, Alfonso Cuarón, Ang Lee, '] as $text) {
            $this->assertSame(64, mb_strlen($text));

            $this->assertSame('Ang Lee', array_column(MovieCreditsText::directors($text), 'name')[3] ?? null, $text);
        }
    }

    public function test_cast_keeps_its_last_name(): void
    {
        $cast = 'Brad Pitt, Edward Norton, Helena Bonham Carter, Meat Loaf, Jared Leto, Zach Grenier';
        $this->assertGreaterThan(64, mb_strlen($cast));

        $this->assertSame('Zach Grenier', array_column(MovieCreditsText::people($cast), 'name')[5]);
    }

    public function test_names_off_the_genre_list_stay_cast_and_directors(): void
    {
        $this->assertSame(['Science F', 'TV'], array_column(MovieCreditsText::people('Science F, TV'), 'name'));
        $this->assertSame(['Science F', 'TV'], array_column(MovieCreditsText::directors('Science F, TV'), 'name'));
    }

    public function test_people_have_no_tmdb_id(): void
    {
        $this->assertSame([['name' => 'Jon Favreau', 'tmdb_id' => null]], MovieCreditsText::people('Jon Favreau'));
    }
}
