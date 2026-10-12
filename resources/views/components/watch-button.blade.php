@props(['root', 'id', 'title', 'watched' => false, 'label' => null, 'remove' => false])
@php
    $endpoint = route('watchlist.picker', ['root' => $root, 'id' => $id]);
    $watchAttributes = $attributes->merge([ ...($label !== null ? ['data-watch-label-kind' => $label] : []), 'data-watch-key' => $root.':'.$id, 'data-watch-title' => $title, 'data-watched' => $watched ? '1' : '0', $remove ? 'data-watch-remove' : 'data-watch-picker' => $endpoint]);
@endphp
    <x-button :attributes="$watchAttributes->class(['watchlist-button'])" :variant="$remove ? 'ghost' : ($label === 'Add' ? 'primary' : 'secondary')" size="sm" :icon="$remove ? 'fas fa-trash' : ($label === 'Edit' ? 'fas fa-pen' : ($watched ? 'fas fa-bookmark' : 'far fa-bookmark'))"><span @if(!$remove) data-watch-label @endif>{{ $label }}</span></x-button>
