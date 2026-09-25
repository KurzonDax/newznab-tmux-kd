/**
 * Multi-select checkbox menu (x-checkbox-menu). Ticking keeps the menu open and focus on the
 * pressed item; the button summary and coral "set" state update in place. Every change
 * dispatches a bubbling `checkbox-menu-change` event with {name, values} for the page.
 */
export function checkboxMenu() {
    return {
        open: false,
        menuRoot: null,

        init() {
            this.menuRoot = this.$el;
        },

        toggle() {
            this.open = !this.open;
        },

        close() {
            this.open = false;
        },

        closeAndFocus() {
            if (!this.open) return;
            this.open = false;
            this.$refs.button.focus();
        },

        focusLeft(event) {
            if (event.relatedTarget && !this.menuRoot.contains(event.relatedTarget)) this.open = false;
        },

        pick(event) {
            const item = event.currentTarget;
            item.setAttribute('aria-checked', item.getAttribute('aria-checked') === 'true' ? 'false' : 'true');
            this.changed();
        },

        clear() {
            if (!this.items().some(item => item.getAttribute('aria-checked') === 'true')) return;
            this.items().forEach(item => item.setAttribute('aria-checked', 'false'));
            this.changed();
        },

        items() {
            return Array.from(this.menuRoot.querySelectorAll('[data-value]'));
        },

        changed() {
            const ticked = this.items().filter(item => item.getAttribute('aria-checked') === 'true');
            const label = this.menuRoot.dataset.label;
            this.menuRoot.querySelector('[data-any]').setAttribute('aria-checked', ticked.length ? 'false' : 'true');
            this.$refs.summary.textContent = label + ': ' + (ticked.length ? ticked.map(item => item.dataset.text).join(', ') : 'any');
            this.menuRoot.classList.toggle('is-set', ticked.length > 0);
            this.menuRoot.dispatchEvent(new CustomEvent('checkbox-menu-change', {
                bubbles: true,
                detail: { name: this.menuRoot.dataset.name, values: ticked.map(item => item.dataset.value) },
            }));
        },
    };
}
