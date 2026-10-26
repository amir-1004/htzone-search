<?php

namespace App\Support;

/**
 * Deterministic demo prices for a catalog that ships without prices.
 *
 * The same SKU always gets the same price, so results are stable across
 * re-seeds and environments, and the price stays plausible for its
 * subcategory (a mug never costs like an espresso machine).
 */
class DemoPrice
{
    /** @var array<string, list<int>> */
    private static array $retailPrices = [];

    public static function for(string $sku, string $subcategory): int
    {
        [$min, $max] = config("catalog.demo_price_ranges.{$subcategory}")
            ?? config('catalog.demo_price_default');

        $prices = self::retailPricesBetween($min, $max);

        return $prices === [] ? $min : $prices[crc32($sku) % count($prices)];
    }

    /**
     * Retail-looking prices in the range: 129, 999, 1,290, 3,490.
     *
     * @return list<int>
     */
    private static function retailPricesBetween(int $min, int $max): array
    {
        return self::$retailPrices["{$min}-{$max}"] ??= array_values(array_filter(
            range($min, $max),
            fn (int $price) => $price < 1000 ? $price % 10 === 9 : $price % 100 === 90,
        ));
    }
}
