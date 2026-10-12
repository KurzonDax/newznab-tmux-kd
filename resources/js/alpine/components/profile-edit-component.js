/**
 * The `copyToClipboard` component body, kept free of any Alpine import so it can
 * be exercised headlessly. `profile-edit.js` is the thin registration shim.
 *
 * The root wraps one input and its copy button; the button calls `copy('<input id>')`.
 */
export function copyToClipboard() {
    return {
        copied: false,

        copy(targetId) {
            const input = document.getElementById(targetId);
            if (!input) return;
            input.select();
            input.setSelectionRange(0, 99999);

            const text = input.value;
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(() => this._showFeedback()).catch(() => this._fallbackCopy(text));
            } else {
                this._fallbackCopy(text);
            }
        },

        _fallbackCopy(text) {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            ta.setSelectionRange(0, 99999);
            try { document.execCommand('copy'); this._showFeedback(); } catch (e) { console.error('Failed to copy:', e); }
            document.body.removeChild(ta);
        },

        _showFeedback() {
            this.copied = true;
            setTimeout(() => { this.copied = false; }, 2000);
        },
    };
}
