<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * The CSV column "attributes" is stored as `specs`: a column named
 * "attributes" would collide with Eloquent's internal $attributes array.
 */
#[Fillable([
    'sku', 'name', 'brand', 'category', 'subcategory',
    'description', 'specs', 'image_url', 'price', 'price_is_demo',
])]
#[Hidden(['embedding', 'embedding_hash'])]
class Product extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'price_is_demo' => 'boolean',
        ];
    }

    /**
     * The text that represents this product's meaning for the embedding model.
     */
    public function embeddingText(): string
    {
        return implode('. ', array_filter([
            $this->name,
            $this->brand,
            "{$this->category} > {$this->subcategory}",
            $this->description,
            $this->specs,
        ]));
    }
}
