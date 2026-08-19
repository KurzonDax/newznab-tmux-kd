import Alpine from '@alpinejs/csp';

Alpine.data('posterIdentityBlacklist', () => ({
    confirmationOpen: false,
    deleteReleases: false,

    get dangerClasses() {
        return this.deleteReleases
            ? 'border-red-500 bg-red-50 dark:border-red-700 dark:bg-red-950/40'
            : 'border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-900';
    },

    openConfirmation() {
        this.confirmationOpen = true;
    },

    closeConfirmation() {
        this.confirmationOpen = false;
        this.deleteReleases = false;
    },
}));
