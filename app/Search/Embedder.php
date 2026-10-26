<?php

namespace App\Search;

/**
 * Turns texts into meaning vectors. Bound in AppServiceProvider, so the
 * provider can be swapped (or faked in tests) without touching the search.
 */
interface Embedder
{
    /**
     * @param  list<string>  $texts
     * @return list<list<float>> One vector per text, in the same order.
     *
     * @throws \Throwable When the provider is unavailable; callers fall back to keywords.
     */
    public function embed(array $texts, ?float $timeout = null): array;
}
