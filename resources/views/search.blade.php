@php
    $config = [
        'endpoint' => route('api.search'),
        'fallbackImage' => 'data:image/svg+xml,'.rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 4 3"><rect width="4" height="3" fill="#f1f1f2"/></svg>'),
        'text' => [
            'foundOne' => __('One product found'),
            'foundMany' => __(':count products found'),
            'showing' => __('Showing :shown of :total'),
            'seconds' => __(':seconds seconds'),
            'errorGeneric' => __('Something went wrong. Please try again.'),
            'errorTooLong' => __('That search is too long. Try a shorter description.'),
            'errorRateLimited' => __('Too many searches in a row. Try again in a minute.'),
        ],
    ];

    $examples = [
        __('Vacuum cleaner'),
        'iPhone 16',
        __('iPhone (Hebrew)'),
        __('A gift for someone who loves to cook'),
        __('Something to clean dog hair off the sofa'),
        'gift for a coffee lover',
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Smart product search') }} · htzone</title>
    <meta name="description" content="{{ __('Describe what you are looking for, and we will find matching products') }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Heebo:wght@400;500;700;800&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen">
    <div x-data="productSearch(@js($config))" class="flex min-h-screen flex-col">
        <header class="border-b border-line bg-white">
            <div class="mx-auto flex h-16 max-w-6xl items-center justify-between px-4">
                <a href="{{ url('/') }}" dir="ltr" class="flex items-baseline gap-0.5 text-2xl font-extrabold tracking-tight" aria-label="htzone">
                    <span dir="ltr">htzone</span><span class="inline-block size-2 rounded-full bg-accent" aria-hidden="true"></span>
                </a>
                <span class="text-sm text-muted">{{ __('Smart product search') }}</span>
            </div>
        </header>

        <main class="mx-auto w-full max-w-6xl flex-1 px-4 pb-16">
            {{-- Hero + search --}}
            <section class="pt-12 text-center sm:pt-16">
                <h1 class="text-4xl font-extrabold tracking-tight sm:text-6xl">{{ __('What would you like to find?') }}</h1>
                <p class="mt-3 text-lg text-muted sm:text-xl">{{ __('Describe what you are looking for, and we will find matching products') }}</p>

                <form role="search" class="mx-auto mt-8 flex max-w-3xl items-center gap-2 rounded-full border border-line bg-white p-1.5 shadow-sm focus-within:border-ink"
                      x-on:submit.prevent="submit()">
                    <label for="q" class="sr-only">{{ __('Search products') }}</label>
                    <div class="relative flex-1">
                        <input id="q" x-ref="input" x-model="query" type="search" name="q" maxlength="200"
                               autocomplete="off" enterkeyhint="search" autofocus
                               placeholder="{{ __('e.g. a gift for someone who loves to cook') }}"
                               class="w-full appearance-none bg-transparent py-3 ps-4 pe-10 text-lg outline-none placeholder:text-muted/70 [&::-webkit-search-cancel-button]:hidden">
                        <button type="button" x-show="query.length" x-cloak x-on:click="clear()"
                                class="absolute inset-y-0 end-2 my-auto grid size-8 place-items-center rounded-full text-muted hover:bg-tile hover:text-ink"
                                aria-label="{{ __('Clear search') }}">
                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
                        </button>
                    </div>
                    <button type="submit"
                            class="flex shrink-0 items-center gap-2 rounded-full bg-ink px-4 py-3 text-lg font-bold text-white transition hover:bg-black/80 disabled:opacity-60 sm:px-7"
                            x-bind:disabled="status === 'loading'" aria-label="{{ __('Search') }}">
                        <span class="hidden sm:inline">{{ __('Search') }}</span>
                        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
                    </button>
                </form>

                {{-- Idle: examples from the assignment --}}
                <div x-show="status === 'idle'" class="mx-auto mt-6 flex max-w-3xl flex-wrap items-center justify-center gap-2">
                    <span class="text-sm text-muted">{{ __('Try:') }}</span>
                    @foreach ($examples as $example)
                        <button type="button" x-on:click="tryExample(@js($example))"
                                class="rounded-full border border-line bg-white px-3.5 py-1.5 text-sm hover:border-ink">{{ $example }}</button>
                    @endforeach
                </div>
            </section>

            {{-- Screen-reader announcements --}}
            <p class="sr-only" aria-live="polite">
                <span x-show="status === 'loading'">{{ __('Searching…') }}</span>
                <span x-show="status === 'results'" x-text="countLabel"></span>
                <span x-show="status === 'empty'">{{ __('No products found') }}</span>
                <span x-show="status === 'error'" x-text="errorMessage"></span>
            </p>

            <section class="mt-12" x-show="status !== 'idle'" x-cloak>
                {{-- Results header --}}
                <div x-show="status === 'results'" class="mb-5 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                    <h2 class="text-2xl font-bold sm:text-3xl">{{ __('We found products for you') }}</h2>
                    <p class="text-sm text-muted"><span x-text="countLabel"></span> · <span x-text="secondsLabel"></span></p>
                </div>

                {{-- Meaning channel unavailable: results come from keywords only --}}
                <div x-show="status === 'results' && degraded" role="note"
                     class="mb-6 flex items-start gap-3 rounded-2xl bg-warn px-4 py-3 text-warn-ink">
                    <svg class="mt-0.5 size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 9v4M12 17h.01M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z"/></svg>
                    <p>{{ __('Smart search is unavailable right now, so these results match your words only.') }}
                        <button type="button" class="font-bold underline" x-on:click="retry()">{{ __('Try again') }}</button>
                    </p>
                </div>

                {{-- Results found by meaning only --}}
                <div x-show="status === 'results' && match === 'related' && !degraded" role="note"
                     class="mb-6 flex items-start gap-3 rounded-2xl bg-info px-4 py-3 text-info-ink">
                    <svg class="mt-0.5 size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8h.01M11 12h1v4h1"/></svg>
                    <p>{{ __('No exact match for') }}"<bdi class="font-bold" x-text="submittedQuery"></bdi>". {{ __('These products are the closest in meaning to your search.') }}</p>
                </div>

                {{-- Loading skeleton --}}
                <div x-show="status === 'loading'" class="grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-3 lg:grid-cols-4" aria-hidden="true">
                    @for ($i = 0; $i < 8; $i++)
                        <div class="overflow-hidden rounded-2xl border border-line bg-white">
                            <div class="skeleton aspect-square"></div>
                            <div class="space-y-3 p-4">
                                <div class="skeleton mx-auto h-4 w-4/5 rounded"></div>
                                <div class="skeleton mx-auto h-6 w-1/3 rounded"></div>
                            </div>
                        </div>
                    @endfor
                </div>

                {{-- Results grid --}}
                <ul x-show="status === 'results'" class="grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-3 lg:grid-cols-4">
                    <template x-for="product in results" x-bind:key="product.sku">
                        <li class="flex flex-col overflow-hidden rounded-2xl border border-line bg-white transition hover:shadow-md">
                            <div class="aspect-square bg-tile">
                                <img x-bind:src="product.image_url" x-bind:alt="product.name" x-on:error="imageFailed($event)"
                                     loading="lazy" decoding="async" class="size-full object-cover mix-blend-multiply">
                            </div>
                            <div class="flex flex-1 flex-col items-center gap-1 p-4 text-center">
                                <p x-show="product.brand" x-text="product.brand" class="text-xs text-muted" dir="auto"></p>
                                <h3 x-text="product.name" class="line-clamp-2 min-h-[2.75rem] font-medium leading-snug" dir="auto"></h3>
                                <p x-show="product.price !== null" x-text="formatPrice(product.price)" class="mt-auto pt-2 text-2xl font-extrabold"></p>
                            </div>
                        </li>
                    </template>
                </ul>

                {{-- Load more --}}
                <div x-show="status === 'results'" class="mt-8 flex flex-col items-center gap-3">
                    <p class="text-sm text-muted" x-text="progressLabel"></p>
                    <button type="button" x-show="hasMore" x-on:click="loadMore()" x-bind:disabled="loadingMore"
                            class="rounded-full border border-ink bg-white px-6 py-2.5 font-bold hover:bg-ink hover:text-white disabled:opacity-60">
                        <span x-show="!loadingMore">{{ __('Load more') }}</span>
                        <span x-show="loadingMore">{{ __('Loading…') }}</span>
                    </button>
                    <p x-show="loadMoreFailed" class="text-sm text-danger-ink">{{ __('Could not load more products.') }}
                        <button type="button" class="font-bold underline" x-on:click="loadMore()">{{ __('Try again') }}</button>
                    </p>
                </div>

                {{-- Empty --}}
                <div x-show="status === 'empty'" class="mx-auto max-w-xl rounded-3xl border border-line bg-white px-6 py-12 text-center">
                    <p class="text-2xl font-bold">{{ __('No products found for') }} "<bdi x-text="submittedQuery"></bdi>"</p>
                    <p x-show="!degraded" class="mt-2 text-muted">{{ __('Try other words, or describe what you need it for.') }}</p>
                    <div x-show="degraded" class="mt-3 text-muted">
                        <p>{{ __('Smart search is unavailable right now, and no product matches these exact words.') }}</p>
                        <button type="button" x-on:click="retry()"
                                class="mt-4 rounded-full bg-ink px-6 py-2.5 font-bold text-white hover:bg-black/80">{{ __('Try again') }}</button>
                    </div>
                    <div x-show="!degraded" class="mt-6 flex flex-wrap justify-center gap-2">
                        @foreach (array_slice($examples, 0, 4) as $example)
                            <button type="button" x-on:click="tryExample(@js($example))"
                                    class="rounded-full border border-line px-3.5 py-1.5 text-sm hover:border-ink">{{ $example }}</button>
                        @endforeach
                    </div>
                </div>

                {{-- Error --}}
                <div x-show="status === 'error'" role="alert" class="mx-auto max-w-xl rounded-3xl bg-danger px-6 py-10 text-center text-danger-ink">
                    <p class="text-xl font-bold" x-text="errorMessage"></p>
                    <button type="button" x-on:click="retry()"
                            class="mt-5 rounded-full bg-danger-ink px-6 py-2.5 font-bold text-white hover:opacity-90">{{ __('Try again') }}</button>
                </div>
            </section>
        </main>
    </div>
</body>
</html>
