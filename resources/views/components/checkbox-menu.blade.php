@props(['name', 'label', 'options' => [], 'selected' => [], 'kind' => 'text'])
{{--
    A multi-select checkbox menu (SPEC section 2 rule 10): OR within the menu, the button reads
    "Label: A, B" and turns coral when set, "Any …" clears. It stays open while ticking and
    closes on Escape, a click outside or focus leaving it. Each change dispatches a bubbling
    `checkbox-menu-change` event carrying {name, values}; the page decides what to reload.
--}}
@php
    $ticked = array_map('strval', $selected);
    $on = array_values(array_filter($options, static fn (string $text, int|string $value): bool => in_array((string) $value, $ticked, true), ARRAY_FILTER_USE_BOTH));
@endphp
<div {{ $attributes->class(['checkbox-menu', 'is-set' => $on !== []]) }} x-data="checkboxMenu" data-name="{{ $name }}" data-label="{{ $label }}"
     x-on:keydown.escape.window="closeAndFocus" x-on:focusout="focusLeft" x-on:click.outside="close">
    <button type="button" class="checkbox-menu-button" x-ref="button" x-on:click="toggle" aria-haspopup="true" x-bind:aria-expanded="open"
            data-part="{{ $on === [] ? 'filter menu button' : 'filter menu button, set' }}"><span x-ref="summary">{{ $label }}: {{ $on === [] ? 'any' : implode(', ', $on) }}</span><i class="fas fa-chevron-down" aria-hidden="true"></i></button>
    <div class="checkbox-menu-panel" role="menu" aria-label="{{ $label }}" x-show="open" x-cloak>
        <button type="button" class="checkbox-menu-item" role="menuitemcheckbox" data-any aria-checked="{{ $on === [] ? 'true' : 'false' }}" x-on:click="clear"><span class="checkbox-menu-box"><i class="fas fa-check" aria-hidden="true"></i></span>Any {{ strtolower($label) }}</button>
        @foreach($options as $value => $text)
            <button type="button" class="checkbox-menu-item" role="menuitemcheckbox" data-value="{{ $value }}" data-text="{{ $text }}" aria-checked="{{ in_array((string) $value, $ticked, true) ? 'true' : 'false' }}" x-on:click="pick"><span class="checkbox-menu-box"><i class="fas fa-check" aria-hidden="true"></i></span>@if($kind === 'resolution')<x-resolution-chip :resolution="collect(\App\Enums\ReleaseResolution::cases())->first(fn ($case) => $case->label() === $text)" :part="false" />@else{{ $text }}@endif</button>
        @endforeach
    </div>
</div>
