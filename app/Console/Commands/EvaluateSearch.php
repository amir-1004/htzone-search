<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Search\ProductSearch;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('search:eval
    {--keyword-only : Skip the embedding channel, for a baseline}
    {--file=tests/golden/queries.json : Golden set to run}')]
#[Description('Run the golden query set and report relevance and latency per query')]
class EvaluateSearch extends Command
{
    public function handle(ProductSearch $search): int
    {
        $golden = json_decode(file_get_contents(base_path($this->option('file'))), true, flags: JSON_THROW_ON_ERROR);
        $k = $golden['k'];
        $rows = [];
        $passed = 0;
        $latencies = [];

        foreach ($golden['queries'] as $case) {
            $result = $search->search($case['query'], useVectors: ! $this->option('keyword-only'));
            $topIds = array_slice($result->ids, 0, $k);
            $top = Product::findMany($topIds)->sortBy(fn (Product $p) => array_search($p->id, $topIds))->values();

            if ($case['expect_empty'] ?? false) {
                $ok = $result->ids === [];
                $score = $ok ? 'empty' : count($result->ids).' found';
            } else {
                $relevant = $top->filter(fn (Product $p) => in_array($p->sku, $case['relevant_ids'] ?? [], true)
                    || in_array($p->subcategory, $case['relevant_subcategories'] ?? [], true)
                    || in_array($p->category, $case['relevant_categories'] ?? [], true))->count();
                $ok = $relevant >= $case['min_relevant_in_top_k'];
                $score = "{$relevant}/{$k} (need {$case['min_relevant_in_top_k']})";
            }

            $passed += (int) $ok;
            $latencies[] = $result->timings['total_ms'];

            $rows[] = [
                $ok ? '<info>PASS</info>' : '<error>FAIL</error>',
                $case['id'],
                mb_strimwidth($case['query'], 0, 32, '…'),
                $score,
                $result->mode,
                $result->topSimilarity !== null ? number_format($result->topSimilarity, 3) : '-',
                $result->timings['total_ms'],
                mb_strimwidth($top->take(3)->pluck('name')->implode(' | '), 0, 70, '…'),
            ];
        }

        $this->table(['', 'id', 'query', 'relevant', 'mode', 'top sim', 'ms', 'top 3'], $rows);

        sort($latencies);
        $p50 = $latencies[intdiv(count($latencies), 2)];
        $p95 = $latencies[(int) floor(count($latencies) * 0.95) - 1];

        $this->info(sprintf('Passed %d/%d · p50 %d ms · p95 %d ms', $passed, count($rows), $p50, $p95));

        return $passed === count($rows) ? self::SUCCESS : self::FAILURE;
    }
}
