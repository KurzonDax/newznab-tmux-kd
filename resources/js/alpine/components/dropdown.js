/**
 * Document-level delegation for .dropdown-container and .submenu-container
 * elements used by the current header navigation.
 */
(function() {
    var containers = document.querySelectorAll('.dropdown-container');
    containers.forEach(function(container) {
        if (container.hasAttribute('x-data')) return;
        var toggle = container.querySelector('.dropdown-toggle');
        var menu = container.querySelector('.dropdown-menu');
        if (!toggle || !menu) return;
        var closeTimeout;
        menu.style.display = 'none';
        toggle.addEventListener('click', function(ev) {
            ev.preventDefault(); ev.stopPropagation();
            var isOpen = menu.style.display === 'block';
            containers.forEach(function(c) { var m = c.querySelector('.dropdown-menu'); if (m && c !== container) m.style.display = 'none'; });
            menu.style.display = isOpen ? 'none' : 'block';
        });
        container.addEventListener('mouseenter', function() { clearTimeout(closeTimeout); });
        container.addEventListener('mouseleave', function() { closeTimeout = setTimeout(function() { menu.style.display = 'none'; }, 300); });
        menu.addEventListener('mouseenter', function() { clearTimeout(closeTimeout); });
    });
    document.addEventListener('click', function(ev) {
        if (!ev.target.closest('.dropdown-container')) containers.forEach(function(c) { var m = c.querySelector('.dropdown-menu'); if (m) m.style.display = 'none'; });
    });

    // Nested submenus
    document.querySelectorAll('.submenu-container').forEach(function(container) {
        if (container.hasAttribute('x-data')) return;
        var sub = container.querySelector('.submenu');
        if (!sub) return;
        var t;
        container.addEventListener('mouseenter', function() { clearTimeout(t); sub.style.display = 'block'; });
        container.addEventListener('mouseleave', function() { t = setTimeout(function() { sub.style.display = 'none'; }, 200); });
        sub.addEventListener('mouseenter', function() { clearTimeout(t); });
    });
})();
