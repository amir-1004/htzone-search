<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'sku', 'name', 'brand', 'category', 'subcategory',
    'description', 'attributes', 'image_url', 'price', 'price_is_demo',
])]
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
}
