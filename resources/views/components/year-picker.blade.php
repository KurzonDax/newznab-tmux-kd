@props([
    'years' => [],
    'selected' => '',
    'from' => '',
    'to' => '',
    'id' => 'year',
])

@php
    $decades = \App\Support\YearRange::decades();
    $showCustomRange = $selected === 'custom';
    $maximumYear = now()->addYear()->year;
@endphp

<div data-year-picker>
    <x-label :for="$id">Year</x-label>
    <x-select :id="$id" name="year" data-year-picker-select>
        <option value="">All Years</option>
        <optgroup label="Decades">
            @foreach($decades as $decade)
                <option value="{{ $decade }}" @selected($selected === $decade)>{{ $decade }}</option>
            @endforeach
        </optgroup>
        <option value="custom" @selected($showCustomRange)>Custom Range</option>
        <optgroup label="Individual Years">
            @foreach($years as $year)
                <option value="{{ $year }}" @selected((string) $selected === (string) $year)>{{ $year }}</option>
            @endforeach
        </optgroup>
    </x-select>

    <div class="mt-3 grid grid-cols-2 gap-3 {{ $showCustomRange ? '' : 'hidden' }}" data-year-custom-range>
        <div>
            <x-label :for="$id.'-from'">From</x-label>
            <x-input :id="$id.'-from'" name="year_from" type="number" min="1900" :max="$maximumYear" :value="$from" placeholder="1900" />
        </div>
        <div>
            <x-label :for="$id.'-to'">To</x-label>
            <x-input :id="$id.'-to'" name="year_to" type="number" min="1900" :max="$maximumYear" :value="$to" :placeholder="$maximumYear" />
        </div>
    </div>
</div>
