/**
 * Alpine.data('copyToClipboard') - Copy button with visual feedback
 */
import Alpine from '@alpinejs/csp';

Alpine.data('copyToClipboard', () => ({
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
    }
}));

/**
 * Document-level delegation for profile edit and copy-to-clipboard
 * on pages that don't use x-data yet.
 */
(function() {
    // Copy to clipboard delegation
    function copyToClipboard(text, button) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function() { showCopyFeedback(button); }).catch(function() { fallbackCopy(text, button); });
        } else fallbackCopy(text, button);
    }
    function fallbackCopy(text, button) {
        var ta = document.createElement('textarea'); ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0'; document.body.appendChild(ta); ta.select(); ta.setSelectionRange(0, 99999);
        try { document.execCommand('copy'); showCopyFeedback(button); } catch(e) {} document.body.removeChild(ta);
    }
    function showCopyFeedback(button) {
        if (!button) return; var icon = button.querySelector('i');
        if (icon) { icon.classList.remove('fa-copy'); icon.classList.add('fa-check'); }
        button.classList.add('text-green-600');
        setTimeout(function() { if (icon) { icon.classList.remove('fa-check'); icon.classList.add('fa-copy'); } button.classList.remove('text-green-600'); }, 2000);
    }
    document.addEventListener('click', function(ev) {
        var copyBtn = ev.target.closest('.copy-btn');
        if (copyBtn && !copyBtn.closest('[x-data]')) { var tid = copyBtn.getAttribute('data-copy-target'); var input = document.getElementById(tid); if (input) { ev.preventDefault(); input.select(); input.setSelectionRange(0, 99999); copyToClipboard(input.value, copyBtn); } }
        var apiBtn = ev.target.id === 'copyApiToken' ? ev.target : ev.target.closest('#copyApiToken');
        if (apiBtn && !apiBtn.closest('[x-data]')) { var input = document.getElementById('apiTokenInput'); if (input) { ev.preventDefault(); input.select(); input.setSelectionRange(0, 99999); copyToClipboard(input.value, apiBtn); } }
    });
})();
