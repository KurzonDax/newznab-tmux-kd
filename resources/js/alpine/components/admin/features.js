/**
 * Admin feature components: user edit, verify modal, scroll sync, etc.
 */
import Alpine from '@alpinejs/csp';

// User Edit datetime picker
Alpine.data('adminUserEdit', () => ({
    init() {
        if (!document.getElementById('rolechangedate')) return;
        this._initDateTime();
        this._setupExpiry();
    },

    _initDateTime() {
        const hidden = document.getElementById('rolechangedate');
        if (!hidden?.value) return;
        const d = new Date(hidden.value);
        if (isNaN(d.getTime())) return;
        this._setSelects(d);
        this._updatePreview();
    },

    _setSelects(d) {
        const ids = ['expiry_year','expiry_month','expiry_day','expiry_hour','expiry_minute'];
        const vals = [d.getFullYear().toString(), String(d.getMonth()+1).padStart(2,'0'), String(d.getDate()).padStart(2,'0'), String(d.getHours()).padStart(2,'0'), String(d.getMinutes()).padStart(2,'0')];
        ids.forEach((id, i) => { const el = document.getElementById(id); if (el) el.value = vals[i]; });
    },

    updateDateTime() {
        const y = document.getElementById('expiry_year')?.value;
        const mo = document.getElementById('expiry_month')?.value;
        const d = document.getElementById('expiry_day')?.value;
        const h = document.getElementById('expiry_hour')?.value;
        const mi = document.getElementById('expiry_minute')?.value;
        if (y && mo && d && h && mi) {
            document.getElementById('rolechangedate').value = y + '-' + mo + '-' + d + 'T' + h + ':' + mi + ':00';
            this._updatePreview();
        }
    },

    updateValidDays() {
        const y = parseInt(document.getElementById('expiry_year')?.value);
        const m = parseInt(document.getElementById('expiry_month')?.value);
        const daySelect = document.getElementById('expiry_day');
        if (!y || !m || !daySelect) return;
        const days = new Date(y, m, 0).getDate();
        const cur = parseInt(daySelect.value);
        daySelect.innerHTML = '<option value="">--</option>';
        for (let i = 1; i <= days; i++) { const o = document.createElement('option'); o.value = String(i).padStart(2,'0'); o.textContent = i; daySelect.appendChild(o); }
        if (cur && cur <= days) daySelect.value = String(cur).padStart(2,'0');
    },

    setExpiry(days, hours) {
        let base;
        const y = document.getElementById('expiry_year')?.value;
        const mo = document.getElementById('expiry_month')?.value;
        const d = document.getElementById('expiry_day')?.value;
        const h = document.getElementById('expiry_hour')?.value;
        const mi = document.getElementById('expiry_minute')?.value;
        const orig = document.getElementById('original_user_expiry')?.value;
        if (y && mo && d && h && mi) base = new Date(y, parseInt(mo)-1, d, h, mi);
        else if (orig) base = new Date(orig);
        else base = new Date();
        base.setDate(base.getDate() + days);
        base.setHours(base.getHours() + hours);
        this._setSelects(base);
        this.updateValidDays();
        this.updateDateTime();
    },

    setEndOfDay() {
        const d = new Date(); d.setHours(23,59,0,0);
        this._setSelects(d);
        this.updateValidDays();
        this.updateDateTime();
    },

    clearExpiry() {
        ['expiry_year','expiry_month','expiry_day','expiry_hour','expiry_minute'].forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
        document.getElementById('rolechangedate').value = '';
        const p = document.getElementById('datetime_preview'); if (p) p.classList.add('hidden');
    },

    _setupExpiry() {
        ['expiry_year','expiry_month','expiry_day','expiry_hour','expiry_minute'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.addEventListener('change', () => this.updateDateTime());
        });
        const y = document.getElementById('expiry_year'), m = document.getElementById('expiry_month');
        if (y) y.addEventListener('change', () => this.updateValidDays());
        if (m) m.addEventListener('change', () => this.updateValidDays());

        // Setup quick action buttons
        document.querySelectorAll('[data-expiry-action]').forEach(btn => {
            btn.addEventListener('click', () => {
                const action = btn.dataset.expiryAction;
                if (action === 'set') {
                    const days = parseInt(btn.dataset.days) || 0;
                    const hours = parseInt(btn.dataset.hours) || 0;
                    this.setExpiry(days, hours);
                } else if (action === 'end-of-day') {
                    this.setEndOfDay();
                } else if (action === 'clear') {
                    this.clearExpiry();
                }
            });
        });
    },

    _updatePreview() {
        const hidden = document.getElementById('rolechangedate');
        const preview = document.getElementById('datetime_preview');
        const display = document.getElementById('datetime_display');
        if (!hidden?.value || !preview || !display) { if (preview) preview.classList.add('hidden'); return; }
        const d = new Date(hidden.value);
        display.textContent = d.toLocaleDateString('en-US', { weekday:'short', year:'numeric', month:'long', day:'numeric', hour:'2-digit', minute:'2-digit' });
        preview.classList.remove('hidden');
        const diff = d - new Date();
        display.classList.remove('text-red-600','dark:text-red-400','text-yellow-600','dark:text-yellow-400','text-blue-600','dark:text-blue-400');
        if (diff < 0) display.classList.add('text-red-600','dark:text-red-400');
        else if (diff < 7*24*60*60*1000) display.classList.add('text-yellow-600','dark:text-yellow-400');
        else display.classList.add('text-blue-600','dark:text-blue-400');
    }
}));

