@php
    /**
     * One row of the generic release lists (docs/proposals/generic-release-lists/SPEC.md 5.4, 5.5): the name; the
     * entity line of a film or show (a link) or of an album or console game (plain text); the chip line (the release
     * chips with Preview / Sample / Clip as the Adult list prints them, then Reported and Response, then the group and
     * poster pair with the list's own context left off); the Category cell ("Root > Sub", the sub-category alone on
     * Other); Size; the date; the 2 × 2 actions with Follow on a film or show row and an empty slot otherwise.
     *
     * @var \App\Data\GenericReleaseRow $row
     * @var \App\Data\GenericListContext $context
     * @var 'group'|'poster'|null $originOmits
     * @var bool|null $selectable false leaves the select cell out (the home page's panels); the lists never pass it
     */
    $details = route('details', $row->guid);
    // The first chip on the page is measured as "chip base"; every other chip by its kind (the prototype comparison).
    $chipBaseTaken = false;
    $chipPart = function (string $name) use ($row, $chipBaseId, &$chipBaseTaken): string {
        if ($row->id === $chipBaseId && ! $chipBaseTaken) {
            $chipBaseTaken = true;

            return 'chip base';
        }

        return $name;
    };
    $followNoun = $row->followRoot === 'tv' ? 'show' : 'film';
@endphp
<tr data-release-row>
    @if($selectable ?? true)<td @if($first) data-part="release row cell" @endif><input type="checkbox" data-select value="{{ $row->guid }}" aria-label="Select {{ $row->name }}"></td>@endif
    <td class="tv-what">
        <a class="tv-release-name" href="{{ $details }}" title="{{ $row->name }}" data-part="release name">{{ $row->name }}</a>
        @if($row->hasEntityLine())
            @if($row->entityUrl !== null)
                <a class="tv-show-line" href="{{ $row->entityUrl }}" title="{{ $row->entityKind === 'film' ? 'Go to the film' : 'Go to the show' }}" data-entity="{{ $row->entityKind }}" data-part="show line under the name">{{ $row->entityLine }}</a>
            @else
                <span class="tv-game-line" data-entity="{{ $row->entityKind }}">{{ $row->entityLine }}</span>
            @endif
        @endif
        @if($row->hasChips() || $row->hasOrigin())
            <div class="tv-chips">
                @include('tv.partials.release-chip-list', ['clip' => $row->clip])
                @if($row->listen !== null)
                    @include('shelf.releases.listen-chip')
                @endif
                @include('tv.partials.report-chips', ['href' => $details])
                @include('tv.partials.release-origin', ['originOmits' => $originOmits])
            </div>
        @endif
    </td>
    <td class="tv-category" title="{{ $row->categoryPath }}">{{ $context->isOther() ? $row->category : $row->categoryPath }}</td>
    <td class="tv-num tv-size">{{ $row->size }}</td>
    <td class="tv-num tv-date" title="{{ $row->dateTitle }}">{{ $row->date }}</td>
    <td>
        <div class="tv-actions">
            <a href="{{ route('getnzb.guid', $row->guid) }}" class="tv-action tv-action-download download-nzb" title="Download NZB" aria-label="Download NZB" data-part="row action: download"><i class="fas fa-download" aria-hidden="true"></i></a>
            <button type="button" class="tv-action" data-copy-nzb="{{ $row->guid }}" title="Copy NZB link for SABnzbd or NZBGet" aria-label="Copy NZB link for SABnzbd or NZBGet" data-part="row action button"><i class="fas fa-link" aria-hidden="true"></i></button>
            <button type="button" class="tv-action" data-cart="{{ $row->guid }}" aria-pressed="{{ $row->inCart ? 'true' : 'false' }}" title="{{ $row->inCart ? 'In cart · click to remove' : 'Add to cart' }}" aria-label="{{ $row->inCart ? 'Remove from cart' : 'Add to cart' }}"><i class="fas fa-cart-shopping" aria-hidden="true"></i></button>
            @if($row->canFollow())
                <button type="button" class="tv-action" data-watch-picker="{{ route('watchlist.picker', ['root' => $row->followRoot, 'id' => $row->followId]) }}" data-watch-key="{{ $row->followRoot }}:{{ $row->followId }}" data-watch-title="{{ $row->followTitle }}" data-watched="{{ $row->watched ? '1' : '0' }}"
                        data-watch-off-title="Follow this {{ $followNoun }}" data-watch-on-title="Following this {{ $followNoun }} · click to unfollow" data-watch-off-aria="Follow {{ $row->followTitle }}" data-watch-on-aria="Unfollow {{ $row->followTitle }}"
                        title="{{ $row->watched ? 'Following this '.$followNoun.' · click to unfollow' : 'Follow this '.$followNoun }}" aria-label="{{ $row->watched ? 'Unfollow ' : 'Follow ' }}{{ $row->followTitle }}"><i class="{{ $row->watched ? 'fas' : 'far' }} fa-bookmark" aria-hidden="true"></i></button>
            @else
                <span class="tv-action tv-action-slot" aria-hidden="true"></span>
            @endif
        </div>
    </td>
</tr>
