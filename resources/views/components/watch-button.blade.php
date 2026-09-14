@props(['root', 'id', 'title', 'watched' => false, 'kind' => 'button', 'label' => null, 'remove' => false, 'size' => 'sm'])
@php
    $endpoint = route('watchlist.picker', ['root' => $root, 'id' => $id]);
    $watchAttributes = $attributes->merge([ ...($label !== null ? ['data-watch-label-kind' => $label] : []), 'data-watch-key' => $root.':'.$id, 'data-watch-title' => $title, 'data-watched' => $watched ? '1' : '0', $remove ? 'data-watch-remove' : 'data-watch-picker' => $endpoint]);
@endphp
@if(in_array($kind, ['row', 'heart'], true))
    <button type="button" {{ $watchAttributes->class([$kind === 'row' ? 'release-action release-action-muted' : 'release-cover-heart']) }} aria-label="{{ $watched ? 'Edit Watchlist choices for ' : 'Watch ' }}{{ $title }}" title="{{ $watched ? 'On your watchlist · click to edit' : 'Watch '.$title }}"><i class="{{ $watched ? 'fas' : 'far' }} fa-heart" aria-hidden="true"></i></button>
@else
    <x-button :attributes="$watchAttributes->class(['watchlist-button'])" :variant="$remove ? 'ghost' : ($label === 'Edit' ? 'secondary' : ($label === 'Add' || $watched ? 'primary' : 'secondary'))" :size="$size" :icon="$remove ? 'fas fa-trash' : ($label === 'Edit' ? 'fas fa-pen' : ($watched ? 'fas fa-heart' : 'far fa-heart'))"><span @if(!$remove) data-watch-label @endif>{{ $label ?? ($watched ? 'Watching' : 'Watch') }}</span>@if(!$label)<i class="fas fa-chevron-down" aria-hidden="true"></i>@endif</x-button>
@endif
