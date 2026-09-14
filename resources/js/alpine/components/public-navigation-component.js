export const publicNavigation = () => ({
    browseOpen: false,
    userOpen: false,
    suggestions: [],
    suggestionIndex: -1,
    suggestRequest: null,

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
    destroy() { this.suggestRequest?.abort(); },

    toggleBrowse() {
        this.browseOpen = !this.browseOpen;
        this.userOpen = false;
    },
    toggleUser() {
        this.userOpen = !this.userOpen;
        this.browseOpen = false;
    },
    closeMenus() {
        this.browseOpen = false;
        this.userOpen = false;
        this.closeSuggestions();
    },
    navigate(event) {
        if (event.target.closest('a')) this.closeMenus();
    },
    handleShortcut(event) {
        if (event.key === 'Escape') {
            if (this.browseOpen) this.$refs.browseTrigger.focus();
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
