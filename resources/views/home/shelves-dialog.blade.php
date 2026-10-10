{{--
    The Shelves dialog (docs/proposals/home-redesign/SPEC.md 4): the shelves the user may view, in the user's order.
    Each row is a checkbox button beside a grip, never a checkbox that contains a button. A tick saves at once;
    reordering is drag and drop on the grip (pointer, or Space / arrow keys / Space, Escape puts the row back), never
    arrow buttons. Opened, saved and reordered by homeShelves.
--}}
<x-tv-dialog name="shelves" class="is-shelves" close="closeDialog()">
    <x-slot:title>Shelves</x-slot:title>
    <x-slot:subtitle>Tick the shelves you want and put them in order.</x-slot:subtitle>
    <div class="home-shelf-rows" data-drag-zone>
        <h3>Shelves, in the order they appear · drag to reorder</h3>
        @foreach($shelfRows as $row)
            <div class="home-shelf-row" data-key="{{ $row['shelf']->value }}">
                <button type="button" class="home-shelf-check" role="checkbox" aria-checked="{{ $row['ticked'] ? 'true' : 'false' }}" data-shelf-tick>
                    <span class="home-shelf-box"><i class="fas fa-check" aria-hidden="true"></i></span>
                    <span class="home-shelf-label">{{ $row['shelf']->value }}<small>{{ $row['shelf']->description() }}</small></span>
                </button>
                <span class="home-grip" data-grip tabindex="0" role="button" aria-pressed="false" aria-label="Drag to reorder {{ $row['shelf']->value }}" title="Drag to reorder"><i class="fas fa-grip-vertical" aria-hidden="true"></i></span>
            </div>
        @endforeach
    </div>
</x-tv-dialog>
