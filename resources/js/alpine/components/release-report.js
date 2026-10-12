/**
 * Alpine.data('adminReleaseReports') - Admin release reports page
 */
import Alpine from '@alpinejs/csp';

Alpine.data('adminReleaseReports', () => ({
    descModalOpen: false,
    descContent: '',
    descReason: '',
    descReporter: '',
    revertModalOpen: false,
    revertActionUrl: '',
    revertStatus: '',
    responseModalOpen: false,
    responseActionUrl: '',
    responseContent: '',
    responseIsPublic: true,
    allChecked: false,
    selectedCount: 0,
    rootEl: null,

    init() {
        this.rootEl = this.$root;
        this.syncSelectionState();
    },

    showDescription(description, reason, reporter) {
        this.descContent = description || 'No additional details provided.';
        this.descReason = reason || 'Unknown';
        this.descReporter = reporter || 'Unknown';
        this.descModalOpen = true;
    },

    closeDescription() { this.descModalOpen = false; },

    showRevert(actionUrl, status) {
        this.revertActionUrl = actionUrl;
        this.revertStatus = status;
        this.revertModalOpen = true;
    },

    closeRevert() { this.revertModalOpen = false; },

    submitRevert() {
        const form = this.$refs.revertForm;
        if (form) { form.action = this.revertActionUrl; form.submit(); }
    },

    showResponse(actionUrl, response, isPublic) {
        this.responseActionUrl = actionUrl;
        this.responseContent = response || '';
        this.responseIsPublic = isPublic !== false;
        this.responseModalOpen = true;
    },

    closeResponse() { this.responseModalOpen = false; },

    responseCharCount() {
        return (this.responseContent || '').length;
    },

    submitResponse() {
        const form = this.$refs.responseForm;
        if (form) { form.action = this.responseActionUrl; form.submit(); }
    },

    componentRoot() {
        return this.rootEl || this.$root;
    },

    reportCheckboxes() {
        const root = this.componentRoot();
        return root ? [...root.querySelectorAll('.report-checkbox')] : [];
    },

    setAllSelection(checked) {
        const boxes = this.reportCheckboxes();
        boxes.forEach(cb => { cb.checked = checked; });
        this.allChecked = checked && boxes.length > 0;
        this.selectedCount = checked ? boxes.length : 0;
    },

    selectAll() {
        this.setAllSelection(true);
    },

    clearSelection() {
        this.setAllSelection(false);
    },

    toggleAll() {
        this.setAllSelection(this.allChecked);
    },

    syncSelectionState() {
        const boxes = this.reportCheckboxes();
        const checkedCount = boxes.filter(cb => cb.checked).length;
        this.selectedCount = checkedCount;
        this.allChecked = boxes.length > 0 && checkedCount === boxes.length;
    },

    onCheckboxChange() {
        this.syncSelectionState();
    },

    validateBulkAction(e) {
        const action = this.componentRoot()?.querySelector('select[name="action"]')?.value;
        const checkedCount = this.reportCheckboxes().filter(cb => cb.checked).length;
        if (!action) { e.preventDefault(); alert('Please select an action.'); return; }
        if (checkedCount === 0) { e.preventDefault(); alert('Please select at least one report.'); return; }
        if (action === 'delete') {
            if (!confirm('Are you sure you want to DELETE ' + checkedCount + ' release(s)? This action cannot be undone.')) e.preventDefault();
        }
    }
}));
