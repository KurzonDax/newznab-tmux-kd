import { modalLifecycle } from './modal-lifecycle.js';
import { fileSummaryPages } from './file-summary-pages.js';

export function filelistModal() {
    return {
        ...modalLifecycle(),
        ...fileSummaryPages(),
        open: false,
        releaseName: '',
        guid: '',
        show(guid) {
            this.open = true;
            this.guid = guid;
            this.releaseName = '';
            this.filesTotal = 0;
            this.filesLastPage = 1;
            return this.loadFilePage(1);
        },
        loadFilePage(page = this.filesPage) {
            return this.fetchFiles(this.guid, this.$refs.content, page);
        },
        close() {
            this.cancelFiles();
            this.open = false;
            this.$refs.content.innerHTML = '';
        },
        init() {
            this.initModal();
            this._showFilelist = guid => this.show(guid);
            this._closeFilelist = () => this.close();
            window.showFilelist = this._showFilelist;
            window.closeFilelistModal = this._closeFilelist;
            this._filelistClick = event => {
                const badge = event.target.closest('.filelist-badge');
                if (badge) { event.preventDefault(); this.show(badge.dataset.guid); return; }
                if (event.target.closest('[data-close-filelist-modal]')) { event.preventDefault(); this.close(); }
            };
            document.addEventListener('click', this._filelistClick);
        },
        destroy() {
            this.cancelFiles();
            this._modalTeardown?.();
            document.removeEventListener('click', this._filelistClick);
            if (window.showFilelist === this._showFilelist) delete window.showFilelist;
            if (window.closeFilelistModal === this._closeFilelist) delete window.closeFilelistModal;
        },
    };
}
