@props(['label'])
{{--
    One bar of the filter bar (SPEC 3.0): the cells of one kind (x-checkbox-menu with `cell`) in a
    continuous rounded bar, every cell the same width, divided by hairlines. The bar look of a
    cell applies only inside this wrapper.
--}}
<div {{ $attributes->class('filter-bar') }} role="group" aria-label="{{ $label }}">
    {{ $slot }}
</div>
