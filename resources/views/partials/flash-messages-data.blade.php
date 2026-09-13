@php
    $legacyMessage = session('message');
    $legacyType = session('message_type');
    $legacyType = is_string($legacyType) ? $legacyType : null;
    $statusMessage = session('status');
    $verificationResent = session('resent') || $statusMessage === 'resent';

    $flashMessages = [
        'success' => session('success')
            ?? session('success_2fa')
            ?? ($verificationResent ? __('A fresh verification link has been sent to your email address.') : $statusMessage)
            ?? ($legacyMessage && $legacyType === 'success' ? $legacyMessage : null)
            ?? ($pageFlashMessages['success'] ?? null),
        'error' => session('error')
            ?? session('error_2fa')
            ?? session('authenticatePasskey::message')
            ?? ($legacyMessage && in_array($legacyType, ['danger', 'error'], true) ? $legacyMessage : null),
        'warning' => session('warning')
            ?? ($legacyMessage && $legacyType === 'warning' ? $legacyMessage : null),
        'info' => session('info')
            ?? ($legacyMessage && ! in_array($legacyType, ['success', 'danger', 'error', 'warning'], true) ? $legacyMessage : null),
    ];

    foreach (session('alerts', []) as $alert) {
        $kind = match ($alert['type']) {
            'success' => 'success',
            'danger', 'error' => 'error',
            'warning' => 'warning',
            default => 'info',
        };
        $existing = $flashMessages[$kind];
        $flashMessages[$kind] = is_array($existing) && array_is_list($existing)
            ? $existing
            : ($existing === null ? [] : [$existing]);
        $flashMessages[$kind][] = $alert['message'];
    }
@endphp

<div id="flash-messages-data" data-messages="{{ json_encode($flashMessages) }}" class="hidden"></div>
