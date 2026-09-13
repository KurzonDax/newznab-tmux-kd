let activeModal = null;
let savedOverflow = '';

/** Shared focus and scroll ownership for the public modal base. */
export function modalLifecycle() {
    return {
        initModal(close = () => this.close(), escape = close) {
            const dialog = this.$el?.querySelector('[data-modal-dialog]');
            if (!dialog) return;
            const document = dialog.ownerDocument;
            let trigger = null;
            const controls = () => {
                const fullSize = dialog.querySelector('[data-full-size-layer]');
                const scope = fullSize?.getClientRects().length ? fullSize : dialog;
                return [...scope.querySelectorAll('button:not([disabled]), a[href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), audio[controls], video[controls], [tabindex="0"]')]
                    .filter(control => control.getClientRects().length);
            };
            const owner = { close, dialog, trigger: () => trigger };
            const release = () => {
                if (activeModal !== owner) return;
                activeModal = null;
                document.body.style.overflow = savedOverflow;
                if (trigger?.isConnected) trigger.focus();
            };
            this.$watch('open', open => {
                if (!open) { release(); return; }
                const previous = activeModal;
                trigger = previous?.dialog.contains(document.activeElement)
                    ? previous.trigger() : document.activeElement;
                if (!previous) savedOverflow = document.body.style.overflow;
                activeModal = owner;
                previous?.close();
                document.body.style.overflow = 'hidden';
                this.$nextTick(() => {
                    if (activeModal === owner && this.open) controls()[0]?.focus();
                });
            });
            const keydown = event => {
                if (activeModal !== owner || !this.open) return;
                if (event.key === 'Escape') {
                    event.preventDefault();
                    escape();
                } else if (event.key === 'Tab') {
                    const focusable = controls();
                    const first = focusable[0], last = focusable.at(-1);
                    if (!first) { event.preventDefault(); return; }
                    if (event.shiftKey && (document.activeElement === first || !focusable.includes(document.activeElement))) {
                        event.preventDefault(); last.focus();
                    } else if (!event.shiftKey && (document.activeElement === last || !focusable.includes(document.activeElement))) {
                        event.preventDefault(); first.focus();
                    }
                }
            };
            document.addEventListener('keydown', keydown);
            this._modalTeardown = () => {
                document.removeEventListener('keydown', keydown);
                release();
            };
        },

        destroy() {
            this._modalTeardown?.();
        },
    };
}
