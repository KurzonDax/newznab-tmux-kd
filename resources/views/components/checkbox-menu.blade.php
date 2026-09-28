@props(['name', 'label', 'options' => [], 'selected' => [], 'kind' => 'text', 'any' => null, 'summary' => 'list', 'fixed' => false, 'part' => null, 'cell' => false, 'single' => false, 'short' => [], 'noun' => null])
{{--
    A multi-select checkbox menu (SPEC section 2 rule 10): OR within the menu, the button reads
    "Label: A, B" and turns coral when set, "Any …" clears. It stays open while ticking and
    closes on Escape, a click outside or focus leaving it. Each change dispatches a bubbling
    `checkbox-menu-change` event carrying {name, values, single}; the page decides what to reload.
    A `fixed` menu has one width whatever is ticked, the panel opening to the right under the
    top bar; summary="count" reads "Genre: 2 chosen", the full list in the title.
    `part` names the button's data-part; null uses the releases screen's names, false omits it.

    A `cell` is a cell of the filter bar (x-filter-bar, SPEC 3.0): the name above the value, a
    counted summary, the panel opening to the right under the cell; its look lives only inside
    the bar. A `single` menu (Completion) has radio items, "Any …" first, and `short` gives each
    value's text in the cell ("100%" for "100% only"). A menu of more than 10 options opens with
    a search field ("Search {noun}", the label in lower case by default).
--}}
@php
    $ticked = array_map('strval', $selected);
    $onValues = array_values(array_filter(array_keys($options), static fn (int|string $value): bool => in_array((string) $value, $ticked, true)));
    $on = array_map(static fn (int|string $value): string => $options[$value], $onValues);
    $counted = $cell || $summary === 'count';
    $reads = match (true) {
        $on === [] => 'any',
        count($on) === 1 => $short[$onValues[0]] ?? $on[0],
        $counted => count($on).' chosen',
        default => implode(', ', $on),
    };
    $buttonPart = $part ?? ($on === [] ? 'filter menu button' : 'filter menu button, set');
    $searchable = count($options) > 10;
    $role = $single ? 'menuitemradio' : 'menuitemcheckbox';
    $mark = $single ? '<span class="checkbox-menu-dot"></span>' : '<span class="checkbox-menu-box"><i class="fas fa-check" aria-hidden="true"></i></span>';
@endphp
<div {{ $attributes->class(['checkbox-menu', 'is-cell' => $cell, 'is-fixed' => $fixed, 'is-set' => $on !== []]) }} x-data="checkboxMenu" data-name="{{ $name }}" data-label="{{ $label }}" data-summary="{{ $counted ? 'count' : $summary }}"@if($single) data-single="true"@endif
     x-on:keydown.escape.window="closeAndFocus" x-on:focusout="focusLeft" x-on:click.outside="close">
    <button type="button" class="checkbox-menu-button" x-ref="button" x-on:click="toggle" aria-haspopup="{{ $searchable ? 'dialog' : 'true' }}" x-bind:aria-expanded="open"
            @if($counted) title="{{ $on === [] ? '' : $label.': '.implode(', ', $on) }}" @endif @if($buttonPart !== false) data-part="{{ $buttonPart }}" @endif><span class="checkbox-menu-label"><span class="checkbox-menu-name">{{ $label }}</span><span class="checkbox-menu-sep">: </span><span @class(['checkbox-menu-value', 'is-any' => $on === []]) x-ref="value">{{ $reads }}</span></span><i class="fas fa-chevron-down" aria-hidden="true"></i></button>
    <div @class(['checkbox-menu-panel', 'is-searchable' => $searchable]) role="{{ $searchable ? 'dialog' : 'menu' }}" aria-label="{{ $label }}" x-ref="panel" x-show="open" x-cloak>
        @if($searchable)
            <div class="checkbox-menu-search"><i class="fas fa-magnifying-glass" aria-hidden="true"></i><input type="text" x-ref="search" x-on:input="narrow" placeholder="Search {{ $noun ?? strtolower($label) }}" aria-label="Search {{ $noun ?? strtolower($label) }}" autocomplete="off"></div>
            <div role="menu" aria-label="{{ $label }}">
        @endif
        <button type="button" class="checkbox-menu-item" role="{{ $role }}" data-any aria-checked="{{ $on === [] ? 'true' : 'false' }}" x-on:click="clear">{!! $mark !!}{{ $any ?? 'Any '.strtolower($label) }}</button>
        @foreach($options as $value => $text)
            <button type="button" class="checkbox-menu-item" role="{{ $role }}" data-value="{{ $value }}" data-text="{{ $text }}"@isset($short[$value]) data-short="{{ $short[$value] }}"@endisset aria-checked="{{ in_array((string) $value, $ticked, true) ? 'true' : 'false' }}" x-on:click="pick">{!! $mark !!}@if($kind === 'resolution')<x-resolution-chip :resolution="collect(\App\Enums\ReleaseResolution::cases())->first(fn ($case) => $case->label() === $text)" :part="false" />@else{{ $text }}@endif</button>
        @endforeach
        @if($searchable)
            </div>
        @endif
    </div>
</div>
