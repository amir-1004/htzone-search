<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Search\Embedder;
use App\Search\VectorMath;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
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
                $vectors = $embedder->embed($texts, config('search.embeddings.batch_timeout'));
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
}
