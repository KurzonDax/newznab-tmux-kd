import Alpine from '@alpinejs/csp';

/**
 * Per-card Save gating on the admin settings hub.
 *
 * Every card on a page is its own form posting to its own endpoint, so a Save button that is
 * always live invites saving a card nobody touched. The button unlocks on the first input or
 * change event inside the card and stays unlocked until the form navigates away.
 */
Alpine.data('settingsCard', () => ({
    pristine: true,

    markDirty() {
        this.pristine = false;
    },
}));