// Admin deleted users
Alpine.data('adminDeletedUsers', () => ({
}));

/**
 * Document-level handlers of the deleted-users page (the `adminDeletedUsers` root).
 */
(function() {
    // --- Click delegation ---
    document.addEventListener('click', function(e) {
        // Restore/delete user buttons
        var restoreBtn = e.target.closest('.restore-user-btn');
        if (restoreBtn) {
            e.preventDefault();
            var userId = restoreBtn.getAttribute('data-user-id');
            var username = restoreBtn.getAttribute('data-username') || 'this user';
            showConfirm({ title: 'Restore User', message: 'Are you sure you want to restore user "' + username + '"?', type: 'success', confirmText: 'Restore', onConfirm: function() {
                var form = document.getElementById('individualActionForm');
                if (form) { form.action = '/admin/deleted-users/restore/' + userId; form.method = 'POST'; form.submit(); }
            }});
            return;
        }

        var deleteUserBtn = e.target.closest('.delete-user-btn');
        if (deleteUserBtn) {
            e.preventDefault();
            var userId = deleteUserBtn.getAttribute('data-user-id');
            var username = deleteUserBtn.getAttribute('data-username') || 'this user';
            showConfirm({ title: 'Permanently Delete User', message: 'Are you sure you want to permanently delete user "' + username + '"? This action cannot be undone.', type: 'danger', confirmText: 'Delete Permanently', onConfirm: function() {
                var form = document.getElementById('individualActionForm');
                if (form) { form.action = '/admin/deleted-users/permanent-delete/' + userId; form.method = 'POST'; form.submit(); }
            }});
            return;
        }
    });

    // --- Admin select-alls and bulk forms ---
    // Deleted users select-all
    var selectAllUsers = document.getElementById('selectAll');
    if (selectAllUsers) {
        var userBoxes = document.querySelectorAll('.user-checkbox');
        selectAllUsers.addEventListener('change', function() { userBoxes.forEach(function(cb) { cb.checked = selectAllUsers.checked; }); });
        userBoxes.forEach(function(cb) { cb.addEventListener('change', function() {
            var all = Array.from(userBoxes).every(function(c) { return c.checked; });
            var some = Array.from(userBoxes).some(function(c) { return c.checked; });
            selectAllUsers.checked = all; selectAllUsers.indeterminate = some && !all;
        }); });
    }

    // Bulk action form
    var bulkForm = document.getElementById('bulkActionForm');
    if (bulkForm) {
        bulkForm.addEventListener('submit', function(ev) {
            ev.preventDefault();
            var action = document.getElementById('bulkAction')?.value;
            var checked = document.querySelectorAll('.user-checkbox:checked');
            var errEl = document.getElementById('validationError'), errMsg = document.getElementById('validationErrorMessage');
            if (errEl) errEl.classList.add('hidden');
            if (!action) { if (errEl && errMsg) { errMsg.textContent = 'Please select an action.'; errEl.classList.remove('hidden'); } return; }
            if (!checked.length) { if (errEl && errMsg) { errMsg.textContent = 'Please select at least one user.'; errEl.classList.remove('hidden'); } return; }
            var text = action === 'restore' ? 'restore' : 'permanently delete';
            showConfirm({ title: action === 'restore' ? 'Restore Users' : 'Delete Users', message: 'Are you sure you want to ' + text + ' ' + checked.length + ' user(s)?', type: action === 'restore' ? 'success' : 'danger', confirmText: action === 'restore' ? 'Restore' : 'Delete', onConfirm: function() { bulkForm.submit(); } });
        });
    }
})();
