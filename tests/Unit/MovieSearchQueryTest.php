<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\MovieSearchQuery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MovieSearchQueryTest extends TestCase
{
    #[Test]
    public function it_maps_unqualified_words_to_all_movie_text_fields(): void
    {
        $query = MovieSearchQuery::fromInput(['q' => 'nolan batman']);

        $this->assertSame(['all' => 'nolan batman'], $query->indexTerms());
    }

    #[Test]
    public function it_parses_prefixed_and_quoted_terms_with_actor_aliases(): void
    {
        $query = MovieSearchQuery::fromInput([
            'q' => 'actor:"tom hanks" director:spielberg plot:heist title:terminal',
        ]);

        $this->assertSame([
            'title' => 'terminal',
            'actors' => 'tom hanks',
            'director' => 'spielberg',
            'plot' => 'heist',
        ], $query->indexTerms());
    }

    #[Test]
    public function it_merges_the_search_box_with_advanced_fields(): void
    {
        $query = MovieSearchQuery::fromInput([
            'q' => 'batman actor:bale',
            'title' => 'dark knight',
            'actors' => 'christian',
            'actor' => 'heath ledger',
            'director' => 'nolan',
            'plot' => 'gotham',
        ]);

        $this->assertSame([
            'all' => 'batman',
            'title' => 'dark knight',
            'actors' => 'bale christian heath ledger',
            'director' => 'nolan',
            'plot' => 'gotham',
        ], $query->indexTerms());
    }

    #[Test]
    public function it_ignores_array_input_and_deduplicates_words(): void
    {
        $query = MovieSearchQuery::fromInput([
            'q' => ['not', 'scalar'],
            'title' => 'Matrix matrix',
        ]);

        $this->assertSame(['title' => 'Matrix'], $query->indexTerms());
    }
}
