/**
 * The signed-in header (issue #908): one drop-down per category plus All, the user menu, the search
 * box's suggestions and its app-drawn one-choice scope picker. Opening one of them closes the others;
 * a click elsewhere, Tab out of a menu or Escape closes it, and Escape returns focus to its button.
 */
export const publicNavigation = () => ({
    openMenu: '',
    userOpen: false,
    scopeOpen: false,
    placeholderQuery: null,
    placeholderChanged: null,
    suggestions: [],
    suggestionIndex: -1,
    suggestRequest: null,

    /** Below 1440px the search placeholder drops its "Press /" hint. */
    init() {
        const input = this.$refs.searchInput, full = input.placeholder;
        this.placeholderQuery = window.matchMedia('(max-width: 1440px)');
        this.placeholderChanged = () => { input.placeholder = this.placeholderQuery.matches ? 'Search releases…' : full; };
        this.placeholderChanged();
        this.placeholderQuery.addEventListener('change', this.placeholderChanged);
    },

    get suggestionsOpen() { return this.suggestions.length > 0; },
    get activeSuggestionId() { return this.suggestionIndex >= 0 ? 'search-suggestion-' + this.suggestionIndex : null; },

    async fetchSuggestions() {
        this.suggestRequest?.abort();
        this.suggestions = [];
        this.suggestionIndex = -1;
        const query = this.$refs.searchInput.value.trim();
        if (query.length < 2) return;
        const request = new AbortController();
        this.suggestRequest = request;
        const url = new URL(this.$refs.searchForm.dataset.suggestUrl, window.location.href);
        url.searchParams.set('q', query);
        try {
            const response = await fetch(url, { signal: request.signal, headers: { Accept: 'application/json' } });
            if (!response.ok) return;
            const data = await response.json();
            if (request.signal.aborted || this.suggestRequest !== request || !data.success
                || this.$refs.searchInput.value.trim() !== query) return;
            this.suggestions = (Array.isArray(data.suggestions) ? data.suggestions : [])
                .filter(label => typeof label === 'string').slice(0, 6).map((label, index) => {
                    const link = new URL(this.$refs.searchForm.action, window.location.href);
                    link.searchParams.set('q', label);
                    link.searchParams.set('t', this.$refs.searchScope.value);
                    return { label, url: link.href, id: 'search-suggestion-' + index, index };
                });
        } catch (error) {
            if (error.name !== 'AbortError' && this.suggestRequest === request) this.suggestions = [];
        }
    },
    closeSuggestions() {
        this.suggestRequest?.abort();
        this.suggestions = [];
        this.suggestionIndex = -1;
    },
    searchKey(event) {
        if (event.key === 'Escape') {
            this.closeSuggestions();
            event.stopPropagation();
        } else if (this.suggestionsOpen && ['ArrowDown', 'ArrowUp'].includes(event.key)) {
            event.preventDefault();
            const direction = event.key === 'ArrowDown' ? 1 : -1;
            this.suggestionIndex = Math.max(0, Math.min(this.suggestions.length - 1, this.suggestionIndex + direction));
        } else if (event.key === 'Enter' && this.suggestionIndex >= 0) {
            event.preventDefault();
            this.$refs.searchInput.value = this.suggestions[this.suggestionIndex].label;
            this.closeSuggestions();
            this.$refs.searchForm.requestSubmit();
        }
    },
    destroy() {
        this.suggestRequest?.abort();
        this.placeholderQuery?.removeEventListener('change', this.placeholderChanged);
    },

    toggleMenu(event) {
        const menu = event.currentTarget.dataset.menu;
        this.showMenu(this.openMenu === menu ? '' : menu);
    },
    /** The Down arrow on a button, open or closed, moves into its menu. */
    openMenuAndFocus(event) {
        const menu = event.currentTarget.dataset.menu;
        this.showMenu(menu);
        this.$nextTick(() => document.getElementById(menu)?.querySelector('a')?.focus());
    },
    showMenu(menu) {
        this.openMenu = menu;
        this.userOpen = false;
        this.scopeOpen = false;
        if (menu) this.closeSuggestions();
    },
    dropFocusLeft(event) {
        const drop = event.currentTarget;
        if (event.relatedTarget && !drop.contains(event.relatedTarget) && this.openMenu === drop.dataset.menuDrop) this.openMenu = '';
    },
    /** A click inside the header but outside the open menu or the scope picker closes it. */
    headerClick(event) {
        if (this.openMenu && event.target.closest('[data-menu-drop]')?.dataset.menuDrop !== this.openMenu) this.openMenu = '';
        if (this.scopeOpen && !event.target.closest('[data-search-scope]')) this.scopeOpen = false;
    },
    toggleUser() {
        this.userOpen = !this.userOpen;
        this.openMenu = '';
    },
    closeMenus() {
        this.openMenu = '';
        this.userOpen = false;
        this.scopeOpen = false;
        this.closeSuggestions();
    },

    toggleScope() {
        const open = !this.scopeOpen;
        this.showMenu('');
        this.scopeOpen = open;
        if (open) this.closeSuggestions();
    },
    /** The Down arrow on the scope button opens the picker with focus on the checked option. */
    openScopeAndFocus() {
        this.showMenu('');
        this.scopeOpen = true;
        this.closeSuggestions();
        this.$nextTick(() => {
            const items = this.scopeItems();
            (items.find(item => item.getAttribute('aria-checked') === 'true') ?? items[0])?.focus();
        });
    },
    scopeItems() {
        return Array.from(this.$refs.scopePanel.querySelectorAll('[role="menuitemradio"]'));
    },
    scopeKey(event) {
        const items = this.scopeItems(), index = items.indexOf(document.activeElement);
        const next = { ArrowDown: (index + 1) % items.length, ArrowUp: (index - 1 + items.length) % items.length, Home: 0, End: items.length - 1 }[event.key];
        if (next === undefined) return;
        event.preventDefault();
        items[next].focus();
    },
    pickScope(event) {
        const item = event.currentTarget, label = item.textContent.trim();
        this.scopeItems().forEach(other => other.setAttribute('aria-checked', other === item ? 'true' : 'false'));
        this.$refs.searchScope.value = item.dataset.value;
        this.$refs.scopeLabel.textContent = label;
        this.$refs.scopeTrigger.setAttribute('aria-label', 'Search scope: ' + label);
        this.scopeOpen = false;
        this.$refs.scopeTrigger.focus();
        return this.fetchSuggestions();
    },
    scopeFocusLeft(event) {
        if (event.relatedTarget && !event.currentTarget.contains(event.relatedTarget)) this.scopeOpen = false;
    },
    navigate(event) {
        if (event.target.closest('a')) this.closeMenus();
    },
    handleShortcut(event) {
        if (event.key === 'Escape') {
            if (this.scopeOpen) this.$refs.scopeTrigger.focus();
            else if (this.openMenu) document.querySelector(`[data-menu="${this.openMenu}"]`)?.focus();
            if (this.userOpen) this.$refs.userTrigger.focus();
            this.closeMenus();
        }
        if (Array.from(document.querySelectorAll('[role="dialog"][aria-modal="true"]')).some(dialog => dialog.getClientRects().length)) return;
        if (event.key === '/' && !event.ctrlKey && !event.metaKey && !event.altKey
            && !event.target.closest('input, textarea, select, [contenteditable], [role="dialog"]')) {
            event.preventDefault();
            this.closeMenus();
            this.$refs.searchInput.focus();
        }
    },
});
