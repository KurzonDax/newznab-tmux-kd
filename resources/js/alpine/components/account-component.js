export function accountPage() {
    return {
        init() {
            const section = window.location.hash.slice(1);
            if (['profile', 'appearance', 'security', 'api', 'downloads', 'privacy', 'invitations'].includes(section) && this.$el.dataset.accountSection !== section) {
                window.location.replace('/account?section=' + section);
            }
        },
        setTheme(event) { this.$store.theme.set(event.currentTarget.dataset.theme); },
    };
}

export function accountRss() {
    return {
        rssRoot: null, feed: 'full-feed', category: '', count: '25', downloads: false, url: '',
        init() { this.rssRoot = this.$el; this.category = this.rssRoot.querySelector('#feed-category')?.value || ''; this.build(); },
        build() {
            const url = new URL(this.rssRoot.dataset.rssBase + '/' + this.feed);
            url.searchParams.set('api_token', this.rssRoot.dataset.apiToken);
            url.searchParams.set(['mymovies', 'myshows'].includes(this.feed) ? 'num' : 'limit', this.count);
            if (this.feed === 'category' && this.category) url.searchParams.set('id', this.category);
            if (this.downloads) url.searchParams.set('dl', '1');
            this.url = url.toString();
        },
    };
}
