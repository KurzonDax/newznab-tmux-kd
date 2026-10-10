{{--
    The administrator's blacklist confirmation (docs/proposals/generic-release-lists/SPEC.md 5.8) in the redesign's
    dialog frame, 680 px: the exact Regex and Group scope in monospace boxes, the Rule and Description, the
    remove-releases option naming the poster's count, Cancel and Confirm blacklist. It posts the same fields as before:
    name, preview_token and delete_releases. Opened and closed by posterIdentityBlacklist (Escape, the close button, a
    click outside and Cancel close it without a rule).
--}}
<x-tv-dialog name="blacklist" class="is-blacklist">
    <x-slot:title>Blacklist this poster</x-slot:title>
    <x-slot:subtitle>Confirm the exact rule that will be saved.</x-slot:subtitle>
    <form method="POST" action="{{ route('admin.poster-identity.blacklist') }}" data-blacklist-form>
        @csrf
        <input type="hidden" name="name" value="{{ $posterIdentity }}">
        <input type="hidden" name="preview_token" value="{{ $blacklistPreviewToken }}">
        <dl class="tv-rule-facts">
            <div><dt>Regex</dt><dd class="is-code">{{ $blacklistPreview['regex'] }}</dd></div>
            <div><dt>Rule</dt><dd>Posted By · Type: Black · Status: enabled</dd></div>
            <div><dt>Group scope</dt><dd class="is-code">{{ $blacklistPreview['groupname'] }}</dd></div>
            <div><dt>Description</dt><dd>{{ $blacklistPreview['description'] }}</dd></div>
        </dl>
        <label class="tv-danger-option">
            <input type="checkbox" name="delete_releases" value="1" x-model="deleteReleases">
            <span>Also permanently remove this poster’s {{ number_format($total) }} existing {{ \Illuminate\Support\Str::plural('release', $total) }} now</span>
        </label>
        <div class="tv-dialog-actions">
            <button type="button" class="tv-details-button is-secondary" x-on:click="close">Cancel</button>
            <button type="submit" class="tv-details-button is-danger"><i class="fas fa-ban" aria-hidden="true"></i>Confirm blacklist</button>
        </div>
    </form>
</x-tv-dialog>
