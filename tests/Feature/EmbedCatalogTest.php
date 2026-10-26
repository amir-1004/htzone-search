<?php

use App\Models\Product;
use Database\Seeders\CatalogSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed(CatalogSeeder::class);

    Http::fake(fn (Request $request) => Http::response([
        'data' => array_map(
            fn (int $i) => ['index' => $i, 'embedding' => array_fill(0, 512, 0.1)],
            array_keys($request['input']),
        ),
    ]));
});

it('embeds every product in batches', function () {
    $this->artisan('catalog:embed')->assertSuccessful();

    expect(Product::whereNull('embedding')->count())->toBe(0);
    Http::assertSentCount(10);
});

it('skips products whose text has not changed', function () {
    $this->artisan('catalog:embed');
    Product::where('sku', 'P0001')->update(['description' => 'תיאור חדש']);

    $this->artisan('catalog:embed')->assertSuccessful();

    Http::assertSentCount(11);
    Http::assertSent(fn (Request $request) => count($request['input']) === 1);
});

it('re-embeds every product when the model changes', function () {
    $this->artisan('catalog:embed');
    config(['search.embeddings.model' => 'another-embedding-model']);

    $this->artisan('catalog:embed')->assertSuccessful();

    Http::assertSentCount(20);
});
