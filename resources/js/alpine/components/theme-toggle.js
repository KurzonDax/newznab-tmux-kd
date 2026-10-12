/**
 * Alpine.data('themeToggle') - Theme toggle button (cycles light/dark/system)
 * Used on the header theme button.
 */
import Alpine from '@alpinejs/csp';

Alpine.data('themeToggle', () => ({
    cycle() {
        Alpine.store('theme').cycle();
    }
}));

// Document-level delegation for #theme-toggle button without x-data
(function() {
    var themeToggle = document.getElementById('theme-toggle');
    if (themeToggle && !themeToggle.closest('[x-data]')) {
        themeToggle.addEventListener('click', function() {
            Alpine.store('theme').cycle();
        });
    }
})();
