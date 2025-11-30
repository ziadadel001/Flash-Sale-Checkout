<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Product;
use App\Services\HoldService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ConcurrentHoldTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Queue::fake();
    }

    public function test_parallel_hold_attempts_do_not_oversell()
    {
        $product = Product::factory()->create([
            'stock_total' => 10,
            'stock_reserved' => 0,
            'stock_sold' => 0,
        ]);

        $holdService = app(HoldService::class);
        $successCount = 0;
        $failureCount = 0;

        for ($i = 0; $i < 15; $i++) {
            try {
                $holdService->createHold($product->id, 2, 2);
                $successCount++;
            } catch (\RuntimeException $e) {
                if ($e->getMessage() === 'not_enough_stock') {
                    $failureCount++;
                }
            }
        }

        $product->refresh();
        $this->assertLessThanOrEqual(5, $successCount);
        $this->assertGreaterThan(0, $failureCount);
        $this->assertLessThanOrEqual(10, $product->stock_reserved);
    }

    public function test_stock_reserved_reflects_multiple_holds()
    {
        $product = Product::factory()->create([
            'stock_total' => 100,
            'stock_reserved' => 0,
            'stock_sold' => 0,
        ]);

        $holdService = app(HoldService::class);

        for ($i = 0; $i < 5; $i++) {
            $holdService->createHold($product->id, 10, 2);
        }

        $product->refresh();
        $this->assertEquals(50, $product->stock_reserved);
        $this->assertEquals(50, $product->available_stock);
    }

    public function test_api_hold_creation_respects_stock_limit()
    {
        $product = Product::factory()->create([
            'stock_total' => 5,
            'stock_reserved' => 0,
            'stock_sold' => 0,
        ]);

        $successCount = 0;
        $failureCount = 0;

        for ($i = 0; $i < 10; $i++) {
            $response = $this->postJson('/api/holds', [
                'product_id' => $product->id,
                'qty' => 1,
            ]);

            if ($response->status() === 201) {
                $successCount++;
            } elseif ($response->status() === 409) {
                $failureCount++;
            }
        }

        $this->assertLessThanOrEqual(5, $successCount);
        $this->assertGreaterThan(0, $failureCount);
    }

    public function test_hold_creation_is_atomic()
    {
        $product = Product::factory()->create([
            'stock_total' => 10,
            'stock_reserved' => 0,
            'stock_sold' => 0,
        ]);

        $holdService = app(HoldService::class);
        $hold1 = $holdService->createHold($product->id, 10, 2);
        $this->assertNotNull($hold1->id);

        $product->refresh();
        $this->assertEquals(10, $product->stock_reserved);

        try {
            $holdService->createHold($product->id, 1, 2);
            $this->fail('Should have thrown exception');
        } catch (\RuntimeException $e) {
            $this->assertEquals('not_enough_stock', $e->getMessage());
        }

        $product->refresh();
        $this->assertEquals(10, $product->stock_reserved);
    }
}
