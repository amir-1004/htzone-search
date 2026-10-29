<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'sku' => $this->sku,
            'name' => $this->name,
            'brand' => $this->brand,
            'category' => $this->category,
            'subcategory' => $this->subcategory,
            'image_url' => $this->image_url,
            // Only a price that came with the catalog: missing fields are not shown.
            'price' => $this->price_is_demo ? null : $this->price,
        ];
    }
}
