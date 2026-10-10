import { modalLifecycle } from './modal-lifecycle.js';

/**
 * The administrator's "Blacklist this poster" confirmation on a poster's list
 * (docs/proposals/generic-release-lists/SPEC.md 5.8), in the redesign's dialog frame
 * (x-tv-dialog): the Blacklist button opens it; Escape, the close button, a click outside and
 * Cancel close it without a rule and return focus to the button (modalLifecycle); the
 * remove-releases box is cleared on close so a reopened dialog never remembers a tick.
 */
export function posterIdentityBlacklist() {
    return {
        ...modalLifecycle(),
        open: false,
        deleteReleases: false,

        init() {
            this.initModal();
        },

        openConfirmation() {
            this.open = true;
        },

        close() {
            this.open = false;
            this.deleteReleases = false;
        },

        closeConfirmation() {
            this.close();
        },
    };
}
