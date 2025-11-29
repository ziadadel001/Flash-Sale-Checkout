<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

class FlashSaleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
          Product::create([
            'sku' => 'FLASH-SALE-2024',
            'name' => 'Flash Sale Product',
            'description' => 'Special limited stock product for flash sale',
            'price' => 99.99,
            'stock_total' => 100,
            'stock_reserved' => 0,
            'stock_sold' => 0,
            'metadata' => [
                'flash_sale' => true,
                'sale_duration' => 3600, // 1 hour in seconds
            ],
        ]);

        $this->command->info('Flash sale product seeded successfully!');
    }
}
