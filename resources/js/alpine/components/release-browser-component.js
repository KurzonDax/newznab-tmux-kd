export function releaseBrowser() {
    return {
        selectedCount: 0,
        browserRoot: null,

        init() {
            this.browserRoot = this.$el;
            this.selectionChanged();
        },

        selectAll(event) {
            this.browserRoot.querySelectorAll('[data-release-select]').forEach(box => {
                box.checked = event.target.checked;
            });
            this.selectionChanged();
        },

        selectionChanged() {
            const boxes = Array.from(this.browserRoot.querySelectorAll('[data-release-select]'));
            this.selectedCount = boxes.filter(box => box.checked).length;
            boxes.forEach(box => { box.closest('[data-release-row]').dataset.selected = box.checked ? '1' : '0'; });
            this.browserRoot.querySelectorAll('[data-select-all]').forEach(header => {
                header.checked = boxes.length > 0 && boxes.length === this.selectedCount;
                header.indeterminate = this.selectedCount > 0 && this.selectedCount < boxes.length;
            });
        },

        clearSelection() {
            this.selectAll({ target: { checked: false } });
        },

        selectedGuids() {
            return Array.from(this.browserRoot.querySelectorAll('[data-release-select]'))
                .filter(box => box.checked).map(box => box.value);
        },

        downloadSelected() {
            const guids = this.selectedGuids();
            if (!guids.length) return;
            window.location.assign('/getnzb?id=' + encodeURIComponent(guids.join(',')) + '&zip=1');
            this.clearSelection();
        },

        goToPage(event) {
            const value = event.target.value.trim();
            const page = Number(value);
            const last = Number(this.browserRoot.dataset.lastPage);
            if (!/^\d+$/.test(value) || page < 1 || page > last) {
                window.showToast('Enter a page from 1 to ' + last + '.', 'warning');
                return;
            }
            const url = new URL(window.location.href);
            url.searchParams.set('page', String(page));
            window.location.assign(url.toString());
        },

        async changePreference(event) {
            const { preference, value } = event.currentTarget.dataset;
            const storedValue = preference === 'per' ? Number(value) : preference === 'thumbs' ? value === '1' : value;
            try {
                await this.post('/profile/update-view', { root: this.browserRoot.dataset.root, [preference]: storedValue });
                this.navigateFilter(preference, value);
            } catch {
                window.showToast('Could not save your view preference. Please try again.', 'error');
            }
        },

        async post(url, body) {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json', Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                },
                body: JSON.stringify(body),
            });
            if (!response.ok) throw new Error('Request failed');
            return response.json();
        },

        navigateFilter(name, value, clearLetter = false) {
            const url = new URL(window.location.href);
            if (name === 'q') ['search', 'subject', 'id', 'searchadvr'].forEach(key => url.searchParams.delete(key));
            if (value === '') url.searchParams.delete(name);
            else url.searchParams.set(name, value);
            url.searchParams.delete('page');
            if (clearLetter) {
                url.searchParams.delete('letter');
                url.searchParams.delete('ob');
            }
            window.location.assign(url.toString());
        },

        searchListing(event) {
            this.navigateFilter('q', event.target.value);
        },

        filterListing(event) {
            this.navigateFilter(event.target.name, event.target.value);
        },

        sortListing(event) {
            this.navigateFilter('sort', event.target.value, true);
        },

        clearFilters() {
            const url = new URL(window.location.href);
            ['q', 'search', 'subject', 'id', 'searchadvr', 'name', 'year', 'genre', 'network', 'label', 'platform', 'publisher', 'author', 'letter', 'watching', 'group', 'poster', 'page', 'minc'].forEach(key => url.searchParams.delete(key));
            window.location.assign(url.toString());
        },

        async toggleBasket(event) {
            const button = event.currentTarget;
            if (button.disabled) return;
            const removing = button.dataset.inBasket === '1';
            button.disabled = true;
            try {
                const result = await this.post(removing ? '/cart/delete/' + encodeURIComponent(button.dataset.guid) : '/cart/add', { id: button.dataset.guid });
                if (!result.success) throw new Error('Basket update failed');
                this.updateBasketButtons([button.dataset.guid], !removing);
                this.$store.cart.setCount(result.cartCount);
                window.showToast(removing ? 'Removed from basket.' : 'Added to basket.', 'success');
                if (removing && this.browserRoot.dataset.basketOnly === '1') window.location.reload();
            } catch {
                window.showToast('Could not update your basket. Please try again.', 'error');
            } finally {
                button.disabled = false;
            }
        },

        async addSelectedToBasket() {
            const guids = this.selectedGuids();
            if (!guids.length) return;
            try {
                const result = await this.post('/cart/add', { id: guids.join(',') });
                if (!result.success) throw new Error('Basket update failed');
                this.updateBasketButtons(guids, true);
                this.$store.cart.setCount(result.cartCount);
                window.showToast('Added to basket.', 'success');
            } catch {
                window.showToast('Could not update your basket. Please try again.', 'error');
            }
        },

        updateBasketButtons(guids, inBasket) {
            this.browserRoot.querySelectorAll('[data-row-action="basket"]').forEach(button => {
                if (!guids.includes(button.dataset.guid)) return;
                button.dataset.inBasket = inBasket ? '1' : '0';
                button.setAttribute('title', inBasket ? 'In basket · click to remove' : 'Add to basket');
                button.setAttribute('aria-label', inBasket ? 'Remove from basket' : 'Add to basket');
            });
        },
    };
}
