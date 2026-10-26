<?php

namespace App\Search;

class SearchResult
{
    public const MODE_HYBRID = 'hybrid';

    public const MODE_KEYWORD = 'keyword';

    /**
     * @param  list<int>  $ids  Ranked product ids.
     * @param  array<string, int>  $timings  Milliseconds per stage.
     * @param  int  $keywordHits  Products that matched the query's words, not only its meaning.
     * @param  bool  $degraded  The meaning channel should have run but the provider failed.
     */
    public function __construct(
        public readonly array $ids,
        public readonly string $mode,
        public readonly array $timings = [],
        public readonly ?float $topSimilarity = null,
        public readonly int $keywordHits = 0,
        public readonly bool $degraded = false,
    ) {}

    /**
     * "exact" when some results contain the query's words; "related" when every
     * result was found by meaning only, so the UI can say so instead of
     * pretending "כרטיס טיסה לאילת" was found.
     */
    public function match(): string
    {
        return $this->keywordHits > 0 || $this->ids === [] ? 'exact' : 'related';
    }
}
