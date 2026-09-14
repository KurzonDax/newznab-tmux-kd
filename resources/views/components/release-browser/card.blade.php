@php
    $entity = $row->entity;
@endphp
<article class="release-browser-card" data-release-row="{{ $row->guid }}" role="listitem">
    <input type="checkbox" value="{{ $row->guid }}" data-release-select @change="selectionChanged" aria-label="Select {{ $row->name }}">
    @include('components.release-browser.artwork')
    <div class="release-browser-card-main">
        <a data-release-title href="{{ route('details', $row->guid) }}">{{ $row->name }}</a>
        @include('components.release-browser.facts')
        @include('components.release-browser.origin')
    </div>
    <div class="release-browser-card-value"><span>Size</span><b>{{ $row->size }}</b></div>
    <div class="release-browser-card-value"><span>Added</span><b>{{ $row->added }}</b></div>
    <div class="release-browser-card-value"><span>Posted</span><b>{{ $row->posted }}</b></div>
    <div class="release-browser-card-value"><span>Grabs</span><b>{{ number_format($row->grabs) }}</b></div>
    @include('components.release-browser.actions')
</article>
