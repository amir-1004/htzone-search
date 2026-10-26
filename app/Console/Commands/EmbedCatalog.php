<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Search\Embedder;
use App\Search\VectorMath;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Sleep;
use Throwable;

#[Signature('catalog:embed {--force : Re-embed every product, even unchanged ones}')]
#[Description('Compute meaning vectors for products whose text changed since the last run')]
class EmbedCatalog extends Command
{
    public function handle(Embedder $embedder): int
    {
        $pending = Product::query()
            ->select(['id', 'name', 'brand', 'category', 'subcategory', 'description', 'specs', 'embedding_hash'])
            ->get()
            ->filter(fn (Product $product) => $this->option('force')
                || $product->embedding_hash !== sha1($product->embeddingText()));

        if ($pending->isEmpty()) {
            $this->info('All products are already embedded.');

            return self::SUCCESS;
        }

        $this->info("Embedding {$pending->count()} products...");
        $bar = $this->output->createProgressBar($pending->count());

        foreach ($pending->chunk(config('search.embeddings.batch_size')) as $batch) {
            $texts = $batch->map->embeddingText()->values()->all();

            try {
                $vectors = $this->embedWithRetry($embedder, $texts);
            } catch (Throwable $e) {
                $this->newLine();
                $this->error('Embedding provider failed: '.($e instanceof RequestException
                    ? $e->response->status().' '.$e->response->json('error.message', '')
                    : $e->getMessage()));
                $this->line('Search keeps working on keywords only. Products embedded so far are saved.');

                return self::FAILURE;
            }

            foreach ($batch->values() as $i => $product) {
                $product->forceFill([
                    'embedding' => VectorMath::pack($vectors[$i]),
                    'embedding_hash' => sha1($texts[$i]),
                ])->save();
            }

            $bar->advance($batch->count());
        }

        $bar->finish();
        $this->newLine();
        $this->info('Done.');

        return self::SUCCESS;
    }

    /**
     * Waits out per-minute rate limits (new OpenAI accounts allow 40K tokens/min).
     * Any other failure, including an empty balance, is thrown immediately.
     *
     * @param  list<string>  $texts
     * @return list<list<float>>
     */
    private function embedWithRetry(Embedder $embedder, array $texts, int $attempts = 5): array
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $embedder->embed($texts, config('search.embeddings.batch_timeout'));
            } catch (RequestException $e) {
                if ($attempt >= $attempts || $e->response->json('error.code') !== 'rate_limit_exceeded') {
                    throw $e;
                }

                preg_match('/try again in ([\d.]+)(ms|s)/', (string) $e->response->json('error.message'), $match);
                $seconds = $match ? (float) $match[1] / ($match[2] === 'ms' ? 1000 : 1) : 20;

                Sleep::for((int) ceil($seconds) + 1)->seconds();
            }
        }
    }
}
