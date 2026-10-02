@php
    /**
     * The Books, Console and PC details pages' tabs (docs/proposals/books-console-pc-redesign/SPEC.md
     * 5A and 5B): Overview, Files (N), Media info only when the release has media info (absent, not
     * disabled), NFO and Comments (N); the Overview prints the facts and the PreDB block it is given.
     * A poster address breaks before its "@", never inside a word.
     *
     * @var \App\Data\ShelfReleaseRow|\App\Data\ConsoleReleaseRow $row
     * @var list<array{string, string}> $facts
     * @var list<array{string, string}> $predb
     */
    $tabs = ['overview' => 'Overview', 'files' => $row->hasFileCount() ? 'Files ('.$row->filesShown().')' : 'Files'];
    if ($row->mediaInfo !== null) {
        $tabs['media'] = 'Media info';
    }
    $tabs += ['nfo' => 'NFO', 'comments' => 'Comments ('.$comments->total().')'];
@endphp
<div class="tv-details-tabs" role="tablist" aria-label="Release details">
    @foreach($tabs as $key => $label)
        <button type="button" role="tab" id="tab-{{ $key }}" data-tab="{{ $key }}" aria-controls="{{ $key }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}" tabindex="{{ $loop->first ? '0' : '-1' }}"
                @if($loop->index < 2) data-part="{{ $loop->first ? 'tab, current' : 'tab' }}" @endif>{{ $label }}</button>
    @endforeach
</div>
<section id="overview" class="tv-details-panel" role="tabpanel" aria-labelledby="tab-overview" data-details-panel>
    <dl class="tv-details-facts">
        @foreach($facts as [$label, $value])
            <div><dt @if($loop->first) data-part="facts label" @endif>{{ $label }}</dt><dd @if($loop->first) data-part="facts value" @endif>@if($label === 'Poster'){!! str_replace('@', '<wbr>@', e($value)) !!}@else{{ $value }}@endif</dd></div>
        @endforeach
    </dl>
    @if($predb !== [])
        <section class="tv-details-predb" aria-labelledby="predb-heading">
            <h3 id="predb-heading">PreDB</h3>
            <dl class="tv-details-facts">
                @foreach($predb as [$label, $value])
                    <div @class(['is-wide' => $label === 'Title'])><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
                @endforeach
            </dl>
        </section>
    @endif
</section>
<section id="files" class="tv-details-panel" role="tabpanel" aria-labelledby="tab-files" data-details-panel hidden>
    <div data-tab-content="files"><p class="tv-note">Loading the file list…</p></div>
</section>
@if($row->mediaInfo !== null)
    <section id="media" class="tv-details-panel" role="tabpanel" aria-labelledby="tab-media" data-details-panel hidden>
        <div data-tab-content="media"><p class="tv-note">Loading media info…</p></div>
    </section>
@endif
<section id="nfo" class="tv-details-panel" role="tabpanel" aria-labelledby="tab-nfo" data-details-panel hidden>
    <div data-tab-content="nfo">@if($row->nfo)<p class="tv-note">Loading the NFO…</p>@else<p class="tv-note">No NFO was posted with this release.</p>@endif</div>
</section>
<section id="comments" class="tv-details-panel tv-details-comments" role="tabpanel" aria-labelledby="tab-comments" data-details-panel hidden>
    @include('details.partials.comments')
</section>
