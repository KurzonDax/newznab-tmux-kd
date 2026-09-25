@props(['resolution', 'part' => true])
@php
    /** @var \App\Enums\ReleaseResolution $resolution */
    $tone = match ($resolution) {
        \App\Enums\ReleaseResolution::Uhd => '4k',
        \App\Enums\ReleaseResolution::FullHd => '1080',
        \App\Enums\ReleaseResolution::Hd => '720',
        \App\Enums\ReleaseResolution::Sd => 'sd',
        \App\Enums\ReleaseResolution::Unknown => 'unknown',
    };
@endphp
<span {{ $attributes->class(['resolution-chip', 'resolution-chip-'.$tone]) }} @if($part) data-part="resolution chip {{ $resolution->label() }}" @endif>{{ $resolution->label() }}</span>
