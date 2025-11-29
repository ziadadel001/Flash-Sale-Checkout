<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Traits\ApiResponse;
use Symfony\Component\HttpFoundation\Response;

class ProductController extends Controller
{
    use ApiResponse;

        protected int $perPage = 10;

    /**
     * List all products in stock
     */
    public function index()
    {
        $paginated = Product::inStock()->paginate($this->perPage);

        return $this->success([
            'products' => $paginated->items(),
            'pagination' => [
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
            ]
        ]);
    }

    /**
     * Show a single product
     */
    public function show(Product $product)
    {
        return $this->success([
            'product' => $this->transform($product),
        ]);
    }

    /**
     * Transform product for API response
     */
    protected function transform(Product $product): array
    {
        return [
            'id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'description' => $product->description,
            'price' => (float)$product->price,
            'available_stock' => $product->available_stock,
            'is_in_stock' => $product->is_in_stock,
            'metadata' => $product->metadata,
            'created_at' => $product->created_at?->toDateTimeString(),
            'updated_at' => $product->updated_at?->toDateTimeString(),
        ];
    }
}
