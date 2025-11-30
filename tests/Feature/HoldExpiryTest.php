<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Hold;
use App\Models\Product;
use App\Services\HoldService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class HoldExpiryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Queue::fake();
    }

    public function test_expired_hold_releases_stock()
    {
        $product = Product::factory()->create([
            'stock_total' => 100,
            'stock_reserved' => 0,
            'stock_sold' => 0,
        ]);

        $holdService = app(HoldService::class);
        $hold = $holdService->createHold($product->id, 30, 2);

        $product->refresh();
        $this->assertEquals(30, $product->stock_reserved);

        $hold->update(['expires_at' => now()->subMinute(1)]);

        $result = $holdService->expireHold($hold->id);
        $this->assertTrue($result);

        $product->refresh();
        $this->assertEquals(0, $product->stock_reserved);

        $hold->refresh();
        $this->assertEquals('expired', $hold->status);
    }

    public function test_hold_expiry_is_idempotent()
    {
        $product = Product::factory()->create([
            'stock_total' => 100,
            'stock_reserved' => 0,
            'stock_sold' => 0,
        ]);

        $holdService = app(HoldService::class);
        $hold = $holdService->createHold($product->id, 30, 2);

        $hold->update(['expires_at' => now()->subMinute(1)]);

        $result1 = $holdService->expireHold($hold->id);
        $this->assertTrue($result1);

        $product->refresh();
        $this->assertEquals(0, $product->stock_reserved);

        $result2 = $holdService->expireHold($hold->id);
        $this->assertFalse($result2);

        $product->refresh();
        $this->assertEquals(0, $product->stock_reserved);
    }

    public function test_multiple_expired_holds_release_all_stock()
    {
        $product = Product::factory()->create([
            'stock_total' => 100,
            'stock_reserved' => 0,
            'stock_sold' => 0,
        ]);

        $holdService = app(HoldService::class);

        $holds = [];
        for ($i = 0; $i < 5; $i++) {
            $hold = $holdService->createHold($product->id, 10, 2);
            $holds[] = $hold;
        }

        $product->refresh();
        $this->assertEquals(50, $product->stock_reserved);

        foreach ($holds as $hold) {
            $hold->update(['expires_at' => now()->subMinute(1)]);
            $holdService->expireHold($hold->id);
        }

        $product->refresh();
        $this->assertEquals(0, $product->stock_reserved);
        $this->assertEquals(0, $product->stock_sold);
    }

    public function test_already_consumed_hold_cannot_be_expired()
    {
        $product = Product::factory()->create([
            'stock_total' => 100,
            'stock_reserved' => 0,
            'stock_sold' => 0,
        ]);

        $holdService = app(HoldService::class);
        $hold = $holdService->createHold($product->id, 30, 2);

        $hold->update(['status' => 'consumed']);

        $result = $holdService->expireHold($hold->id);
        $this->assertFalse($result);

        $product->refresh();
        $this->assertEquals(30, $product->stock_reserved);
    }

    public function test_command_expires_old_holds()
    {
        $product = Product::factory()->create([
            'stock_total' => 100,
            'stock_reserved' => 0,
            'stock_sold' => 0,
        ]);

        $holdService = app(HoldService::class);

        $hold1 = $holdService->createHold($product->id, 20, 2);
        $hold2 = $holdService->createHold($product->id, 20, 2);

        $hold1->update(['expires_at' => now()->subMinute(5)]);
        $hold2->update(['expires_at' => now()->subMinute(5)]);

        $product->refresh();
        $this->assertEquals(40, $product->stock_reserved);

        $this->artisan('holds:expire-old');

        $product->refresh();
        $this->assertEquals(0, $product->stock_reserved);

        $hold1->refresh();
        $hold2->refresh();
        $this->assertEquals('expired', $hold1->status);
        $this->assertEquals('expired', $hold2->status);
    }
}
