<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Hold;
use App\Models\Product;
use Illuminate\Support\Str;

class HoldFactory extends Factory
{
    protected $model = Hold::class;

    public function definition()
    {
        return [
            'product_id' => Product::factory(),
            'qty' => 1,
            'status' => 'active',
            'expires_at' => now()->addMinutes(10),
            'unique_token' => Str::uuid()->toString(),
        ];
    }

    public function configure()
    {
        return $this->afterCreating(function (Hold $hold) {
            if ($hold->status === 'active') {
                $product = $hold->product()->lockForUpdate()->first();
                if ($product) {
                    $product->stock_reserved += $hold->qty;
                    $product->save();
                }
            }
        });
    }
}
