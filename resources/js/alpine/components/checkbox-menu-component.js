/**
 * Checkbox menu (x-checkbox-menu), on its own as a pill or as a cell of the filter bar
 * (x-filter-bar). Ticking keeps the menu open and focus on the pressed item; the value
 * ("A, B", or "2 chosen" for a counted menu, "any" when nothing is ticked) and the "set" state
 * update in place. A one-choice menu (Completion) has radio items: picking closes it and the
 * cell reads the item's short text. A long menu has a search field that narrows the items in
 * place, survives ticking and is forgotten when the menu closes; a menu opens scrolled to its
 * first ticked item. Every change dispatches a bubbling `checkbox-menu-change` event with
 * {name, values, single} for the page.
 */
export function checkboxMenu() {
    return {
        open: false,
        menuRoot: null,

        init() {
            this.menuRoot = this.$el;
        },

        toggle() {
            this.setOpen(!this.open);
            if (!this.open) return;
            this.$nextTick(() => {
                this.place();
                this.scrollToTicked();
                this.$refs.search?.focus();
            });
        },

        setOpen(open) {
            this.open = open;
            this.menuRoot.classList.toggle('is-open', open);
            if (!open) this.forgetSearch();
        },

        /** A left-anchored (fixed-width or bar cell) menu shifts left rather than run past the window. */
        place() {
            const panel = this.$refs.panel;
            const classes = this.menuRoot.classList;
            if (!panel || !(classes.contains('is-fixed') || classes.contains('is-cell'))) return;
            panel.style.left = '';
            const over = panel.getBoundingClientRect().right - (document.documentElement.clientWidth - 16);
            if (over > 0) panel.style.left = -over + 'px';
        },

        /** Opens with the first ticked item in view, just under the search field. */
        scrollToTicked() {
            const panel = this.$refs.panel;
            const first = this.items().find(item => item.getAttribute('aria-checked') === 'true');
            if (!panel || !first || first.offsetTop + first.offsetHeight <= panel.clientHeight) return;
            panel.scrollTop = first.offsetTop - (this.$refs.search?.parentElement.offsetHeight ?? 0) - 6;
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

        /** Hides the items whose text does not hold the search text; "Any …" always shows. */
        narrow() {
            const text = (this.$refs.search?.value ?? '').trim().toLowerCase();
            this.items().forEach(item => { item.hidden = text !== '' && !item.dataset.text.toLowerCase().includes(text); });
        },

        forgetSearch() {
            if (!this.$refs.search) return;
            this.$refs.search.value = '';
            this.items().forEach(item => { item.hidden = false; });
        },

        pick(event) {
            const item = event.currentTarget;
            if (this.single()) {
                const was = item.getAttribute('aria-checked') === 'true';
                this.items().forEach(other => other.setAttribute('aria-checked', other === item ? 'true' : 'false'));
                if (!was) this.changed();
                this.closeAndFocus();
                return;
            }
            item.setAttribute('aria-checked', item.getAttribute('aria-checked') === 'true' ? 'false' : 'true');
            this.changed();
        },

        clear() {
            const ticked = this.items().some(item => item.getAttribute('aria-checked') === 'true');
            this.items().forEach(item => item.setAttribute('aria-checked', 'false'));
            if (ticked) this.changed();
            if (this.single()) this.closeAndFocus();
        },

        single() {
            return this.menuRoot.dataset.single === 'true';
        },

        items() {
            return Array.from(this.menuRoot.querySelectorAll('[data-value]'));
        },

        changed() {
            const ticked = this.items().filter(item => item.getAttribute('aria-checked') === 'true');
            const label = this.menuRoot.dataset.label, texts = ticked.map(item => item.dataset.text);
            const counted = this.menuRoot.dataset.summary === 'count';
            const reads = !ticked.length ? 'any'
                : ticked.length === 1 ? (ticked[0].dataset.short ?? texts[0])
                : counted ? ticked.length + ' chosen' : texts.join(', ');
            this.menuRoot.querySelector('[data-any]').setAttribute('aria-checked', ticked.length ? 'false' : 'true');
            this.$refs.value.textContent = reads;
            this.$refs.value.classList.toggle('is-any', !ticked.length);
            if (counted) this.$refs.button.setAttribute('title', texts.length ? label + ': ' + texts.join(', ') : '');
            this.menuRoot.classList.toggle('is-set', ticked.length > 0);
            this.menuRoot.dispatchEvent(new CustomEvent('checkbox-menu-change', {
                bubbles: true,
                detail: { name: this.menuRoot.dataset.name, values: ticked.map(item => item.dataset.value), single: this.single() },
            }));
        },
    };
}
