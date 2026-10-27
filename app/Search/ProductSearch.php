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
        private int $minVectorResults,
        private bool $coherence,
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
        $degraded = false;

        if ($useVectors && $this->index->hasVectors()) {
            $embedStart = hrtime(true);
            $queryVector = $this->embedQuery($query);
            $timings['embed_ms'] = $this->elapsed($embedStart);

            if ($queryVector !== null) {
                [$vectorRanking, $topSimilarity] = $this->rankByVector($queryVector, $documents);
            } else {
                $degraded = true;
            }
        }

        $rankStart = hrtime(true);
        ['scores' => $keywordRanking, 'complete' => $completeMatches] = $this->keywords->match($query, $documents);

        if ($vectorRanking !== null && $this->coherence) {
            [$vectorRanking, $keywordRanking] = $this->keepCoherent($vectorRanking, $keywordRanking, $completeMatches, $documents);
        }
        $ids = $this->fuse($vectorRanking, $keywordRanking);
        $timings['rank_ms'] = $this->elapsed($rankStart);
        $timings['total_ms'] = $this->elapsed($start);

        return new SearchResult(
            ids: array_slice($ids, 0, $this->maxResults),
            mode: $vectorRanking !== null ? SearchResult::MODE_HYBRID : SearchResult::MODE_KEYWORD,
            timings: $timings,
            topSimilarity: $topSimilarity,
            keywordHits: count($keywordRanking),
            degraded: $degraded,
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
        $rank = 0;

        // The margin keeps weak matches out, but one near-exact match ("מארז מתנה לאמא"
        // for "מתנה לאמא טריה") must not hide everything else: always keep the best few
        // that clear the absolute threshold. (A closure, not fn(): $rank must persist.)
        $kept = array_filter($similarities, function (float $similarity) use (&$rank, $cutoff) {
            return ++$rank <= $this->minVectorResults
                ? $similarity >= $this->minSimilarity
                : $similarity >= $cutoff;
        });

        return [$kept, $top];
    }

    /**
     * Products found by meaning only must share a subcategory with what the query
     * is clearly about. Otherwise "שעון יד" brings wall clocks and a cooking timer,
     * and "סכינים" brings cups and a juicer at the end of the list.
     *
     * The query's subcategories: those of products that contain every query term
     * when there are any ("סכינים" → knives, "טוחן קרח" → the blender whose
     * description says it crushes ice), otherwise those that repeat among the best
     * meaning hits ("שעון יד" → smartwatches), with the best three always kept so a
     * query that spans categories ("מתנה למישהו שאוהב לבשל") keeps its range.
     *
     * @param  array<int, float>  $vectorRanking
     * @param  array<int, float>  $keywordRanking
     * @param  array<int, true>  $completeMatches
     * @return array{0: array<int, float>, 1: array<int, float>} Vector and keyword rankings, filtered.
     */
    private function keepCoherent(array $vectorRanking, array $keywordRanking, array $completeMatches, array $documents): array
    {
        $subcategoryOf = fn (int $id) => $documents[$id]['subcategory'];

        if ($completeMatches !== []) {
            // The query's own words point at the subcategories. A few strays ("מגש גבינות
            // עם סכיני הגשה" for "סכינים") must not pull theirs in: keep a real share.
            $counts = array_count_values(array_map($subcategoryOf, array_keys($completeMatches)));
            $total = count($completeMatches);
            $focus = $total >= 3 ? array_filter($counts, fn (int $count) => $count / $total >= 0.25) : $counts;
            $core = [];
        } else {
            // Only meaning to go on: subcategories that repeat among the best hits, and the
            // best three always stay, so a query that spans categories keeps its range.
            $core = array_slice($vectorRanking, 0, 3, true);
            $focus = array_filter(
                array_count_values(array_map($subcategoryOf, array_keys(array_slice($vectorRanking, 0, 10, true)))),
                fn (int $count) => $count >= 2,
            );
        }

        // Products that contain every query term always stay. Everything else (meaning-only
        // hits, and partial keyword hits like a dashcam matching "דרך" in "קפה חם בדרך
        // לעבודה") must sit in the query's subcategories.
        $onTopic = fn (int $id) => isset($completeMatches[$id]) || isset($core[$id]) || isset($focus[$subcategoryOf($id)]);

        return [
            array_filter($vectorRanking, $onTopic, ARRAY_FILTER_USE_KEY),
            array_filter($keywordRanking, $onTopic, ARRAY_FILTER_USE_KEY),
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
