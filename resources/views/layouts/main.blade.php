<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="public-shell">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- Apply dark mode BEFORE any CSS loads to prevent white flash --}}
    @include('partials.theme-init')

    <title>{{ $meta_title ?? config('app.name') }}@if(isset($meta_title) && $meta_title !== '' && (($site['metatitle'] ?? '') !== '')) - @endif{{ $site['metatitle'] ?? '' }}</title>

    <meta name="keywords" content="{{ $meta_keywords ?? '' }}">
    <meta name="description" content="{{ $meta_description ?? '' }}">

    <!-- Theme Preference - Set via meta tag for CSP compliance -->
    <meta name="theme-preference" content="{{ $userTheme }}">
    <!-- CSP Nonce for dynamic script loading -->
    <meta name="csp-nonce" content="{{ csp_nonce() }}">
    @auth
        <meta name="user-authenticated" content="true">
        <meta name="update-theme-url" content="{{ route('profile.update-theme') }}">
    @endauth

    <!-- Styles -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('meta')
    @stack('styles')
</head>
<body class="public-ui public-shell font-sans antialiased text-gray-900 dark:text-gray-100">
    @include('partials.header-menu')
    <main class="public-page">
        @yield('content')
        @if(isset($content) && is_string($content))
            {!! $content !!}
        @endif
    </main>
    <footer class="public-footer">@include('partials.footer')</footer>

    <!-- Confirmation Modal (used on many pages) -->
    @include('partials.confirmation-modal', ['publicModal' => true])

    <!-- Toast Notifications (Alpine.js CSP Safe) -->
    @include('partials.toast-notifications', ['publicToasts' => true])

    {{-- Release-specific modals: pushed by pages that show releases --}}
    @auth @include('partials.watchlist-picker') @endauth
    @stack('modals')

    @stack('scripts')

    <!-- Theme Management Data (moved to csp-safe.js) -->
    @php $themePreference = $userTheme; @endphp
    <div id="current-theme-data"
         data-theme="{{ $themePreference }}"
         data-authenticated="{{ $loggedin ? 'true' : 'false' }}"
         data-update-url="{{ route('profile.update-theme') }}"
         class="hidden">
    </div>

    <!-- Flash Messages Data (moved to csp-safe.js) -->
    @include('partials.flash-messages-data')
</body>
</html>
