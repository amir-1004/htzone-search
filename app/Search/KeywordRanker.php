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
        $groups = $this->termGroups($query);

        if ($groups === []) {
            return [];
        }

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
                    $groupScore += $this->termScore($term, $document['fields']) / $maxWeight;
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
        }

        arsort($scores);

        return $scores;
    }

    /**
     * @param  array<string, array<string, true>>  $fields
     */
    private function termScore(string $term, array $fields): float
    {
        $best = 0.0;

        foreach ($this->normalizer->variants($term) as $variant => $confidence) {
            foreach ($fields as $field => $tokens) {
                $weight = ($this->fieldWeights[$field] ?? 1.0) * $confidence;

                if ($weight <= $best) {
                    continue;
                }

                if (isset($tokens[$variant])) {
                    $best = $weight;
                } elseif (mb_strlen($variant) >= 3 && $this->hasPrefixMatch($variant, $tokens)) {
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
