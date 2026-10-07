{{-- Listen (audio-redesign SPEC 5.10), last in the chip line: opens the Listen dialog, which plays the preview at once. The Audio list and the details pages' tables both print it; $chipPart names its data-part, or returns null. --}}
<x-chip variant="clip" action class="listen-badge" :data-guid="$row->guid" :data-release-display-name="$row->name" :data-audio-url="$row->listen['url']"
        :data-audio-type="$row->listen['type']" :data-audio-title="$row->listen['title']" :data-audio-artist="$row->listen['artist']"
        :data-audio-seconds="$row->listen['seconds']" :data-part="$chipPart('Listen chip')" :title="$row->listenTitle()">Listen</x-chip>
