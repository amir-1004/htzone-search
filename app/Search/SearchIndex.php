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
    /**
     * Bump when the shape of a cached document changes, so a deploy never
     * reads an index built by older code.
     */
    private const FORMAT = 8;

    /** @var array<int, array{fields: array<string, array<string, true>>, vector: ?string, subcategory: string}>|null */
    private ?array $documents = null;

    public function __construct(
        private TextNormalizer $normalizer,
        private string $cacheStore,
    ) {}

    /**
     * @return array<int, array{fields: array<string, array<string, true>>, vector: ?string, subcategory: string}> Keyed by product id.
     */
    public function documents(): array
    {
        return $this->documents ??= Cache::store($this->cacheStore)->remember(
            'search-index:v'.self::FORMAT.':'.$this->version(),
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
            ->select(['id', 'name', 'brand', 'category', 'subcategory', 'description', 'specs', 'image_label', 'embedding'])
            ->lazyById(250)
            ->each(function (Product $product) use (&$documents) {
                $fields = [];

                foreach (['name', 'image_label', 'brand', 'subcategory', 'category', 'specs', 'description'] as $field) {
                    $tokens = [];
                    foreach ($this->normalizer->tokens((string) $product->{$field}) as $token) {
                        $tokens += $this->normalizer->variants($token);
                    }
                    $fields[$field] = array_fill_keys(array_keys($tokens), true);
                }

                $documents[$product->id] = [
                    'fields' => $fields,
                    'vector' => $product->embedding,
                    'subcategory' => $product->subcategory,
                ];
            });

        return $documents;
    }
}
