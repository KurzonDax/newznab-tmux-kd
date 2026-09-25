/**
 * The TV screens' file list (docs/proposals/tv-redesign/SPEC.md 3.5): every page of
 * `release/{guid}/files` fetched at 100 a page and shown as one plain two-column list, sizes
 * under 1 MB in KB. Used by the file list dialog and the details page's Files tab.
 */
import { escapeHtml } from './media-info-block.js';

const PER_PAGE = 100;

/** Sizes as the TV rows write them: 2.41 GB, 12.3 GB, 734 MB, 57 KB. */
export function fileSize(value) {
    const bytes = Number(value);
    if (!Number.isFinite(bytes) || bytes < 0) return '—';
    if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(bytes >= 10737418240 ? 1 : 2) + ' GB';
    if (bytes >= 1048576) return Math.round(bytes / 1048576).toLocaleString('en-US') + ' MB';
    return Math.max(1, Math.round(bytes / 1024)) + ' KB';
}

/** @returns {Promise<{name: string, files: {title: string, size: number}[]}>} */
export async function loadAllFiles(guid, signal) {
    const files = [];
    let page = 1, last = 1, name = '';
    do {
        const response = await fetch('/release/' + encodeURIComponent(guid) + '/files?page=' + page + '&per=' + PER_PAGE, { headers: { Accept: 'application/json' }, signal });
        if (!response.ok || response.redirected) throw new Error('Request failed');
        const data = await response.json();
        if (!Array.isArray(data.files)) throw new Error('Invalid file list');
        files.push(...data.files);
        last = Number(data.last_page) || 1;
        name = data.release?.searchname || name;
        page++;
    } while (page <= last);
    return { name, files };
}

export function fileTable(files) {
    if (!files.length) return '<p class="tv-note">This release lists no files.</p>';
    return '<table class="tv-file-list"><thead><tr><th>File</th><th class="tv-num">Size</th></tr></thead><tbody>' +
        files.map(file => '<tr><td>' + escapeHtml(file.title || file.name || 'Unknown') + '</td><td class="tv-num">' + fileSize(file.size) + '</td></tr>').join('') +
        '</tbody></table>';
}
