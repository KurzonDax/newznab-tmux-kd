{{--
    The admin's Delete confirmation on the Adult list and an Adult release's Similar releases (docs/proposals/adult-redesign/SPEC.md
    5.11) in the redesign's dialog frame, 560 px: the release name in full in its box, what goes with it, then Cancel, which takes
    the focus, and the red Delete. A row's trashcan (data-delete-release) opens it through releaseDeleteDialog, which posts the
    existing admin delete and hands the deleted row to the screen (release-deleted).
--}}
<div x-data="releaseDeleteDialog">
    <x-tv-dialog name="release-delete" class="is-release-delete" aria-describedby="release-delete-name release-delete-note">
        <x-slot:title>Delete this release?</x-slot:title>
        <p class="tv-delete-name" id="release-delete-name" x-text="name"></p>
        <p class="tv-delete-note" id="release-delete-note">This permanently removes the NZB, its pictures and clip, and everything linked to it. It can’t be undone.</p>
        <div class="tv-dialog-actions">
            <button type="button" class="tv-details-button is-secondary" x-ref="cancel" x-on:click="close">Cancel</button>
            <button type="button" class="tv-details-button is-danger" x-on:click="deleteRelease" x-bind:disabled="busy"><i class="fas fa-trash" aria-hidden="true"></i>Delete</button>
        </div>
    </x-tv-dialog>
</div>
