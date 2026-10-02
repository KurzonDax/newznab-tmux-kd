@props(['root', 'title', 'year' => null, 'href'])

@php
    $icon = match ($root) {
        'movies' => 'fa-film',
        'tv', 'anime' => 'fa-tv',
        'audio', 'music' => 'fa-music',
        'console' => 'fa-gamepad',
        default => 'fa-box',
    };
@endphp

@if(! in_array($root, ['adult', 'books', 'games'], true) && filled($title))
    <x-chip variant="entity" :icon="'fas '.$icon" :href="$href" :title="'Open '.$title" {{ $attributes }}>{{ $title }}{{ filled($year) && !in_array($root, ['tv', 'anime'], true) ? ' · '.$year : '' }}</x-chip>
@endif
