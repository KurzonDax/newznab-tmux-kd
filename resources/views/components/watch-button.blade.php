@props(['root', 'id', 'title', 'watched' => false, 'kind' => 'button', 'label' => null, 'remove' => false, 'size' => 'sm'])
@php
    $endpoint = route('watchlist.picker', ['root' => $root, 'id' => $id]);
    // A follow button (no label) carries its own wording for both states; watchlist-component.js only swaps between them.
    $follow = $label === null && ! $remove;
    [$offTitle, $onTitle, $offAria, $onAria] = ['Follow '.$title, 'Following · click to unfollow', 'Follow '.$title, 'Unfollow '.$title];
    $followTitle = $watched ? $onTitle : $offTitle;
    $wording = ! $follow ? [] : ['data-watch-off-title' => $offTitle, 'data-watch-on-title' => $onTitle,
        ...($kind === 'button' ? ['data-watch-off-label' => 'Follow', 'data-watch-on-label' => 'Following'] : ['data-watch-off-aria' => $offAria, 'data-watch-on-aria' => $onAria])];
    $watchAttributes = $attributes->merge([ ...($label !== null ? ['data-watch-label-kind' => $label] : []), 'data-watch-key' => $root.':'.$id, 'data-watch-title' => $title, 'data-watched' => $watched ? '1' : '0', $remove ? 'data-watch-remove' : 'data-watch-picker' => $endpoint, ...$wording]);
@endphp
@if(in_array($kind, ['row', 'heart'], true))
    <button type="button" {{ $watchAttributes->class([$kind === 'row' ? 'release-action release-action-muted' : 'release-cover-heart']) }} aria-label="{{ $watched ? $onAria : $offAria }}" title="{{ $followTitle }}"><i class="{{ $watched ? 'fas' : 'far' }} fa-bookmark" aria-hidden="true"></i></button>
@else
    <x-button :attributes="$watchAttributes->class(['watchlist-button'])->merge($follow ? ['title' => $followTitle] : [])" :variant="$remove ? 'ghost' : ($label === 'Edit' ? 'secondary' : ($label === 'Add' || $watched ? 'primary' : 'secondary'))" :size="$size" :icon="$remove ? 'fas fa-trash' : ($label === 'Edit' ? 'fas fa-pen' : ($watched ? 'fas fa-bookmark' : 'far fa-bookmark'))"><span @if(!$remove) data-watch-label @endif>{{ $label ?? ($watched ? 'Following' : 'Follow') }}</span>@if(!$label)<i class="fas fa-chevron-down" aria-hidden="true"></i>@endif</x-button>
@endif
