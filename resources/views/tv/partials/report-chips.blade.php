{{--
    The Reported and Response chips (docs/proposals/generic-release-lists/SPEC.md 5.5, 6): "Reported" / "Reported (N)" with a
    flag and "Response" with a reply arrow, each in its own hue, linking to the release page ($href) or plain on the release
    page itself ($href null). $chipPart names each chip's data-part, or returns null; the row carries reports and publicResponses.
--}}
@if($row->reports > 0)
    <x-chip variant="reported" icon="fas fa-flag" :href="$href" data-report-summary :data-part="$chipPart('Reported chip')" :title="$href === null ? 'The reports are on this page' : 'Open the report on the release page'">{{ $row->reportedLabel() }}</x-chip>
@endif
@if($row->publicResponses > 0)
    <x-chip variant="response" icon="fas fa-reply" :href="$href" data-public-response :data-part="$chipPart('Response chip')" :title="$href === null ? 'A staff response is on this page' : 'A staff response is on the release page'">Response</x-chip>
@endif
