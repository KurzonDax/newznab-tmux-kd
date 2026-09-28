@props(['root', 'id', 'title', 'watched' => false, 'kind' => 'button', 'label' => null, 'remove' => false, 'size' => 'sm'])
@php
    $endpoint = route('watchlist.picker', ['root' => $root, 'id' => $id]);
    // A row follow button (no label) carries its own wording for both states; watchlist-component.js only swaps between them.
    $follow = $label === null && ! $remove;
    [$offTitle, $onTitle, $offAria, $onAria] = ['Follow '.$title, 'Following · click to unfollow', 'Follow '.$title, 'Unfollow '.$title];
    $followTitle = $watched ? $onTitle : $offTitle;
    $wording = ! $follow ? [] : ['data-watch-off-title' => $offTitle, 'data-watch-on-title' => $onTitle,
        ...($kind === 'button' ? [] : ['data-watch-off-aria' => $offAria, 'data-watch-on-aria' => $onAria])];
    $watchAttributes = $attributes->merge([ ...($label !== null ? ['data-watch-label-kind' => $label] : []), 'data-watch-key' => $root.':'.$id, 'data-watch-title' => $title, 'data-watched' => $watched ? '1' : '0', $remove ? 'data-watch-remove' : 'data-watch-picker' => $endpoint, ...$wording]);
@endphp
@if($kind === 'row')
    <button type="button" {{ $watchAttributes->class(['release-action release-action-muted']) }} aria-label="{{ $watched ? $onAria : $offAria }}" title="{{ $followTitle }}"><i class="{{ $watched ? 'fas' : 'far' }} fa-bookmark" aria-hidden="true"></i></button>
@else
    <x-button :attributes="$watchAttributes->class(['watchlist-button'])" :variant="$remove ? 'ghost' : ($label === 'Add' ? 'primary' : 'secondary')" :size="$size" :icon="$remove ? 'fas fa-trash' : ($label === 'Edit' ? 'fas fa-pen' : ($watched ? 'fas fa-bookmark' : 'far fa-bookmark'))"><span @if(!$remove) data-watch-label @endif>{{ $label }}</span></x-button>
@endif
