<?php

namespace App\Providers;

use App\Search\Embedder;
use App\Search\KeywordRanker;
use App\Search\OpenAiEmbedder;
use App\Search\ProductSearch;
use App\Search\SearchIndex;
use App\Search\TextNormalizer;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TextNormalizer::class, fn () => new TextNormalizer(
            config('search.stopwords'),
        ));

        $this->app->singleton(KeywordRanker::class, fn ($app) => new KeywordRanker(
            $app->make(TextNormalizer::class),
            config('search.field_weights'),
            config('search.aliases'),
            config('search.keyword_min_coverage'),
        ));

        $this->app->bind(Embedder::class, fn () => new OpenAiEmbedder(
            config('services.openai.key'),
            config('services.openai.base_url'),
            config('search.embeddings.model'),
            config('search.embeddings.dimensions'),
            config('search.embeddings.query_timeout'),
        ));

        $this->app->scoped(SearchIndex::class, fn ($app) => new SearchIndex(
            $app->make(TextNormalizer::class),
            config('search.index_cache_store'),
        ));

        $this->app->bind(ProductSearch::class, fn ($app) => new ProductSearch(
            $app->make(SearchIndex::class),
            $app->make(KeywordRanker::class),
            $app->make(Embedder::class),
            config('search.embeddings'),
            config('search.vector_min_similarity'),
            config('search.rrf_k'),
            config('search.max_results'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
