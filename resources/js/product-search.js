/**
 * Search page state: submit → GET /api/search, "load more" pagination,
 * and the idle / loading / results / empty / error states.
 *
 * All user-facing text arrives from Blade (lang/he.json) in `config.text`,
 * and every server value is rendered with x-text, never x-html.
 */
export default (config) => ({
    query: '',
    submittedQuery: '',
    status: 'idle', // idle | loading | results | empty | error
    results: [],
    total: 0,
    page: 0,
    hasMore: false,
    match: 'exact',
    degraded: false,
    tookMs: 0,
    loadingMore: false,
    loadMoreFailed: false,
    errorMessage: '',
    controller: null,

    init() {
        const initial = new URL(window.location.href).searchParams.get('q');

        if (initial) {
            this.query = initial;
            this.run(initial);
        }

        window.addEventListener('popstate', () => {
            const q = new URL(window.location.href).searchParams.get('q') ?? '';
            this.query = q;
            q ? this.run(q, { updateUrl: false }) : this.reset();
        });
    },

    submit() {
        const q = this.query.trim();

        if (q === '') {
            this.$refs.input.focus();
            return;
        }

        this.run(q);
    },

    tryExample(example) {
        this.query = example;
        this.run(example);
    },

    clear() {
        this.query = '';
        this.reset();
        history.pushState({}, '', window.location.pathname);
        this.$refs.input.focus();
    },

    reset() {
        this.controller?.abort();
        Object.assign(this, { status: 'idle', submittedQuery: '', results: [], total: 0, page: 0, hasMore: false });
    },

    async run(q, { updateUrl = true } = {}) {
        if (updateUrl) {
            history.pushState({}, '', `?q=${encodeURIComponent(q)}`);
        }

        this.submittedQuery = q;
        this.status = 'loading';
        this.results = [];

        const response = await this.fetchPage(q, 1);

        if (response) {
            this.apply(response, 1);
        }
    },

    async loadMore() {
        this.loadingMore = true;
        this.loadMoreFailed = false;

        const response = await this.fetchPage(this.submittedQuery, this.page + 1, { keepResults: true });

        this.loadingMore = false;

        if (response) {
            this.apply(response, this.page + 1);
        }
    },

    retry() {
        this.run(this.submittedQuery, { updateUrl: false });
    },

    async fetchPage(q, page, { keepResults = false } = {}) {
        this.controller?.abort();
        this.controller = new AbortController();

        const url = `${config.endpoint}?${new URLSearchParams({ q, page })}`;

        try {
            const response = await fetch(url, {
                headers: { Accept: 'application/json' },
                signal: this.controller.signal,
            });

            if (!response.ok) {
                throw Object.assign(new Error(this.messageFor(response.status)), { friendly: true });
            }

            return await response.json();
        } catch (error) {
            if (error.name === 'AbortError') {
                return null;
            }

            if (keepResults) {
                this.loadMoreFailed = true;
            } else {
                // Network failures ("Failed to fetch") get the generic Hebrew message.
                this.errorMessage = error.friendly ? error.message : config.text.errorGeneric;
                this.status = 'error';
            }

            return null;
        }
    },

    apply(response, page) {
        this.results = page === 1 ? response.data : [...this.results, ...response.data];
        this.total = response.meta.total;
        this.page = response.meta.current_page;
        this.hasMore = response.meta.current_page < response.meta.last_page;
        this.match = response.search.match;
        this.degraded = response.search.degraded;
        this.tookMs = response.search.took_ms;
        this.status = this.results.length ? 'results' : 'empty';
    },

    messageFor(status) {
        if (status === 422) return config.text.errorTooLong;
        if (status === 429) return config.text.errorRateLimited;

        return config.text.errorGeneric;
    },

    get countLabel() {
        const template = this.total === 1 ? config.text.foundOne : config.text.foundMany;

        return template.replace(':count', this.total.toLocaleString('he-IL'));
    },

    get progressLabel() {
        return config.text.showing
            .replace(':shown', this.results.length.toLocaleString('he-IL'))
            .replace(':total', this.total.toLocaleString('he-IL'));
    },

    get secondsLabel() {
        return config.text.seconds.replace(':seconds', (this.tookMs / 1000).toFixed(2));
    },

    formatPrice(price) {
        return new Intl.NumberFormat('he-IL', {
            style: 'currency',
            currency: 'ILS',
            maximumFractionDigits: 0,
        }).format(price);
    },

    imageFailed(event) {
        event.target.src = config.fallbackImage;
    },
});
