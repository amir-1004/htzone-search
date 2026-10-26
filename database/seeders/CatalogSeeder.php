<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Support\DemoPrice;
use Illuminate\Database\Seeder;
use RuntimeException;

class CatalogSeeder extends Seeder
{
    private const COLUMNS = [
        'id', 'name', 'brand', 'category', 'subcategory',
        'description', 'attributes', 'image_url',
    ];

    /**
     * Import the catalog CSV. Upserts by SKU, so re-running never duplicates rows.
     */
    public function run(): void
    {
        $path = config('catalog.csv_path');
        $handle = @fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("Catalog CSV not found: {$path}");
        }

        $header = fgetcsv($handle, escape: '');
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);

        if ($missing = array_diff(self::COLUMNS, $header)) {
            throw new RuntimeException('Catalog CSV is missing columns: '.implode(', ', $missing));
        }

        $now = now();
        $rows = [];

        while (($values = fgetcsv($handle, escape: '')) !== false) {
            if ($values === [null]) {
                continue;
            }

            $row = array_map('trim', array_combine($header, $values));

            $rows[] = [
                'sku' => $row['id'],
                'name' => $row['name'],
                'brand' => $row['brand'] !== '' ? $row['brand'] : null,
                'category' => $row['category'],
                'subcategory' => $row['subcategory'],
                'description' => $row['description'],
                'attributes' => $row['attributes'],
                'image_url' => $row['image_url'],
                'price' => DemoPrice::for($row['id'], $row['subcategory']),
                'price_is_demo' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        fclose($handle);

        foreach (array_chunk($rows, 250) as $chunk) {
            Product::upsert(
                $chunk,
                uniqueBy: ['sku'],
                update: array_diff(array_keys($chunk[0]), ['sku', 'created_at']),
            );
        }

        $this->command?->info('Imported '.count($rows).' products.');
    }
}
