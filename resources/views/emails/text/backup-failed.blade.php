{{ $operation }} failed
{{ str_repeat('=', max(strlen($operation) + 7, 30)) }}

{{ $offsite ? 'No incomplete copy was published; a temporary partial may be retained for the next run.' : 'No incomplete backup was kept.' }}

  Operation:  {{ $operation }}
@if ($setId)
  Backup set: {{ $setId }}
@endif
  Error:      {{ $error }}

View database backups: {{ $backupsUrl }}

— {{ $site }} Backups
