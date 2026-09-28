{{--
    The show bar (SPEC 3.0): the TV shows wall's six filters, on the wall and on the TV releases list.
    $options: the option lists by URL key; $shows: the ticked values (TvShowFilters); $firstPart: the
    first cell's data-part (false for none).
--}}
<x-filter-bar label="The show" class="is-show">
    <x-checkbox-menu cell name="genre" label="Genre" any="Any genre" noun="genres" :part="$firstPart" :options="$options['genre']" :selected="$shows->genres" />
    <x-checkbox-menu cell name="decade" label="Premiered" any="Any decade" :part="false" :options="$options['decade']" :selected="$shows->decades" />
    <x-checkbox-menu cell name="language" label="Language" any="Any language" noun="languages" :part="false" :options="$options['language']" :selected="$shows->languages" />
    <x-checkbox-menu cell name="network" label="Network" any="Any network" noun="networks" :part="false" :options="$options['network']" :selected="$shows->networks" />
    <x-checkbox-menu cell name="rating" label="Rating" any="Any rating" :part="false" :options="$options['rating']" :selected="$shows->ratings" />
    <x-checkbox-menu cell name="status" label="Status" any="Any status" :part="false" :options="$options['status']" :selected="$shows->statuses" />
</x-filter-bar>
