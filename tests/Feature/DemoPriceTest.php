<?php

use App\Support\DemoPrice;

it('returns the same price for the same SKU', function () {
    expect(DemoPrice::for('P0101', 'מכונות קפה'))
        ->toBe(DemoPrice::for('P0101', 'מכונות קפה'));
});

it('looks like a retail price', function (string $sku) {
    $price = DemoPrice::for($sku, 'מכונות קפה');

    expect($price >= 1000 ? $price % 100 : $price % 10)
        ->toBe($price >= 1000 ? 90 : 9);
})->with(['P0101', 'P0102', 'P0103', 'P0104', 'P0105']);

it('falls back to the default range for an unknown subcategory', function () {
    [$min, $max] = config('catalog.demo_price_default');

    expect(DemoPrice::for('P9999', 'לא קיים'))
        ->toBeGreaterThanOrEqual($min)->toBeLessThanOrEqual($max);
});
