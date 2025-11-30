<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Hold;
use App\Models\Order;
use App\Models\Product;
use App\Services\HoldService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class OrderCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Queue::fake();
    }

    public function test_create_order_from_active_hold()
    {
        $product = Product::factory()->create([
            'stock_total' => 100,
            'stock_reserved' => 0,
            'stock_sold' => 0,
        ]);

        $holdService = app(HoldService::class);
        $hold = $holdService->createHold($product->id, 25, 2);

        $product->refresh();
        $this->assertEquals(25, $product->stock_reserved);

        $response = $this->postJson('/api/orders', [
            'hold_id' => $hold->id,
        ]);

        $this->assertEquals(201, $response->status());
        $this->assertEquals('pending', $response->json('data.status'));

        $order = Order::find($response->json('data.order_id'));
        $this->assertNotNull($order);
        $this->assertEquals('pending', $order->status);

        $hold->refresh();
        $this->assertEquals('consumed', $hold->status);
    }

    public function test_cannot_create_order_from_expired_hold()
    {
        $product = Product::factory()->create([
            'stock_total' => 100,
            'stock_reserved' => 0,
            'stock_sold' => 0,
        ]);

        $holdService = app(HoldService::class);
        $hold = $holdService->createHold($product->id, 25, 2);

        $hold->update(['expires_at' => now()->subMinute(1)]);

        $response = $this->postJson('/api/orders', [
            'hold_id' => $hold->id,
        ]);

        $this->assertEquals(410, $response->status());
    }


    public function test_order_amount_calculated_correctly()
    {
        $product = Product::factory()->create([
            'stock_total' => 100,
            'price' => 49.99,
            'stock_reserved' => 0,
            'stock_sold' => 0,
        ]);

        $holdService = app(HoldService::class);
        $hold = $holdService->createHold($product->id, 5, 2);

        $response = $this->postJson('/api/orders', [
            'hold_id' => $hold->id,
        ]);

        $expectedAmount = 5 * 49.99;
        $this->assertEqualsWithDelta($expectedAmount, $response->json('data.amount'), 0.01);
    }

    public function test_order_creation_is_atomic()
    {
        $product = Product::factory()->create([
            'stock_total' => 100,
            'stock_reserved' => 0,
            'stock_sold' => 0,
        ]);

        $holdService = app(HoldService::class);
        $hold = $holdService->createHold($product->id, 10, 2);

        $orderService = app(OrderService::class);
        $order1 = $orderService->createOrderFromHold($hold->id);
        $this->assertNotNull($order1->id);

        $order2 = $orderService->createOrderFromHold($hold->id);
        $this->assertEquals($order1->id, $order2->id);

        $orderCount = Order::where('hold_id', $hold->id)->count();
        $this->assertEquals(1, $orderCount);
    }
}