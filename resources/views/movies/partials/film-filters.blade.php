{{--
    The film bar (Movies SPEC 5.1, 5.2): Genre, Year, Score, MPAA Rating and Language describe the
    film; on the Movie releases list and the Films wall. $options: the film menus' options by URL
    key; $films: the ticked values (MovieFilmFilters).
--}}
<x-filter-bar label="The film" class="is-film">
    <x-checkbox-menu cell name="genre" label="Genre" any="Any genre" noun="genres" :part="false" :options="$options['genre']" :selected="$films->genres" />
    <x-year-menu :decades="\App\Data\MovieFilmFilters::decadeOptions()" :selected="$films->decades" :from="$films->yearFrom" :to="$films->yearTo" />
    <x-checkbox-menu cell name="score" label="Score" any="Any score" :part="false" :options="\App\Data\MovieFilmFilters::SCORES" :selected="$films->scores" />
    <x-checkbox-menu cell name="rating" label="MPAA Rating" any="Any MPAA rating" :part="false" :options="$options['rating']" :selected="$films->ratings" />
    <x-checkbox-menu cell name="language" label="Language" any="Any language" noun="languages" :part="false" :options="$options['language']" :selected="$films->languages" />
</x-filter-bar>
