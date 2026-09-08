export function recoveredReleases() {
    return {
        expandedId: null,
        allFiles: false,

        isExpanded(id) {
            return this.expandedId === id;
        },

        toggleDetails(id) {
            this.expandedId = this.isExpanded(id) ? null : id;
            this.allFiles = false;
        },

        toggleFiles() {
            this.allFiles = !this.allFiles;
        },

        submitFilters(event) {
            event.currentTarget.form.requestSubmit();
        },
    };
}
