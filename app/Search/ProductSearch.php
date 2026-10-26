<?php

namespace App\Search;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Hybrid search: meaning (vectors) + words (keywords and aliases).
 *
 * Each channel ranks the products that pass its own threshold, and the two
 * rankings are merged with Reciprocal Rank Fusion. If the embedding provider
 * fails, the search degrades to keywords only instead of erroring.
 */
class ProductSearch
{
    /**
     * @param  array{model: string, dimensions: int, query_cache_ttl: int}  $embeddingConfig
     */
    public function __construct(
        private SearchIndex $index,
        private KeywordRanker $keywords,
        private Embedder $embedder,
        private array $embeddingConfig,
        private float $minSimilarity,
        private float $relativeMargin,
        private int $rrfK,
        private int $maxResults,
    ) {}

    public function search(string $query, bool $useVectors = true): SearchResult
    {
        $timings = [];
        $start = hrtime(true);

        $documents = $this->index->documents();
        $timings['index_ms'] = $this->elapsed($start);

        $vectorRanking = null;
        $topSimilarity = null;

        if ($useVectors && $this->index->hasVectors()) {
            $embedStart = hrtime(true);
            $queryVector = $this->embedQuery($query);
            $timings['embed_ms'] = $this->elapsed($embedStart);

            if ($queryVector !== null) {
                [$vectorRanking, $topSimilarity] = $this->rankByVector($queryVector, $documents);
            }
        }

        $rankStart = hrtime(true);
        $keywordRanking = $this->keywords->rank($query, $documents);
        $ids = $this->fuse($vectorRanking, $keywordRanking);
        $timings['rank_ms'] = $this->elapsed($rankStart);
        $timings['total_ms'] = $this->elapsed($start);

        return new SearchResult(
            ids: array_slice($ids, 0, $this->maxResults),
            mode: $vectorRanking !== null ? SearchResult::MODE_HYBRID : SearchResult::MODE_KEYWORD,
            timings: $timings,
            topSimilarity: $topSimilarity,
        );
    }

    /**
     * @return list<float>|null Null when the provider is unavailable.
     */
    private function embedQuery(string $query): ?array
    {
        $text = trim($query.' '.$this->keywords->expansionText($query));
        $key = 'query-embedding:'.md5("{$this->embeddingConfig['model']}|{$this->embeddingConfig['dimensions']}|{$text}");

        try {
            return Cache::remember(
                $key,
                $this->embeddingConfig['query_cache_ttl'],
                fn () => VectorMath::normalize($this->embedder->embed([$text])[0]),
            );
        } catch (Throwable $e) {
            Log::warning('search.embedding_failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param  list<float>  $queryVector
     * @return array{0: array<int, float>, 1: ?float} Hits above the threshold (best first), and the best similarity.
     */
    private function rankByVector(array $queryVector, array $documents): array
    {
        $similarities = [];

        foreach ($documents as $id => $document) {
            if ($document['vector'] !== null) {
                $similarities[$id] = VectorMath::dot($queryVector, VectorMath::unpack($document['vector']));
            }
        }

        arsort($similarities);

        if ($similarities === []) {
            return [[], null];
        }

        $top = reset($similarities);
        $cutoff = max($this->minSimilarity, $top - $this->relativeMargin);

        return [
            array_filter($similarities, fn (float $similarity) => $similarity >= $cutoff),
            $top,
        ];
    }

    /**
     * Reciprocal Rank Fusion: score = Σ weight / (k + rank). Rank-based, so the
     * two channels' scores never need to be on the same scale. Vector hits
     * count fully; keyword hits are weighted by how much of the query they
     * matched, so a product that only matches "מתנה" in a long sentence
     * cannot outrank a product that matches its meaning.
     *
     * @param  array<int, float>|null  $vectorRanking
     * @param  array<int, float>  $keywordRanking
     * @return list<int>
     */
    private function fuse(?array $vectorRanking, array $keywordRanking): array
    {
        $scores = [];

        foreach ([[$vectorRanking ?? [], false], [$keywordRanking, true]] as [$ranking, $weighted]) {
            $rank = 0;

            foreach ($ranking as $id => $score) {
                $weight = $weighted ? $score : 1.0;
                $scores[$id] = ($scores[$id] ?? 0) + $weight / ($this->rrfK + ++$rank);
            }
        }

        arsort($scores);

        return array_keys($scores);
    }

    private function elapsed(int $start): int
    {
        return (int) round((hrtime(true) - $start) / 1e6);
    }
}
