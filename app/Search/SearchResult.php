<?php

namespace App\Search;

class SearchResult
{
    public const MODE_HYBRID = 'hybrid';

    public const MODE_KEYWORD = 'keyword';

    /**
     * @param  list<int>  $ids  Ranked product ids.
     * @param  array<string, int>  $timings  Milliseconds per stage.
     */
    public function __construct(
        public readonly array $ids,
        public readonly string $mode,
        public readonly array $timings = [],
        public readonly ?float $topSimilarity = null,
    ) {}
}
