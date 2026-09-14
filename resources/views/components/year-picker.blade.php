@props([
    'selected' => '',
    'from' => '',
    'to' => '',
    'id' => 'year',
    'label' => 'Year',
    'navigate' => false,
    'compact' => false,
])

@php
    $range = \App\Support\YearRange::fromInput($selected, $from, $to);
    $selected = is_scalar($selected) ? trim((string) $selected) : '';
    $showCustomRange = $selected === 'custom';
    $selected = $showCustomRange || $range !== null ? $selected : '';
    $from = $showCustomRange ? ($range?->from ?? '') : '';
    $to = $showCustomRange ? ($range?->to ?? '') : '';
    $maximumYear = (int) date('Y') + 1;
@endphp

<div class="year-picker" data-year-picker data-year-navigate="{{ $navigate ? '1' : '0' }}">
    <div>
        <x-label :for="$id" :class="$compact ? 'sr-only' : ''">{{ $label }}</x-label>
        <x-select width="compact" :id="$id" name="year" data-year-picker-select>
            <option value="">All Years</option>
            <optgroup label="Decades">
                @foreach(\App\Support\YearRange::decades() as $decade)
                    <option value="{{ $decade }}" @selected($selected === $decade)>{{ $decade }}</option>
                @endforeach
            </optgroup>
            <option value="custom" @selected($showCustomRange)>Custom Range</option>
            <optgroup label="Individual Years">
                @foreach(\App\Support\YearRange::years() as $year)
                    <option value="{{ $year }}" @selected($selected === $year)>{{ $year }}</option>
                @endforeach
            </optgroup>
        </x-select>
    </div>
    <div class="year-picker-range {{ $showCustomRange ? '' : 'hidden' }}" data-year-custom-range>
        <div>
            <x-label :for="$id.'-from'">From</x-label>
            <x-input :id="$id.'-from'" name="year_from" type="number" min="1900" :max="$maximumYear" :value="$from" :disabled="!$showCustomRange" placeholder="1900" />
        </div>
        <div>
            <x-label :for="$id.'-to'">To</x-label>
            <x-input :id="$id.'-to'" name="year_to" type="number" min="1900" :max="$maximumYear" :value="$to" :disabled="!$showCustomRange" :placeholder="$maximumYear" />
        </div>
        @if($navigate)
            <x-button type="button" data-year-apply>Apply year</x-button>
        @endif
    </div>
    @if($selected !== '')
        <a href="{{ request()->fullUrlWithoutQuery(['year', 'year_from', 'year_to', 'page', '_fragment']) }}" data-year-clear class="text-primary-600 dark:text-primary-400">Clear year</a>
    @endif
</div>
