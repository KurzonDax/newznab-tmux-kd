/**
 * The `adminRowDelete` component body, kept free of any Alpine import so it can
 * be exercised headlessly. `row-delete.js` is the thin registration shim.
 *
 * The root wraps the delete button of one row of an admin list and carries:
 * `data-ajax-url` and `data-csrf-token` (the `admin/ajax` endpoint), `data-ajax-action`
 * (the delete action that list uses), `data-row-id`, `data-question` (asked with the
 * browser's own confirm()) and `data-deleted-message` (the success toast).
 *
 * The endpoint answers 2xx once the row is deleted and 500 when the delete throws,
 * so the status code alone tells the two apart.
 */
export function adminRowDelete() {
    return {
        // Declared here, not first assigned in init(): Alpine writes a property that no scope
        // declares onto the outermost component, and on the blacklist list every row sits
        // inside the one `blacklistSweep` root, so the rows would overwrite each other.
        _ajaxUrl: '',
        _csrf: '',
        _action: '',
        _rowId: '',
        _question: '',
        _deletedMessage: '',

        init() {
            const data = this.$el.dataset;
            this._ajaxUrl = data.ajaxUrl;
            this._csrf = data.csrfToken;
            this._action = data.ajaxAction;
            this._rowId = data.rowId;
            this._question = data.question;
            this._deletedMessage = data.deletedMessage;
        },

        remove() {
            if (! window.confirm(this._question)) { return; }

            fetch(this._ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': this._csrf, 'X-Requested-With': 'XMLHttpRequest' },
                body: new URLSearchParams({ action: this._action, row_id: this._rowId }),
            }).then(response => {
                if (! response.ok) { throw new Error('Delete answered ' + response.status); }
                document.getElementById('row-' + this._rowId)?.remove();
                window.showToast(this._deletedMessage, 'success');
            }).catch(() => window.showToast('Error', 'error'));
        },
    };
}
