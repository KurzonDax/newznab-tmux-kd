/**
 * The Fullscreen view stage shared by both image modals.
 *
 * Not an Alpine component itself: spread the returned object into a component's
 * data to add the second layer described in CONTEXT.md -- the release's
 * Full-size copy fitted inside the viewport, entered from a corner control and
 * exited back to the modal it came from. The control appears only when a
 * trigger supplied a full URL, so releases whose only stored rendering is the
 * thumb never offer it.
 *
 * The host component owns close(); this only adds the layer above it.
 */
export function fullscreenStage() {
    return {
        fullUrl: '',
        fullscreen: false,

        /** Called by the host whenever it opens with a new image. */
        resetFullscreen(fullUrl) {
            this.fullUrl = fullUrl || '';
            this.fullscreen = false;
        },

        enterFullscreen() {
            if (this.fullUrl) { this.fullscreen = true; }
        },

        exitFullscreen() {
            this.fullscreen = false;
        },

        /** Escape and backdrop clicks step back one layer at a time. */
        stepBack() {
            if (this.fullscreen) { this.exitFullscreen(); return; }
            this.close();
        },
    };
}
