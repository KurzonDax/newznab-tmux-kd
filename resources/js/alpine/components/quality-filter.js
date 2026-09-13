/**
 * Alpine.data('qualityFilter') - Resolution and source filter buttons
 * CSP-safe: Uses click handler with data attributes instead of inline expressions
 */
import Alpine from '@alpinejs/csp';

Alpine.data('qualityFilter', () => ({
    activeResolution: 'all',
    activeSource: 'all',
    totalReleases: 0,
    visibleCount: 0,

    init() {
        this.totalReleases = this.$el.querySelectorAll('.release-item').length;
        this.visibleCount = this.totalReleases;

        // Set up click handlers via event delegation
        this.$el.addEventListener('click', (e) => {
            const resBtn = e.target.closest('[data-resolution]');
            if (resBtn) {
                const filter = resBtn.getAttribute('data-resolution');
                if (filter) {
                    this.activeResolution = filter;
                    this._updateButtonStyles();
                    this._applyFilters();
                }
                return;
            }

            const srcBtn = e.target.closest('[data-source]');
            if (srcBtn) {
                const filter = srcBtn.getAttribute('data-source');
                if (filter) {
                    this.activeSource = filter;
                    this._updateButtonStyles();
                    this._applyFilters();
                }
            }
        });

        // Initial button styles
        this._updateButtonStyles();
    },

    /**
     * Update button active/inactive styles
     */
    _updateButtonStyles() {
        const activeClasses = ['bg-primary-600', 'dark:bg-primary-700', 'text-white', 'hover:bg-primary-700', 'dark:hover:bg-primary-800'];
        const inactiveClasses = ['bg-gray-200', 'dark:bg-gray-700', 'text-gray-700', 'dark:text-gray-300', 'hover:bg-gray-300', 'dark:hover:bg-gray-600'];

        for (const [attribute, selected] of [['resolution', this.activeResolution], ['source', this.activeSource]]) {
            this.$el.querySelectorAll(`[data-${attribute}]`).forEach(button => {
                const isActive = button.getAttribute(`data-${attribute}`) === selected;
                button.classList.remove(...activeClasses, ...inactiveClasses);
                button.classList.add(...(isActive ? activeClasses : inactiveClasses));
            });
        }
    },

    countText() {
        if (this.activeResolution === 'all' && this.activeSource === 'all') return '(' + this.totalReleases + ' total)';
        return '(' + this.visibleCount + ' of ' + this.totalReleases + ')';
    },

    _applyFilters() {
        let visible = 0;
        this.$el.querySelectorAll('.release-item').forEach(item => {
            const name = (item.getAttribute('data-release-name') || '').toLowerCase();
            let matchR = true, matchS = true;

            if (this.activeResolution !== 'all') matchR = name.includes(this.activeResolution.toLowerCase());
            if (this.activeSource !== 'all') {
                const s = this.activeSource.toLowerCase();
                if (s === 'bluray') matchS = name.includes('bluray') || name.includes('blu-ray') || name.includes('bdrip') || name.includes('brrip');
                else if (s === 'web-dl') matchS = name.includes('web-dl') || name.includes('webdl') || name.includes('web.dl');
                else if (s === 'webrip') matchS = name.includes('webrip') || name.includes('web-rip') || name.includes('web.rip');
                else matchS = name.includes(s);
            }

            if (matchR && matchS) {
                item.style.removeProperty('display');
                item.classList.remove('hidden');
                visible++;
            } else {
                item.style.setProperty('display', 'none', 'important');
            }
        });
        this.visibleCount = visible;
    }
}));
