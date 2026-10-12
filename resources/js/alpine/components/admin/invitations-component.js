/**
 * The `adminInvitations` component body, kept free of any Alpine import so it can
 * be exercised headlessly. `invitations.js` is the thin registration shim.
 *
 * Drives the select-all box of the admin invitations list. Alpine's `$el` is the
 * element an expression sits on, so the root is captured in `init()`, where it is the root.
 */
export function adminInvitations() {
    return {
        _root: null,

        init() {
            this._root = this.$el;
        },

        /** Head box: apply its new checked value to every row. */
        toggleAll() {
            const head = this._head();
            this._rows().forEach(box => { box.checked = head.checked; });
        },

        /** Row box: the head box is checked when all rows are, indeterminate when only some are. */
        syncHead() {
            const head = this._head();
            const rows = this._rows();
            const all = rows.length > 0 && rows.every(box => box.checked);
            const some = rows.some(box => box.checked);
            head.checked = all;
            head.indeterminate = some && ! all;
        },

        _head() {
            return this._root.querySelector('#select_all');
        },

        _rows() {
            return Array.from(this._root.querySelectorAll('.invitation-checkbox'));
        },
    };
}
