<?php

namespace App\Search;

use App\Models\Product;
use Illuminate\Support\Facades\Cache;

/**
 * The whole catalog, prepared for ranking in memory: tokens per field and
 * packed vectors. Built from the database and cached under a version key
 * that changes whenever products change, so it is never stale.
 */
class SearchIndex
{
    /** @var array<int, array{fields: array<string, array<string, true>>, vector: ?string}>|null */
    private ?array $documents = null;

    public function __construct(
        private TextNormalizer $normalizer,
        private string $cacheStore,
    ) {}

    /**
     * @return array<int, array{fields: array<string, array<string, true>>, vector: ?string}> Keyed by product id.
     */
    public function documents(): array
    {
        return $this->documents ??= Cache::store($this->cacheStore)->remember(
            'search-index:'.$this->version(),
            now()->addDay(),
            fn () => $this->build(),
        );
    }

    public function hasVectors(): bool
    {
        foreach ($this->documents() as $document) {
            if ($document['vector'] !== null) {
                return true;
            }
        }

        return false;
    }

    private function version(): string
    {
        $state = Product::query()
            ->selectRaw('count(*) as total, max(updated_at) as updated')
            ->first();

        return md5("{$state->total}|{$state->updated}");
    }

    private function build(): array
    {
        $documents = [];

        Product::query()
            ->select(['id', 'name', 'brand', 'category', 'subcategory', 'description', 'specs', 'embedding'])
            ->lazyById(250)
            ->each(function (Product $product) use (&$documents) {
                $fields = [];

                foreach (['name', 'brand', 'subcategory', 'category', 'specs', 'description'] as $field) {
                    $fields[$field] = array_fill_keys($this->normalizer->tokens((string) $product->{$field}), true);
                }

                $documents[$product->id] = [
                    'fields' => $fields,
                    'vector' => $product->embedding,
                ];
            });

        return $documents;
    }
}
