<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Hold;
use App\Models\Order;
use App\Models\Product;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;

class WebhookIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_duplicate_not_reprocessed()
    {
        $product = Product::factory()->create([
            'stock_total' => 100,
            'stock_reserved' => 10,
            'stock_sold' => 0,
        ]);

        $hold = Hold::factory()->create([
            'product_id' => $product->id,
            'qty' => 10,
            'status' => 'active',
        ]);

        $order = Order::factory()->create([
            'hold_id' => $hold->id,
            'status' => 'pending',
            'amount' => 1000,
        ]);

        $payload = [
            'order_id' => $order->id,
            'payment_id' => 'pay_123',
            'status' => 'succeeded',
            'type' => 'payment.notification',
        ];

        $key = 'idempotency_key_123';

        $response1 = $this->postJson('/api/webhooks', $payload, ['Idempotency-Key' => $key]);
        $this->assertEquals(202, $response1->status());

        $response2 = $this->postJson('/api/webhooks', $payload, ['Idempotency-Key' => $key]);
        $this->assertEquals(202, $response2->status());

        $webhooks = WebhookEvent::where('idempotency_key', $key)->get();
        $this->assertEquals(1, $webhooks->count());
    }

    public function test_webhook_successful_payment_marks_order_paid()
    {
        $product = Product::factory()->create([
            'stock_total' => 100,
            'stock_reserved' => 0,  
            'stock_sold' => 0,
        ]);

        $hold = Hold::factory()->create([
            'product_id' => $product->id,
            'qty' => 10,
            'status' => 'active',
        ]);

        $product->refresh();  

        $order = Order::factory()->create([
            'hold_id' => $hold->id,
            'status' => 'pending',
            'amount' => 1000,
        ]);

        $payload = [
            'order_id' => $order->id,
            'payment_id' => 'pay_456',
            'status' => 'succeeded',
            'type' => 'payment.completed',
        ];

        $this->postJson('/api/webhooks', $payload, ['Idempotency-Key' => 'key_pay_success']);

        $order->refresh();
        $this->assertEquals('paid', $order->status);

        $product->refresh();
        $this->assertEquals(10, $product->stock_sold);
        $this->assertEquals(0, $product->stock_reserved);
    }

    public function test_webhook_failed_payment_cancels_order()
    {
        $product = Product::factory()->create([
            'stock_total' => 100,
            'stock_reserved' => 0,  
            'stock_sold' => 0,
        ]);

        $hold = Hold::factory()->create([
            'product_id' => $product->id,
            'qty' => 10,
            'status' => 'active',
        ]);

        $product->refresh();  

        $order = Order::factory()->create([
            'hold_id' => $hold->id,
            'status' => 'pending',
            'amount' => 1000,
        ]);

        $payload = [
            'order_id' => $order->id,
            'payment_id' => 'pay_failed',
            'status' => 'failed',
            'type' => 'payment.failed',
        ];

        $this->postJson('/api/webhooks', $payload, ['Idempotency-Key' => 'key_pay_fail']);

        $order->refresh();
        $this->assertEquals('failed', $order->status);

        $product->refresh();
        $this->assertEquals(0, $product->stock_sold);
        $this->assertEquals(0, $product->stock_reserved); 
    }
}