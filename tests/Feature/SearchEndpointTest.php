<?php

use App\Models\Product;
use App\Search\VectorMath;
use Database\Seeders\CatalogSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed(CatalogSeeder::class);
});

/** A 512-dimension unit vector pointing along one axis. */
function axisVector(int $axis): array
{
    $vector = array_fill(0, 512, 0.0);
    $vector[$axis] = 1.0;

    return $vector;
}

function fakeEmbeddings(array $vector): void
{
    Http::fake(['api.openai.com/*' => Http::response([
        'data' => [['index' => 0, 'embedding' => $vector]],
    ])]);
}

it('returns products with image, name and price', function () {
    $this->getJson('/api/search?q=שואב אבק')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['sku', 'name', 'brand', 'image_url', 'price', 'price_is_demo']],
            'meta' => ['current_page', 'per_page', 'total'],
            'search' => ['query', 'mode', 'match', 'degraded', 'took_ms'],
        ])
        ->assertJsonPath('data.0.price_is_demo', true)
        ->assertJsonPath('search.match', 'exact');
});

it('finds Apple phones for "אייפון" through the alias dictionary', function () {
    $skus = collect($this->getJson('/api/search?q=אייפון')->json('data'))->take(3)->pluck('sku');

    expect($skus->sort()->values()->all())->toBe(['P0401', 'P0407', 'P0413']);
});

it('paginates the ranked results', function () {
    $first = $this->getJson('/api/search?q=אוזניות')->assertOk();
    $second = $this->getJson('/api/search?q=אוזניות&page=2')->assertOk();

    expect($first->json('meta.total'))->toBeGreaterThan(12)
        ->and($first->json('data'))->toHaveCount(12)
        ->and($second->json('meta.current_page'))->toBe(2)
        ->and(array_intersect(
            array_column($first->json('data'), 'sku'),
            array_column($second->json('data'), 'sku'),
        ))->toBeEmpty();
});

it('returns an empty list for an empty query', function () {
    $this->getJson('/api/search?q=')
        ->assertOk()
        ->assertJsonPath('meta.total', 0);
});

it('returns no results for gibberish instead of the nearest junk', function () {
    $this->getJson('/api/search?q=asdkjqwe')
        ->assertOk()
        ->assertJsonPath('meta.total', 0);
});

it('rejects a query that is too long', function () {
    $this->getJson('/api/search?q='.str_repeat('א', 201))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('q');
});

it('treats script tags as plain text', function () {
    $this->getJson('/api/search?q='.urlencode('<script>alert(1)</script>'))
        ->assertOk()
        ->assertJsonPath('search.query', '<script>alert(1)</script>')
        ->assertJsonPath('meta.total', 0);
});

it('ranks by meaning when vectors are available', function () {
    Product::where('sku', 'P0950')->first()
        ->forceFill(['embedding' => VectorMath::pack(axisVector(0))])->save();
    Product::where('sku', 'P0001')->first()
        ->forceFill(['embedding' => VectorMath::pack(axisVector(1))])->save();
    fakeEmbeddings(axisVector(0));

    $this->getJson('/api/search?q='.urlencode('משהו שיעזור לנקות שערות של כלב מהספה'))
        ->assertOk()
        ->assertJsonPath('search.mode', 'hybrid')
        ->assertJsonPath('search.degraded', false)
        ->assertJsonPath('data.0.sku', 'P0950')
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('search.match', 'related');
});

/** A unit vector whose similarity to axisVector(0) is $similarity. */
function vectorAt(float $similarity): array
{
    $vector = array_fill(0, 512, 0.0);
    $vector[0] = $similarity;
    $vector[1] = sqrt(1 - $similarity ** 2);

    return $vector;
}

it('drops meaning-only results from subcategories the query is not about', function (bool $coherence, bool $kept) {
    config(['search.subcategory_coherence' => $coherence]);

    foreach (['P0937' => 1.0, 'P0936' => 0.97, 'P0940' => 0.96, 'P0939' => 0.95, 'P0949' => 0.94] as $sku => $similarity) {
        Product::where('sku', $sku)->first()->forceFill(['embedding' => VectorMath::pack(vectorAt($similarity))])->save();
    }
    fakeEmbeddings(axisVector(0));

    $skus = array_column($this->getJson('/api/search?q=xyzzy')->assertOk()->json('data'), 'sku');

    // Four dog products (כלבים) lead; the small-pet carrier (כללי לחיות מחמד) is off-topic.
    expect($skus)->toContain('P0937', 'P0936', 'P0940', 'P0939')
        ->and(in_array('P0949', $skus, true))->toBe($kept);
})->with([
    'coherence on' => [true, false],
    'coherence off' => [false, true],
]);

it('falls back to keywords when the embedding provider fails', function () {
    Product::where('sku', 'P0950')->first()
        ->forceFill(['embedding' => VectorMath::pack(axisVector(0))])->save();
    Http::fake(['api.openai.com/*' => Http::response('down', 500)]);

    $this->getJson('/api/search?q=שואב אבק')
        ->assertOk()
        ->assertJsonPath('search.mode', 'keyword')
        ->assertJsonPath('search.degraded', true)
        ->assertJsonPath('data.0.subcategory', 'שואבי אבק ורובוטים');
});
