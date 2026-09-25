/**
 * Row actions shared by the TV screens' release lists (tv-releases, tv-episode-list): Copy NZB
 * link, the cart toggle, and the floating bar's Download NZBs / Add to cart. The host component
 * supplies `screen` (its root, carrying data-nzb-link-base and data-api-token), `selectedGuids()`
 * and `clearSelection()`.
 */
import { postJson } from './tv-list.js';

const COPIED = 'NZB link copied. It contains your API key, so only paste it into your own downloader.';

/**
 * Copies text with the Clipboard API, or with execCommand where the site is served over plain
 * http and the Clipboard API is unavailable.
 */
export async function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
        try {
            await navigator.clipboard.writeText(text);
            return true;
        } catch {
            // fall through to the legacy path
        }
    }
    const buffer = document.createElement('textarea');
    buffer.value = text;
    buffer.setAttribute('readonly', '');
    buffer.className = 'tv-copy-buffer';
    document.body.appendChild(buffer);
    buffer.select();
    let copied = false;
    try {
        copied = document.execCommand('copy');
    } catch {
        copied = false;
    }
    buffer.remove();
    return copied;
}

export function rowActions() {
    return {
        nzbLink(guid) {
            const url = new URL(this.screen.dataset.nzbLinkBase);
            url.searchParams.set('t', 'get');
            url.searchParams.set('id', guid);
            url.searchParams.set('apikey', this.screen.dataset.apiToken);
            return url.toString();
        },

        async copyLink(button) {
            const link = this.nzbLink(button.dataset.copyNzb);
            if (!(await copyText(link))) {
                window.showToast('Could not copy. The link is: ' + link, 'error');
                return;
            }
            window.showToast(COPIED, 'success');
            const icon = button.querySelector('i');
            icon.classList.replace('fa-link', 'fa-check');
            clearTimeout(button.copiedTimer);
            button.copiedTimer = setTimeout(() => icon.classList.replace('fa-check', 'fa-link'), 1600);
        },

        /** Round cart buttons change their tooltip; a labelled one (the details header's) its words and icon. */
        markCart(guids, inCart) {
            this.screen.querySelectorAll('[data-cart]').forEach(button => {
                if (!guids.includes(button.dataset.cart)) return;
                button.setAttribute('aria-pressed', inCart ? 'true' : 'false');
                if (button.dataset.cartLabel !== undefined) {
                    button.querySelector('span').textContent = inCart ? 'In cart' : 'Add to cart';
                    const icon = button.querySelector('i');
                    icon.classList.toggle('fa-check', inCart);
                    icon.classList.toggle('fa-cart-shopping', !inCart);
                    return;
                }
                button.setAttribute('title', inCart ? 'In cart · click to remove' : 'Add to cart');
                button.setAttribute('aria-label', inCart ? 'Remove from cart' : 'Add to cart');
            });
        },

        async toggleCart(button) {
            if (button.disabled) return;
            const guid = button.dataset.cart, removing = button.getAttribute('aria-pressed') === 'true';
            button.disabled = true;
            try {
                const result = await postJson(removing ? '/cart/delete/' + encodeURIComponent(guid) : '/cart/add', { id: guid });
                if (!result.success) throw new Error('Cart update failed');
                this.markCart([guid], !removing);
                if (result.cartCount !== undefined) this.$store.cart.setCount(result.cartCount);
                window.showToast(removing ? 'Removed from cart.' : 'Added to cart.', 'success');
            } catch {
                window.showToast('Could not update your cart. Please try again.', 'error');
            } finally {
                button.disabled = false;
            }
        },

        async addSelectedToCart() {
            const guids = this.selectedGuids();
            if (!guids.length) return;
            try {
                const result = await postJson('/cart/add', { id: guids.join(',') });
                if (!result.success) throw new Error('Cart update failed');
                this.markCart(guids, true);
                if (result.cartCount !== undefined) this.$store.cart.setCount(result.cartCount);
                window.showToast(guids.length === 1 ? 'Added to cart.' : guids.length + ' releases added to cart.', 'success');
                this.clearSelection();
            } catch {
                window.showToast('Could not update your cart. Please try again.', 'error');
            }
        },

        downloadSelected() {
            const guids = this.selectedGuids();
            if (!guids.length) return;
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '/getnzb';
            form.hidden = true;
            const fields = { id: guids.join(','), zip: '1', _token: document.querySelector('meta[name="csrf-token"]')?.content ?? '' };
            Object.entries(fields).forEach(([name, value]) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = value;
                form.append(input);
            });
            document.body.append(form);
            form.submit();
            form.remove();
            this.clearSelection();
        },
    };
}
