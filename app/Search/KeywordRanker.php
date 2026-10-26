<?php

namespace App\Search;

/**
 * Scores products by how well their fields match the query terms.
 *
 * Each query term (or its alias group) gets the best weighted match across
 * the product's fields: exact token 1.0, token prefix 0.75 (שואב → שואבי).
 * A product must cover enough of the query's terms to count as a hit.
 */
class KeywordRanker
{
    /** @var array<string, string> */
    private array $aliases = [];

    /**
     * @param  array<string, float>  $fieldWeights
     * @param  array<string, string>  $aliases
     */
    public function __construct(
        private TextNormalizer $normalizer,
        private array $fieldWeights,
        array $aliases,
        private float $minCoverage,
    ) {
        foreach ($aliases as $term => $expansion) {
            $this->aliases[$this->normalizer->normalize($term)] = $expansion;
        }
    }

    /**
     * Query terms, each with the catalog terms that can satisfy it.
     *
     * @return list<list<string>> One group per query term; a group of several terms is an alias expansion.
     */
    public function termGroups(string $query): array
    {
        [$phraseExpansions, $rest] = $this->extractPhrases($query);

        return [
            ...array_map(fn (string $expansion) => $this->normalizer->tokens($expansion), $phraseExpansions),
            ...array_map(
                fn (string $token) => isset($this->aliases[$token])
                    ? $this->normalizer->tokens($this->aliases[$token])
                    : [$token],
                $this->normalizer->tokens($rest),
            ),
        ];
    }

    /**
     * Alias expansions of the query, to enrich the text sent to the embedding model.
     */
    public function expansionText(string $query): string
    {
        [$phraseExpansions, $rest] = $this->extractPhrases($query);

        return implode(' ', [
            ...$phraseExpansions,
            ...array_filter(array_map(fn (string $token) => $this->aliases[$token] ?? null, $this->normalizer->tokens($rest))),
        ]);
    }

    /**
     * Multi-word aliases ("אמא טריה") are matched on the normalized query before
     * it is split into tokens.
     *
     * @return array{0: list<string>, 1: string} Expansions found, and the query without those phrases.
     */
    private function extractPhrases(string $query): array
    {
        $normalized = ' '.$this->normalizer->normalize($query).' ';
        $expansions = [];

        foreach ($this->aliases as $phrase => $expansion) {
            if (str_contains($phrase, ' ') && str_contains($normalized, " {$phrase} ")) {
                $expansions[] = $expansion;
                $normalized = str_replace(" {$phrase} ", ' ', $normalized);
            }
        }

        return [$expansions, $normalized];
    }

    /**
     * @param  array<int, array{fields: array<string, array<string, true>>}>  $documents  Keyed by product id.
     * @return array<int, float> Product id => score in [0, 1], best first, hits only.
     */
    public function rank(string $query, array $documents): array
    {
        return $this->match($query, $documents)['scores'];
    }

    /**
     * Scores, plus the products that contain every term of the query ("טוחן קרח"
     * in a blender's description): a strong signal even when the match is weak
     * in field weight.
     *
     * @param  array<int, array{fields: array<string, array<string, true>>}>  $documents
     * @return array{scores: array<int, float>, complete: array<int, true>}
     */
    public function match(string $query, array $documents): array
    {
        $groups = $this->termGroups($query);

        if ($groups === []) {
            return ['scores' => [], 'complete' => []];
        }

        // Spelling skeletons are ambiguous ("סכינים" and "מסוכנים" share one), so they
        // are a fallback: used only when no product contains every term as written.
        $strict = $this->matchGroups($groups, $documents, minConfidence: 0.7);

        return $strict['complete'] !== [] ? $strict : $this->matchGroups($groups, $documents, minConfidence: 0.0);
    }

    /**
     * @param  list<list<string>>  $groups
     * @return array{scores: array<int, float>, complete: array<int, true>}
     */
    private function matchGroups(array $groups, array $documents, float $minConfidence): array
    {
        $complete = [];

        $maxWeight = max($this->fieldWeights);
        $scores = [];

        // A short query must match in full ("שואב אבק" is not every product with dust in
        // its description); longer, conversational queries need a share of their terms.
        $required = max(min(count($groups), 2), (int) ceil(count($groups) * $this->minCoverage));

        foreach ($documents as $id => $document) {
            $total = 0.0;
            $covered = 0;

            foreach ($groups as $terms) {
                $groupScore = 0.0;

                foreach ($terms as $term) {
                    $groupScore += $this->termScore($term, $document['fields'], $minConfidence) / $maxWeight;
                }

                $groupScore /= count($terms);

                if ($groupScore > 0) {
                    $covered++;
                    $total += $groupScore;
                }
            }

            if ($covered >= $required) {
                $scores[$id] = $total / count($groups);
            }

            if ($covered === count($groups)) {
                $complete[$id] = true;
            }
        }

        arsort($scores);

        return ['scores' => $scores, 'complete' => $complete];
    }

    /**
     * @param  array<string, array<string, true>>  $fields
     */
    private function termScore(string $term, array $fields, float $minConfidence): float
    {
        $best = 0.0;

        foreach ($this->normalizer->variants($term) as $variant => $confidence) {
            if ($confidence < $minConfidence) {
                continue;
            }

            foreach ($fields as $field => $tokens) {
                $weight = ($this->fieldWeights[$field] ?? 1.0) * $confidence;

                if ($weight <= $best) {
                    continue;
                }

                if (isset($tokens[$variant])) {
                    $best = $weight;
                } elseif ($confidence >= 0.7 && mb_strlen($variant) >= 3 && $this->hasPrefixMatch($variant, $tokens)) {
                    // Prefixes only for real word forms: a spelling skeleton ("סכנ") would catch "סכנה".
                    $best = max($best, $weight * 0.75);
                }
            }
        }

        return $best;
    }

    /**
     * @param  array<string, true>  $tokens
     */
    private function hasPrefixMatch(string $prefix, array $tokens): bool
    {
        foreach ($tokens as $token => $_) {
            if (str_starts_with($token, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
