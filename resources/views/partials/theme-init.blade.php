{{--
    Dark mode initialization - MUST be included at the very top of <head>,
    BEFORE any CSS/Vite tags, to prevent white flash on page load.
    1. The blocking script applies the 'dark' class and data-loading to <html> synchronously.
    2. The style tag covers background, text color, color-scheme, x-cloak hiding, and transition
       suppression so the first paint matches the user's theme with zero flash.
--}}
<script nonce="{{ csp_nonce() }}">
(function() {
    var d = document.documentElement;
    d.setAttribute('data-loading', '');
    @if(empty($localThemeOnly) && auth()->check())
        var t = '{{ auth()->user()->theme_preference ?? "light" }}';
    @else
        var t = localStorage.getItem('theme') || 'light';
    @endif
    var isDark = t === 'dark' || (t === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
    if (isDark) {
        d.classList.add('dark');
    }
})();
</script>
<style nonce="{{ csp_nonce() }}">
[x-cloak] { display: none !important; }
html[data-loading], html[data-loading] *, html[data-loading] *::before, html[data-loading] *::after { transition: none !important; }
html { color-scheme: light; }
html.dark { color-scheme: dark; }
html, body { background-color: #f5f5f2; color: #17181c; }
html.dark, html.dark body { background-color: #0f1014; color: #f4f4f6; }
</style>
