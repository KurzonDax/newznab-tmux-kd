import { modalLifecycle } from './modal-lifecycle.js';
import { fetchList, postJson } from './tv-list.js';
import { rowActions } from './tv-row-actions.js';

const LOAD_FAILED = 'Could not load the releases. Reload the page and try again.';
const SAVE_FAILED = 'Could not save your view preference. Please try again.';

/**
 * The home page (content/home.blade.php; docs/proposals/home-redesign/SPEC.md 3 and 4).
 *
 * Shelves: the arrows scroll a rail by 80% of its width; a tile opens its panel under its rail,
 * fetched as the page's `panel` fragment (the tile is marked open at once, a failed load closes it
 * again with a toast); one panel per page; the open tile, the close button and Escape close it.
 * The panel's rows are the generic lists' rows, so Copy link and Cart work as on every list.
 *
 * The Shelves dialog: a tick saves at once and the shelves behind the dialog are fetched again
 * (the `shelves` fragment). Reordering is drag and drop on a row's grip. Pointer: the row lifts
 * as a ghost that follows the pointer while the list reflows under it, the drop saves. Keyboard:
 * Space grabs, the arrow keys move and announce the position, Space drops and saves, Escape puts
 * the row back and keeps the dialog open. A save that fails puts the row or the tick back.
 */
