@props(['root', 'title', 'year' => null, 'href'])

@php
    $icon = match ($root) {
        'movies' => 'fa-film',
        'tv' => 'fa-tv',
        'audio', 'music' => 'fa-music',
        'console', 'games' => 'fa-gamepad',
        'books' => 'fa-book-open',
        default => 'fa-box',
    };
@endphp

@if($root !== 'adult' && filled($title))
    <x-chip variant="entity" :icon="'fas '.$icon" :href="$href" :title="'Open '.$title" {{ $attributes }}>{{ $title }}{{ filled($year) && $root !== 'tv' ? ' · '.$year : '' }}</x-chip>
@endif
