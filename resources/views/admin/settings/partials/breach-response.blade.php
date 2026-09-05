{{--
    Rendered beside the Sessions & access card. It is its own form because it posts to the
    login-session controller rather than to the settings save path.
--}}
<x-panel variant="alt" padding="sm">
    <h3 class="font-semibold text-gray-900 dark:text-gray-100">
        <i class="fas fa-triangle-exclamation mr-1 text-red-600 dark:text-red-400" aria-hidden="true"></i>Breach response
    </h3>
    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
        Expire every Web Login Session, Remembered Login, and 2FA Trusted Device. Your current
        admin session is the only session spared.
    </p>

    <form method="post" action="{{ route('admin.login-sessions.expire-all') }}" class="mt-3">
        @csrf
        <x-button
            type="submit"
            variant="danger"
            icon="fas fa-right-from-bracket"
            data-confirm="Expire all web logins and trusted devices? Only this admin session will remain signed in."
        >Expire All Logins</x-button>
    </form>
</x-panel>
