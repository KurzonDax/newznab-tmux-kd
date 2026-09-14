import { modalLifecycle } from './modal-lifecycle.js';

export function trailerModal() {
    return {
        ...modalLifecycle(),
        open: false,
        url: '',
        playerContainer: null,
        init() {
            this.initModal();
            this.playerContainer = this.$el.querySelector('[data-trailer-player]');
            this._click = event => {
                const trigger = event.target.closest('[data-trailer-url]');
                if (!trigger) return;
                this.url = trigger.dataset.trailerUrl;
                const player = document.createElement('iframe');
                player.src = this.url;
                player.title = 'Movie trailer';
                player.className = 'w-full aspect-video';
                player.tabIndex = 0;
                player.allow = 'encrypted-media; picture-in-picture; fullscreen';
                player.referrerPolicy = 'strict-origin-when-cross-origin';
                player.allowFullscreen = true;
                this.playerContainer.replaceChildren(player);
                this.open = true;
            };
            document.addEventListener('click', this._click);
        },
        close() {
            this.playerContainer?.replaceChildren();
            this.url = '';
            this.open = false;
        },
        destroy() {
            document.removeEventListener('click', this._click);
            this.close();
            this._modalTeardown?.();
        },
    };
}