export function homeShelves() {
    return {
        ...modalLifecycle(),
        ...rowActions(),
        open: false,
        screen: null,
        openTile: null,
        panelRequest: null,
        shelvesRequest: null,
        drag: null,
        grabStart: null,

        init() {
            this.screen = this.$el;
            this.initModal(() => this.closeDialog());
            this._escape = event => {
                // Escape belongs to an open dialog first (this page's, or a row chip's NFO, files or picture dialog)
                const dialogOpen = Array.from(document.querySelectorAll('[data-modal-dialog]')).some(dialog => dialog.getClientRects().length);
                if (event.key === 'Escape' && !this.open && !dialogOpen && this.openTile) this.closePanel(true);
            };
            this._move = event => this.moveDrag(event);
            this._drop = () => this.endDrag();
            document.addEventListener('keydown', this._escape);
        },

        destroy() {
            document.removeEventListener('keydown', this._escape);
            this.stopDragListeners();
            this.drag?.ghost.remove();
            this.panelRequest?.abort();
            this.shelvesRequest?.abort();
            this._modalTeardown?.();
        },

        handleClick(event) {
            const target = event.target;
            const rail = target.closest('[data-rail]');
            if (rail) return this.scrollRail(rail);
            const tile = target.closest('[data-tile]');
            if (tile) return this.toggleTile(tile);
            if (target.closest('[data-close-panel]')) return this.closePanel(true);
            const copy = target.closest('[data-copy-nzb]');
            if (copy) return this.copyLink(copy);
            const cart = target.closest('[data-cart]');
            if (cart) return this.toggleCart(cart);
            if (target.closest('[data-shelves-open]')) return this.openDialog();
            const tick = target.closest('[data-shelf-tick]');
            if (tick) return this.toggleShelf(tick);
            return undefined;
        },

        /* ---------- shelves ---------- */

        scrollRail(button) {
            const rail = button.closest('[data-shelf]').querySelector('[data-rail-of]');
            if (rail) rail.scrollBy({ left: Number(button.dataset.rail) * rail.clientWidth * 0.8, behavior: 'smooth' });
        },

        async toggleTile(tile) {
            if (tile.getAttribute('aria-expanded') === 'true') {
                this.closePanel();
                return;
            }
            this.closePanel();
            const section = tile.closest('[data-shelf]'), request = new AbortController();
            tile.setAttribute('aria-expanded', 'true');
            this.openTile = tile;
            this.panelRequest = request;
            const url = new URL(this.screen.dataset.homeUrl, window.location.href);
            url.searchParams.set('shelf', section.dataset.shelf);
            url.searchParams.set('kind', tile.dataset.kind);
            url.searchParams.set('id', tile.dataset.id);
            try {
                const html = await fetchList(url, request.signal, 'panel');
                if (this.openTile !== tile) return;
                const slot = section.querySelector('[data-panel-slot]');
                slot.innerHTML = html;
                slot.firstElementChild?.scrollIntoView?.({ block: 'nearest', behavior: 'smooth' });
            } catch {
                if (this.openTile !== tile) return;
                this.closePanel();
                window.showToast(LOAD_FAILED, 'error');
            }
        },

        /** Closes the open panel; `refocus` returns focus to its tile (the close button, Escape). */
        closePanel(refocus = false) {
            const tile = this.openTile;
            this.panelRequest?.abort();
            this.panelRequest = null;
            this.openTile = null;
            this.screen.querySelectorAll('[data-tile][aria-expanded="true"]').forEach(open => open.setAttribute('aria-expanded', 'false'));
            this.screen.querySelectorAll('[data-panel-slot]').forEach(slot => { slot.innerHTML = ''; });
            if (refocus && tile?.isConnected) tile.focus();
        },

        /** The shelves drawn again after the dialog saved a change. */
        async refreshShelves() {
            this.shelvesRequest?.abort();
            const request = new AbortController();
            this.shelvesRequest = request;
            try {
                const html = await fetchList(new URL(this.screen.dataset.homeUrl, window.location.href), request.signal, 'shelves');
                if (request.signal.aborted) return;
                this.panelRequest?.abort();
                this.openTile = null;
                this.$refs.shelves.innerHTML = html;
            } catch (error) {
                if (error.name !== 'AbortError') window.showToast(LOAD_FAILED, 'error');
            }
        },

        /* ---------- the Shelves dialog ---------- */

        openDialog() {
            this.open = true;
            this.focusFirstTick(20);
        },

        /** The first row's checkbox takes focus once the dialog is on screen (x-show reveals it a moment after `open` is set). */
        focusFirstTick(tries) {
            const box = this.screen.querySelector('[data-shelf-tick]');
            if (!this.open || !box) return;
            if (box.getClientRects().length) box.focus();
            else if (tries > 0) setTimeout(() => this.focusFirstTick(tries - 1), 16);
        },

        closeDialog() {
            if (!this.open) return;
            // a row still grabbed goes back where it was: nothing was dropped
            const grip = this.grabStart && this.zone().querySelector('[data-grip][aria-pressed="true"]');
            if (grip) {
                this.restoreOrder(this.grabStart);
                this.letGo(grip, grip.closest('[data-key]'));
            }
            this.open = false;
            // after the dialog let go of the focus: back to the Shelves button, whatever opened it
            this.$nextTick(() => this.screen.querySelector('[data-shelves-open]')?.focus());
        },

        close() {
            this.closeDialog();
        },

        zone() {
            return this.screen.querySelector('[data-drag-zone]');
        },

        rows() {
            return Array.from(this.zone().querySelectorAll('[data-key]'));
        },

        keys() {
            return this.rows().map(row => row.dataset.key);
        },

        async toggleShelf(button) {
            if (button.disabled) return;
            const was = button.getAttribute('aria-checked') === 'true';
            button.setAttribute('aria-checked', was ? 'false' : 'true');
            button.disabled = true;
            const ticked = this.rows().filter(row => row.querySelector('[data-shelf-tick]').getAttribute('aria-checked') === 'true').map(row => row.dataset.key);
            try {
                // the listed rows travel with the ticks, so a shelf the dialog does not list keeps its own
                await postJson(this.screen.dataset.preferenceUrl, { root: 'home', shelves: this.keys(), ticked });
            } catch {
                button.setAttribute('aria-checked', was ? 'true' : 'false');
                window.showToast(SAVE_FAILED, 'error');
                return;
            } finally {
                button.disabled = false;
            }
            await this.refreshShelves();
        },

        /** Saves the rows' order when it differs from `start`; a failed save puts the rows back. */
        async saveOrder(start) {
            const keys = this.keys();
            if (keys.join() === start.join()) return;
            try {
                await postJson(this.screen.dataset.preferenceUrl, { root: 'home', shelves: keys });
            } catch {
                this.restoreOrder(start);
                window.showToast(SAVE_FAILED, 'error');
                return;
            }
            window.showToast('Order saved', 'success');
            await this.refreshShelves();
        },

        restoreOrder(keys) {
            const zone = this.zone(), byKey = Object.fromEntries(this.rows().map(row => [row.dataset.key, row]));
            keys.forEach(key => byKey[key] && zone.appendChild(byKey[key]));
        },

        /* ---------- drag and drop: pointer ---------- */

        startDrag(event) {
            const grip = event.target.closest('[data-grip]');
            if (!grip || event.button !== 0 || this.drag) return;
            const row = grip.closest('[data-key]');
            if (!row) return;
            event.preventDefault();
            // the ghost is the row's clone inside a carrier that wears the row list's class, so the row's styles still apply to it
            const box = row.getBoundingClientRect(), clone = row.cloneNode(true), ghost = document.createElement('div');
            clone.removeAttribute('data-key');
            ghost.className = 'home-drag-ghost home-shelf-rows';
            ghost.appendChild(clone);
            ghost.style.width = box.width + 'px';
            ghost.style.height = box.height + 'px';
            ghost.style.left = box.left + 'px';
            ghost.style.top = box.top + 'px';
            document.body.appendChild(ghost);
            row.classList.add('is-dragging');
            this.drag = { row, ghost, dx: event.clientX - box.left, dy: event.clientY - box.top, start: this.keys() };
            document.addEventListener('pointermove', this._move);
            document.addEventListener('pointerup', this._drop);
            document.addEventListener('pointercancel', this._drop);
        },

        moveDrag(event) {
            const drag = this.drag;
            if (!drag) return;
            drag.ghost.style.left = (event.clientX - drag.dx) + 'px';
            drag.ghost.style.top = (event.clientY - drag.dy) + 'px';
            // the row under the pointer, else the nearest one: the dragged row takes the place before or after it
            let target = null, nearest = Infinity;
            this.rows().filter(row => row !== drag.row).forEach(row => {
                const box = row.getBoundingClientRect(), distance = Math.abs(event.clientY - (box.top + box.height / 2));
                if (distance < nearest) {
                    nearest = distance;
                    target = { row, before: event.clientY < box.top + box.height / 2 };
                }
            });
            if (!target) return;
            if (target.before) target.row.before(drag.row);
            else target.row.after(drag.row);
        },

        endDrag() {
            const drag = this.drag;
            if (!drag) return undefined;
            this.drag = null;
            this.stopDragListeners();
            drag.ghost.remove();
            drag.row.classList.remove('is-dragging');
            return this.saveOrder(drag.start);
        },

        stopDragListeners() {
            document.removeEventListener('pointermove', this._move);
            document.removeEventListener('pointerup', this._drop);
            document.removeEventListener('pointercancel', this._drop);
        },

        /* ---------- drag and drop: keyboard, on the grip ---------- */

        handleKeydown(event) {
            const grip = event.target.closest?.('[data-grip]');
            if (!grip) return undefined;
            const row = grip.closest('[data-key]'), grabbed = grip.getAttribute('aria-pressed') === 'true';
            if (event.key === ' ' || event.key === 'Enter') {
                event.preventDefault();
                if (grabbed) return this.release(grip, row);
                grip.setAttribute('aria-pressed', 'true');
                row.classList.add('is-grabbed');
                this.grabStart = this.keys();
                window.showToast('Grabbed · arrow keys move it, Space drops it', 'info');
                return undefined;
            }
            if (!grabbed) return undefined;
            if (event.key === 'Escape') {
                // puts the row back; the dialog stays open
                event.preventDefault();
                event.stopPropagation();
                this.restoreOrder(this.grabStart);
                this.letGo(grip, row);
                grip.focus();
                return undefined;
            }
            const step = { ArrowUp: -1, ArrowLeft: -1, ArrowDown: 1, ArrowRight: 1 }[event.key];
            if (!step) return undefined;
            event.preventDefault();
            const neighbour = step < 0 ? row.previousElementSibling : row.nextElementSibling;
            if (neighbour?.hasAttribute('data-key')) {
                if (step < 0) neighbour.before(row);
                else neighbour.after(row);
            }
            grip.focus();
            const rows = this.rows();
            window.showToast(`${row.dataset.key} · position ${rows.indexOf(row) + 1} of ${rows.length}`, 'info');
            return undefined;
        },

        release(grip, row) {
            const start = this.grabStart;
            this.letGo(grip, row);
            return this.saveOrder(start);
        },

        letGo(grip, row) {
            grip.setAttribute('aria-pressed', 'false');
            row.classList.remove('is-grabbed');
            this.grabStart = null;
        },
    };
}
