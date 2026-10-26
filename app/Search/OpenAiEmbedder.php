<?php

namespace App\Search;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAiEmbedder implements Embedder
{
    public function __construct(
        private ?string $apiKey,
        private string $baseUrl,
        private string $model,
        private int $dimensions,
        private float $defaultTimeout,
    ) {}

    public function embed(array $texts, ?float $timeout = null): array
    {
        if (blank($this->apiKey)) {
            throw new RuntimeException('OPENAI_API_KEY is not configured.');
        }

        $data = Http::withToken($this->apiKey)
            ->acceptJson()
            ->timeout($timeout ?? $this->defaultTimeout)
            ->post(rtrim($this->baseUrl, '/').'/embeddings', [
                'model' => $this->model,
                'input' => $texts,
                'dimensions' => $this->dimensions,
            ])
            ->throw()
            ->json('data');

        if (! is_array($data) || count($data) !== count($texts)) {
            throw new RuntimeException('Unexpected embeddings response.');
        }

        usort($data, fn (array $a, array $b) => $a['index'] <=> $b['index']);

        return array_map(fn (array $item) => $item['embedding'], $data);
    }
}
