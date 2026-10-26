<?php

use App\Models\Product;
use Database\Seeders\CatalogSeeder;

it('imports every product from the catalog CSV', function () {
    $this->seed(CatalogSeeder::class);

    expect(Product::count())->toBe(1000);

    $product = Product::where('sku', 'P0001')->firstOrFail();

    expect($product->name)->toBe('מטהג אוויר חם Air Fryer XL')
        ->and($product->brand)->toBe('Philips')
        ->and($product->subcategory)->toBe('מכשירי חשמל קטנים למטבח')
        ->and($product->image_url)->toStartWith('https://');
});

it('stores an empty brand as null', function () {
    $this->seed(CatalogSeeder::class);

    expect(Product::where('sku', 'P0409')->value('brand'))->toBeNull();
});

it('can be re-run without duplicating or losing rows', function () {
    $this->seed(CatalogSeeder::class);
    Product::where('sku', 'P0001')->update(['name' => 'stale']);

    $this->seed(CatalogSeeder::class);

    expect(Product::count())->toBe(1000)
        ->and(Product::where('sku', 'P0001')->value('name'))->toBe('מטהג אוויר חם Air Fryer XL');
});

it('gives every product a demo price inside its subcategory range', function () {
    $this->seed(CatalogSeeder::class);

    Product::all()->each(function (Product $product) {
        [$min, $max] = config("catalog.demo_price_ranges.{$product->subcategory}");

        expect($product->price)->toBeGreaterThanOrEqual($min)->toBeLessThanOrEqual($max)
            ->and($product->price_is_demo)->toBeTrue();
    });
});

it('fails loudly when the CSV is missing columns', function () {
    $path = tempnam(sys_get_temp_dir(), 'catalog');
    file_put_contents($path, "id,name\nP1,Test\n");
    config(['catalog.csv_path' => $path]);

    expect(fn () => $this->seed(CatalogSeeder::class))
        ->toThrow(RuntimeException::class, 'missing columns');

    unlink($path);
});
