import { checkboxMenu } from './checkbox-menu-component.js';

/** Apply works once From holds four digits; To may stay empty (From alone is that one year). */
export function yearReady(from, to) {
    return /^\d{4}$/.test(from) && (to === '' || /^\d{4}$/.test(to));
}

/** The range typed, or null when it is not complete, runs outside first–last or has the later year first. */
export function yearRange(from, to, first, last) {
    if (!yearReady(from, to)) return null;
    const start = Number(from), end = to === '' ? start : Number(to);
    return start < first || end > last || start > end ? null : { from: start, to: end };
}

/**
 * The film bar's Year cell (x-year-menu, Movies SPEC 5.2): a checkbox menu of decades (multi-select,
 * "N chosen" for several) above a From / To range. A valid range closes the menu and reads
 * "1980–1989", or "2024" for From alone; a range outside first–last or with the later year first
 * is refused, its message replacing the "Range" heading, and focus returns to From. A range
 * replaces the ticked decades and ticking a decade replaces a range; "Any year" clears both and
 * keeps the menu open. Every change dispatches `checkbox-menu-change` with {params} for the URL.
 */
export function yearMenu() {
    const menu = checkboxMenu();
    return {
        ...menu,

        changed() {
            this.resetRange();
            menu.changed.call(this);
        },

        changeDetail(values) {
            return { params: { decade: values, year_from: '', year_to: '' } };
        },

        clear() {
            const set = this.menuRoot.classList.contains('is-set');
            this.items().forEach(item => item.setAttribute('aria-checked', 'false'));
            if (set) this.changed();
            else this.resetRange();
        },

        /** Keeps digits only and enables Apply once the range is ready. */
        rangeInput(event) {
            const field = event.target;
            if (field.tagName !== 'INPUT') return;
            const digits = field.value.replace(/\D/g, '');
            if (digits !== field.value) field.value = digits;
            this.$refs.apply.disabled = !yearReady(this.$refs.from.value, this.$refs.to.value);
        },

        applyRange() {
            const root = this.menuRoot, from = this.$refs.from.value, to = this.$refs.to.value;
            const range = yearRange(from, to, Number(root.dataset.first), Number(root.dataset.last));
            if (!range) {
                this.showError('Years run ' + root.dataset.first + '–' + root.dataset.last + ', earliest first');
                this.$refs.from.focus();
                return;
            }
            this.showError('');
            this.items().forEach(item => item.setAttribute('aria-checked', 'false'));
            root.querySelector('[data-any]').setAttribute('aria-checked', 'false');
            const text = range.from === range.to ? String(range.from) : range.from + '–' + range.to;
            this.$refs.value.textContent = text;
            this.$refs.value.classList.remove('is-any');
            this.$refs.button.setAttribute('title', root.dataset.label + ': ' + text);
            root.classList.add('is-set');
            root.dispatchEvent(new CustomEvent('checkbox-menu-change', {
                bubbles: true,
                detail: { params: { decade: [], year_from: String(range.from), year_to: range.to === range.from ? '' : String(range.to) } },
            }));
            this.closeAndFocus();
        },

        /** The refusal replaces the "Range" heading in place; an empty message restores it. */
        showError(message) {
            const heading = this.$refs.rangeHeading;
            heading.textContent = message || heading.dataset.heading;
            heading.classList.toggle('is-error', message !== '');
            if (message) heading.setAttribute('role', 'alert');
            else heading.removeAttribute('role');
        },

        resetRange() {
            this.$refs.from.value = '';
            this.$refs.to.value = '';
            this.$refs.apply.disabled = true;
            this.showError('');
        },
    };
}
