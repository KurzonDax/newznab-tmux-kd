@props(['name', 'label', 'options' => [], 'selected' => [], 'kind' => 'text', 'any' => null, 'summary' => 'list', 'fixed' => false, 'part' => null])
{{--
    A multi-select checkbox menu (SPEC section 2 rule 10): OR within the menu, the button reads
    "Label: A, B" and turns coral when set, "Any …" clears. It stays open while ticking and
    closes on Escape, a click outside or focus leaving it. Each change dispatches a bubbling
    `checkbox-menu-change` event carrying {name, values}; the page decides what to reload.
    The shows wall's menus are `fixed` (one width whatever is ticked, the panel opening to the
    right under the top bar) with summary="count" ("Genre: 2 chosen", the full list in the title).
    `part` names the button's data-part; null uses the releases screen's names, false omits it.
--}}
@php
    $ticked = array_map('strval', $selected);
    $on = array_values(array_filter($options, static fn (string $text, int|string $value): bool => in_array((string) $value, $ticked, true), ARRAY_FILTER_USE_BOTH));
    $counted = $summary === 'count';
    $reads = $on === [] ? 'any' : ($counted && count($on) > 1 ? count($on).' chosen' : implode(', ', $on));
    $buttonPart = $part ?? ($on === [] ? 'filter menu button' : 'filter menu button, set');
@endphp
<div {{ $attributes->class(['checkbox-menu', 'is-fixed' => $fixed, 'is-set' => $on !== []]) }} x-data="checkboxMenu" data-name="{{ $name }}" data-label="{{ $label }}" data-summary="{{ $summary }}"
     x-on:keydown.escape.window="closeAndFocus" x-on:focusout="focusLeft" x-on:click.outside="close">
    <button type="button" class="checkbox-menu-button" x-ref="button" x-on:click="toggle" aria-haspopup="true" x-bind:aria-expanded="open"
            @if($counted) title="{{ $on === [] ? '' : $label.': '.implode(', ', $on) }}" @endif @if($buttonPart !== false) data-part="{{ $buttonPart }}" @endif><span class="checkbox-menu-label" x-ref="summary">{{ $label }}: {{ $reads }}</span><i class="fas fa-chevron-down" aria-hidden="true"></i></button>
    <div class="checkbox-menu-panel" role="menu" aria-label="{{ $label }}" x-ref="panel" x-show="open" x-cloak>
        <button type="button" class="checkbox-menu-item" role="menuitemcheckbox" data-any aria-checked="{{ $on === [] ? 'true' : 'false' }}" x-on:click="clear"><span class="checkbox-menu-box"><i class="fas fa-check" aria-hidden="true"></i></span>{{ $any ?? 'Any '.strtolower($label) }}</button>
        @foreach($options as $value => $text)
            <button type="button" class="checkbox-menu-item" role="menuitemcheckbox" data-value="{{ $value }}" data-text="{{ $text }}" aria-checked="{{ in_array((string) $value, $ticked, true) ? 'true' : 'false' }}" x-on:click="pick"><span class="checkbox-menu-box"><i class="fas fa-check" aria-hidden="true"></i></span>@if($kind === 'resolution')<x-resolution-chip :resolution="collect(\App\Enums\ReleaseResolution::cases())->first(fn ($case) => $case->label() === $text)" :part="false" />@else{{ $text }}@endif</button>
        @endforeach
    </div>
</div>
