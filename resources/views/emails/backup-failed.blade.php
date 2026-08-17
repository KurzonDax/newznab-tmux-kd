<x-mail.layout :title="$operation.' failed'" :preheader="$preheader" :site-name="$site">
    <x-mail.alert type="danger">
        <strong>{{ $operation }} failed.</strong>
        {{ $offsite ? 'No incomplete copy was published; a temporary partial may be retained for the next run.' : 'No incomplete backup was kept.' }}
    </x-mail.alert>

    <x-mail.status-table>
        <tr>
            <td class="status-label">Operation</td>
            <td>{{ $operation }}</td>
        </tr>
        @if ($setId)
            <tr>
                <td class="status-label">Backup set</td>
                <td>{{ $setId }}</td>
            </tr>
        @endif
        <tr>
            <td class="status-label">Error</td>
            <td>{{ $error }}</td>
        </tr>
    </x-mail.status-table>

    <x-mail.button :url="$backupsUrl" color="primary">
        View database backups
    </x-mail.button>

    <div class="signature">
        <p>— {{ $site }} Backups</p>
    </div>
</x-mail.layout>
