<div {{ $attributes->merge(['class' => 'mx-6 mb-4 rounded-lg border border-primary-200 dark:border-primary-800 bg-primary-50 dark:bg-primary-950/40 px-4 py-3 text-sm text-primary-900 dark:text-primary-100']) }}>
    <i class="fas fa-spinner fa-spin mr-2" x-show="running" x-cloak></i>
    <i class="fas fa-circle-info mr-2" x-show="!running"></i>
    <span x-text="summary"></span>
    <span class="ml-2 text-primary-700 dark:text-primary-300" x-show="running">Sweep controls are disabled while this run finishes.</span>
</div>
