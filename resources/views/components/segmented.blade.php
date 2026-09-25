@props(['items' => [], 'current' => null, 'label' => 'View'])
{{-- Two or more links shown as one switch; the current one is coral. --}}
<div {{ $attributes->class('segmented') }} role="group" aria-label="{{ $label }}">
    @foreach($items as $text => $href)
        @if($text === $current)
            <a href="{{ $href }}" aria-current="page" data-part="view switch, current">{{ $text }}</a>
        @else
            <a href="{{ $href }}" data-part="view switch, other">{{ $text }}</a>
        @endif
    @endforeach
</div>
