import { modalLifecycle } from './modal-lifecycle.js';
import { copyText } from './tv-row-actions.js';

/**
 * A search's "+ Add filter" menu (search/filter-menu.blade.php): the list of filter kinds, and
 * the form of the one picked under a back row. It closes on Escape (focus returns to the button),
 * on a click outside and when focus leaves it, and always opens on its list, never on the form it
 * was closed on. The Sub-category form's search field narrows its choices in place.
 */
export function searchFilters() {
    return {
        open: false,
        kind: '',
        menuRoot: null,

        init() {
            this.menuRoot = this.$el;
        },

        get onList() {
            return this.kind === '';
        },

        toggle() {
            if (this.open) this.close();
            else this.setOpen(true);
        },

        setOpen(open) {
            this.open = open;
            this.kind = '';
            this.menuRoot.classList.toggle('is-open', open);
            this.forgetSearch();
        },

        close() {
            if (this.open) this.setOpen(false);
        },

        closeAndFocus() {
            if (!this.open) return;
            this.setOpen(false);
            this.$refs.button.focus();
        },

        focusLeft(event) {
            if (event.relatedTarget && !this.menuRoot.contains(event.relatedTarget)) this.close();
        },

        /** A kind is picked: its form takes the list's place and its first field takes focus. */
        pick(event) {
            const kind = event.currentTarget.dataset.kind;
            this.kind = kind;
            this.focusSoon(() => this.form(kind)?.querySelector('input:not([type="hidden"])'));
        },

        /** The back row: the list returns, with focus on the kind that was open. */
        back() {
            const kind = this.kind;
            this.kind = '';
            this.forgetSearch();
            this.focusSoon(() => this.menuRoot.querySelector('[data-kind="' + kind + '"]'));
        },

        /** Focuses what `find` returns once it is drawn: x-show reveals an element some time after its flag changes. */
        focusSoon(find, tries = 30) {
            const attempt = () => {
                if (!this.open) return;
                const target = find();
                if (target?.getClientRects().length) target.focus();
                else if (tries-- > 0) setTimeout(attempt, 16);
            };
            this.$nextTick(attempt);
        },

        form(kind) {
            return this.menuRoot.querySelector('[data-filter-form="' + kind + '"]');
        },

        choices() {
            return Array.from(this.menuRoot.querySelectorAll('[data-sub-category]'));
        },

        /** Hides the sub-categories whose "Root > Sub" does not hold the search text. */
        narrow(event) {
            const text = event.target.value.trim().toLowerCase();
            this.choices().forEach(item => { item.hidden = text !== '' && !item.dataset.text.toLowerCase().includes(text); });
        },

        forgetSearch() {
            const field = this.menuRoot.querySelector('[data-filter-search]');
            if (!field) return;
            field.value = '';
            this.choices().forEach(item => { item.hidden = false; });
        },
    };
}

/**
 * The "RSS for this search" dialog (search/feed-dialog.blade.php). The heading row's button opens
 * it with a `search-feed-open` event; Copy puts the feed URL on the clipboard and the button reads
 * "Copied" for a moment.
 */
export function searchFeed() {
    return {
        ...modalLifecycle(),
        open: false,
        copiedTimer: null,

        init() {
            this.initModal();
        },

        openFeed() {
            this.open = true;
        },

        close() {
            this.open = false;
            this.showCopied(false);
        },

        async copyFeed() {
            if (!await copyText(this.$refs.feedUrl.textContent.trim())) {
                window.showToast('Could not copy the feed URL. Select it and copy it by hand.', 'error');
                return;
            }
            this.showCopied(true);
            this.copiedTimer = setTimeout(() => this.showCopied(false), 2000);
        },

        showCopied(copied) {
            clearTimeout(this.copiedTimer);
            this.$refs.copy.classList.toggle('is-copied', copied);
        },

        destroy() {
            clearTimeout(this.copiedTimer);
            this._modalTeardown?.();
        },
    };
}
