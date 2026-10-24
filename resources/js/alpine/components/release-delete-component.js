import { modalLifecycle } from './modal-lifecycle.js';
import { postJson } from './tv-list.js';

/**
 * The pager line's "Showing X–Y of N releases" after one release left the page, by the server's own rule
 * (components/pager-line.blade.php): N drops by one, Y becomes min(Y, N), X stays, with number_format's
 * separators and "release" at N = 1. Any other text is returned as it is.
 */
export function summaryAfterDelete(text) {
    const match = /^Showing ([\d,]+)–([\d,]+) of ([\d,]+) /.exec(text);
    if (!match) return text;
    const [from, to, total] = match.slice(1).map(value => Number(value.replaceAll(',', '')));
    const left = total - 1, shown = number => number.toLocaleString('en-US');
    return 'Showing ' + shown(from) + '–' + shown(Math.min(to, left)) + ' of ' + shown(left) + ' ' + (left === 1 ? 'release' : 'releases');
}

/**
 * Takes a deleted release's row out of its table and returns the trashcan of the row now in its place (the last
 * row's when it was last), or null when the table has no row left.
 */
export function takeOutRow(row) {
    const rows = Array.from(row.parentElement.children);
    const index = rows.indexOf(row);
    row.remove();
    const left = rows.filter(other => other !== row);
    return left[Math.min(index, left.length - 1)]?.querySelector('[data-delete-release]') ?? null;
}

/**
 * The admin's Delete confirmation on the Adult list and an Adult release's Similar releases
 * (docs/proposals/adult-redesign/SPEC.md 5.11, adult/releases/delete-dialog.blade.php). A row's trashcan
 * (data-delete-release, the admin delete's URL; data-release-name) opens it naming the release, with Cancel
 * focused once the dialog is on screen. Escape, the close button, a click outside and Cancel close it sending nothing (modalLifecycle returns
 * the focus to the trashcan). Delete posts once; while the request is in flight nothing closes the dialog or sends
 * again, as the server answers success for a release already gone. Any answer but JSON success: true closes it with
 * an error toast and the row kept. On success the dialog closes, the release leaves the admin's cart count when its
 * row's Cart was pressed, the row is handed to the screen in a release-deleted event (the list or the details page
 * takes it out and sets the focus, the trashcan being gone), and a toast names the release.
 */
export function releaseDeleteDialog() {
    return {
        ...modalLifecycle(),
        open: false,
        busy: false,
        name: '',
        url: '',
        trigger: null,

        init() {
            this.initModal();
            this._click = event => {
                const trigger = event.target.closest('[data-delete-release]');
                if (trigger) this.show(trigger);
            };
            document.addEventListener('click', this._click);
        },

        destroy() {
            this._modalTeardown?.();
            document.removeEventListener('click', this._click);
        },

        show(trigger) {
            if (this.open) return;
            this.trigger = trigger;
            this.url = trigger.dataset.deleteRelease;
            this.name = trigger.dataset.releaseName ?? '';
            // the trashcan has the focus to return to, whatever the browser does with a clicked button
            trigger.focus();
            this.open = true;
            // modalLifecycle focuses the close button from its own $nextTick; Cancel takes the focus one tick after that
            this.$nextTick(() => this.$nextTick(() => this.focusCancel(20)));
        },

        /** Cancel takes the focus once it is on screen: x-show reveals the dialog an animation frame after `open` is set. */
        focusCancel(tries) {
            const cancel = this.$refs.cancel;
            if (!this.open || !cancel) return;
            if (cancel.getClientRects().length) cancel.focus();
            else if (tries > 0) setTimeout(() => this.focusCancel(tries - 1), 16);
        },

        close() {
            if (this.busy) return;
            this.open = false;
        },

        async deleteRelease() {
            if (this.busy || !this.open) return;
            this.busy = true;
            let deleted = false;
            try {
                deleted = (await postJson(this.url, {}))?.success === true;
            } catch {
                // a refused, two-factor or failed request is a failed delete
            }
            this.busy = false;
            this.close();
            if (!deleted) {
                window.showToast('Could not delete the release. Please try again.', 'error');
                return;
            }
            // the row is gone already when the list was drawn again while the request was out
            const row = this.trigger?.isConnected ? this.trigger.closest('[data-release-row]') : null;
            if (row) {
                if (row.querySelector('[data-cart]')?.getAttribute('aria-pressed') === 'true') this.$store.cart.setCount(Math.max(0, this.$store.cart.count - 1));
                row.dispatchEvent(new CustomEvent('release-deleted', { bubbles: true }));
            }
            window.showToast('Deleted ' + this.name, 'success');
        },
    };
}
