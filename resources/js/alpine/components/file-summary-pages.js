export function fileSummaryPages() {
    return {
        filesPage: 1,
        filesPer: 100,
        filesTotal: 0,
        filesLastPage: 1,
        filesLoading: false,
        filesRequest: 0,
        filesController: null,
        filesPrevious() { return this.loadFilePage(Math.max(1, this.filesPage - 1)); },
        filesNext() { return this.loadFilePage(Math.min(this.filesLastPage, this.filesPage + 1)); },
        filesPerChanged(event) {
            this.filesPer = Number(event.target.value);
            return this.loadFilePage(1);
        },
        cancelFiles() {
            this.filesRequest++;
            this.filesController?.abort();
            this.filesController = null;
            this.filesLoading = false;
        },
        async fetchFiles(guid, content, page = 1) {
            this.cancelFiles();
            const request = this.filesRequest;
            this.filesController = new AbortController();
            this.filesPage = page;
            this.filesLoading = true;
            content.innerHTML = '<p class="text-muted" role="status">Loading file list…</p>';
            try {
                const response = await fetch('/release/' + encodeURIComponent(guid) + '/files?page=' + page + '&per=' + this.filesPer, {
                    headers: { Accept: 'application/json' }, signal: this.filesController.signal,
                });
                if (!response.ok || response.redirected) throw new Error('Request failed');
                const data = await response.json();
                if (request !== this.filesRequest) return false;
                if (!Array.isArray(data.files)) throw new Error('Invalid file list');
                this.filesTotal = data.total;
                this.filesLastPage = data.last_page;
                this.releaseName = data.release?.searchname || '';
                content.innerHTML = data.files.length ? '<table class="details-files"><thead><tr><th>File name</th><th>Size</th></tr></thead><tbody>' + data.files.map(file => '<tr><td>' + escapeHtml(file.title || file.name || 'Unknown') + '</td><td>' + fileSize(file.size) + '</td></tr>').join('') + '</tbody></table>' : '<p class="text-muted">No files on this page.</p>';
                return true;
            } catch {
                if (request === this.filesRequest) {
                    content.innerHTML = '<p class="text-red-600 dark:text-red-400" role="alert">Could not load this tab. Please try again.</p>';
                }
                return false;
            } finally {
                if (request === this.filesRequest) this.filesLoading = false;
            }
        },
    };
}

function escapeHtml(value) {
    const entities = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
    return String(value).replace(/[&<>"']/g, character => entities[character]);
}

function fileSize(value) {
    const bytes = Number(value);
    if (!Number.isFinite(bytes) || bytes < 0) return 'Unknown';
    if (bytes === 0) return '0 B';
    const unit = Math.min(4, Math.max(0, Math.floor(Math.log(bytes) / Math.log(1024))));
    return (bytes / (1024 ** unit)).toLocaleString(undefined, { maximumFractionDigits: 2 }) + ' ' + ['B', 'KB', 'MB', 'GB', 'TB'][unit];
}
