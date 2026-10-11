{{--
    The Imported chip (issue #1049), the last of a release's own chips on every chip line: an icon with no text on a
    release added from an imported NZB. It reports and opens nothing; its sentence is the hover title and, the chip
    having no text, its accessible name. $row carries `imported` (a release row, or the older browser's row data).
    It names no data-part: the prototypes the parts are measured against have no such chip, and it never stands in
    for the page's "chip base".
--}}
@if($row->imported)
    <x-chip variant="imported" icon="fas fa-right-to-bracket" role="img"
            title="This release was added from an imported NZB" aria-label="This release was added from an imported NZB" />
@endif
