import Alpine from '@alpinejs/csp';

Alpine.data('blacklistSweep', () => ({
    mode: 'dry-run',
    running: false,
    summary: 'No admin-triggered sweep has run yet.',
    pollTimer: null,

    init() {
        const initial = JSON.parse(this.$el.dataset.initialStatus || '{}');
        this.applyStatus(initial);
        if (this.running) this.beginPolling();
    },

    confirmSweep(ruleId = null) {
        if (this.running) return;
        const deleting = this.mode === 'delete';
        const scope = ruleId === null ? 'all enabled blacklist rules' : 'this blacklist rule only';
        window.showConfirm({
            title: deleting ? 'Permanently delete matching releases?' : 'Run blacklist dry-run?',
            message: deleting
                ? 'This permanently removes every release matching ' + scope + ', across all time.'
                : 'This reports releases matching ' + scope + ', across all time. No releases are deleted.',
            type: deleting ? 'danger' : 'warning',
            confirmText: deleting ? 'Delete releases' : 'Run dry-run',
            onConfirm: () => this.start(ruleId),
        });
    },

    async start(ruleId) {
        const payload = { mode: this.mode };
        if (ruleId !== null) payload.rule_id = ruleId;

        const response = await fetch(this.$el.dataset.startUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            body: JSON.stringify(payload),
        });
        const data = await response.json();
        if (!response.ok) {
            window.showToast(data.message || 'Unable to start blacklist sweep.', 'error');
            await this.refresh();
            return;
        }

        this.applyStatus({ running: true, current: data.run, last: null });
        window.showToast(data.message, 'success');
        this.beginPolling();
    },

    async refresh() {
        const response = await fetch(this.$el.dataset.statusUrl, { headers: { 'Accept': 'application/json' } });
        if (response.ok) this.applyStatus(await response.json());
    },

    applyStatus(status) {
        this.running = Boolean(status.running);
        const run = status.current || status.last;
        if (!run) {
            this.summary = 'No admin-triggered sweep has run yet.';
            return;
        }

        const scope = run.rule_id === null ? 'all rules' : 'rule #' + run.rule_id;
        const counts = run.mode === 'delete'
            ? run.removed_count + ' removed'
            : run.matched_count + ' matched';
        const duration = this.running ? '' : ' in ' + this.formatDuration(run.started_at, run.finished_at);
        this.summary = this.running
            ? 'Running ' + run.mode + ' sweep for ' + scope + ' since ' + run.started_at + ' — ' + counts
            : 'Last sweep: ' + run.mode + ' for ' + scope + ', finished ' + run.finished_at + duration + ' — ' + counts + ' (exit ' + run.exit_code + ')';

        if (!this.running && this.pollTimer) {
            clearInterval(this.pollTimer);
            this.pollTimer = null;
        }
    },

    beginPolling() {
        if (this.pollTimer) return;
        this.pollTimer = setInterval(() => this.refresh(), 3000);
    },

    formatDuration(startedAt, finishedAt) {
        const seconds = Math.max(0, Math.round((Date.parse(finishedAt) - Date.parse(startedAt)) / 1000));
        if (seconds < 60) return seconds + 's';

        const minutes = Math.floor(seconds / 60);
        return minutes + 'm ' + (seconds % 60) + 's';
    },
}));
