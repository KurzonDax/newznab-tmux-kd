@php
    /**
     * The panel of one opened home tile (docs/proposals/home-redesign/SPEC.md 3.3), the home page's `panel` fragment:
     * the title, a show's or film's count, the close button, the releases in the generic lists' row without its
     * select cell, and for a show or film the button to its page. The Category cell reads the sub-category alone
     * inside one section and "Root > Sub" in Following ($mixed).
     *
     * @var array{title: string, count: ?string, rows: list<\App\Data\GenericReleaseRow>, more: array{label: string, url: string}|null} $panel
     * @var \App\Data\GenericListContext $context
     * @var bool $mixed
     */
    $chipBaseId = collect($panel['rows'])->first(fn ($row): bool => $row->hasChips())?->id;
@endphp
<div class="home-panel" data-panel>
    <div class="home-panel-head">
        <h3>{{ $panel['title'] }}</h3>
        @if($panel['count'] !== null)
            <span class="home-panel-count" data-panel-count>{{ $panel['count'] }}</span>
        @endif
        <button type="button" class="tv-action home-panel-close" data-close-panel title="Close (Esc)" aria-label="Close"><i class="fas fa-xmark" aria-hidden="true"></i></button>
    </div>
    @if($panel['rows'] === [])
        <p class="home-panel-none" data-empty>No releases yet.</p>
    @else
        <table @class(['tv-feed is-shelf is-generic is-home-panel', 'is-mixed' => $mixed])>
            <colgroup><col><col class="home-col-category"><col class="tv-col-size"><col class="tv-col-date"><col class="tv-col-actions"></colgroup>
            <thead>
                <tr>
                    <th>Release</th>
                    <th class="tv-category">Category</th>
                    <th class="tv-num">Size</th>
                    <th class="tv-num">Added</th>
                    <th><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                @foreach($panel['rows'] as $row)
                    @include('generic.releases.row', ['row' => $row, 'first' => false, 'chipBaseId' => $chipBaseId, 'originOmits' => null, 'selectable' => false])
                @endforeach
            </tbody>
        </table>
    @endif
    @if($panel['more'] !== null)
        <div class="home-panel-after">
            <a class="tv-details-button is-secondary is-small" href="{{ $panel['more']['url'] }}">{{ $panel['more']['label'] }}<i class="fas fa-arrow-right" aria-hidden="true"></i></a>
        </div>
    @endif
</div>
