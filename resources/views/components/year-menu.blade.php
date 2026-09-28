@props(['decades', 'selected' => [], 'from' => null, 'to' => null, 'first' => \App\Data\MovieFilmFilters::FIRST_YEAR, 'last' => \App\Data\MovieFilmFilters::lastYear()])
{{--
    The film bar's Year cell (Movies SPEC 5.2), a cell of x-filter-bar like x-checkbox-menu with
    `cell`: "Any year", the decades as tick boxes in three columns, then the Range (From, To,
    Apply). One decade reads "1990s", several "2 chosen" (the title names them); a range reads
    "1980–1989", or "2024" for From alone. yearMenu (year-menu-component.js) refuses a range
    outside $first–$last or with the later year first, the message replacing the "Range" heading.
    $decades: value => label, newest first; $selected: the ticked decades; $from / $to: the range.
--}}
@php
    $ticked = array_map('strval', $selected);
    $on = array_values(array_filter($decades, static fn (string $label, int|string $value): bool => in_array((string) $value, $ticked, true), ARRAY_FILTER_USE_BOTH));
    $range = \App\Data\MovieFilmFilters::rangeLabel($from, $to);
    $reads = match (true) {
        $range !== '' => $range,
        $on === [] => 'any',
        count($on) === 1 => $on[0],
        default => count($on).' chosen',
    };
    $set = $range !== '' || $on !== [];
    $mark = '<span class="checkbox-menu-box"><i class="fas fa-check" aria-hidden="true"></i></span>';
@endphp
<div {{ $attributes->class(['checkbox-menu', 'is-cell', 'year-menu', 'is-set' => $set]) }} x-data="yearMenu" data-name="year" data-label="Year" data-summary="count" data-first="{{ $first }}" data-last="{{ $last }}"
     x-on:keydown.escape.window="closeAndFocus" x-on:focusout="focusLeft" x-on:click.outside="close">
    <button type="button" class="checkbox-menu-button" x-ref="button" x-on:click="toggle" aria-haspopup="dialog" x-bind:aria-expanded="open"
            title="{{ $set ? 'Year: '.($range !== '' ? $range : implode(', ', $on)) : '' }}"><span class="checkbox-menu-label"><span class="checkbox-menu-name">Year</span><span class="checkbox-menu-sep">: </span><span @class(['checkbox-menu-value', 'is-any' => ! $set]) x-ref="value">{{ $reads }}</span></span><i class="fas fa-chevron-down" aria-hidden="true"></i></button>
    <div class="checkbox-menu-panel year-menu-panel" role="dialog" aria-label="Year" x-ref="panel" x-show="open" x-cloak>
        <div class="year-menu-decades" role="menu" aria-label="Any year or decades">
            <button type="button" class="checkbox-menu-item" role="menuitemcheckbox" data-any aria-checked="{{ $set ? 'false' : 'true' }}" x-on:click="clear">{!! $mark !!}Any year</button>
            <div class="checkbox-menu-heading" aria-hidden="true">Decades</div>
            @foreach($decades as $value => $label)
                <button type="button" class="checkbox-menu-item" role="menuitemcheckbox" data-value="{{ $value }}" data-text="{{ $label }}" aria-checked="{{ in_array((string) $value, $ticked, true) ? 'true' : 'false' }}" x-on:click="pick">{!! $mark !!}{{ $label }}</button>
            @endforeach
        </div>
        <div class="checkbox-menu-rule"></div>
        <div class="checkbox-menu-heading" x-ref="rangeHeading" data-heading="Range">Range</div>
        <form class="year-menu-range" x-on:submit.prevent="applyRange" x-on:input="rangeInput">
            <input x-ref="from" inputmode="numeric" maxlength="4" placeholder="From" aria-label="From year" autocomplete="off" value="{{ $from }}">
            <span>to</span>
            <input x-ref="to" inputmode="numeric" maxlength="4" placeholder="To" aria-label="To year" autocomplete="off" value="{{ $to }}">
            <button type="submit" class="tv-button" x-ref="apply" @disabled($from === null)>Apply</button>
        </form>
    </div>
</div>
