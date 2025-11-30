<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Hold;
use App\Models\Order;
use App\Models\Product;
use App\Models\WebhookEvent;
use App\Services\WebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class WebhookOutOfOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_before_order_creation_waits_for_order()
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

        $nonexistentOrderId = 99999;

        $payload = [
            'order_id' => $nonexistentOrderId,
            'payment_id' => 'pay_outoforder',
            'status' => 'succeeded',
            'type' => 'payment.completed',
        ];

        $response = $this->postJson('/api/webhooks', $payload, ['Idempotency-Key' => 'key_outoforder']);
        $this->assertEquals(202, $response->status());

        $webhook = WebhookEvent::where('idempotency_key', 'key_outoforder')->first();
        $this->assertNotNull($webhook);
        $this->assertFalse($webhook->processed);
        $this->assertEquals('waiting_for_order', $webhook->outcome);
    }

    public function test_webhook_retry_when_order_created_later()
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

        $payload = [
            'order_id' => 999,
            'payment_id' => 'pay_retry',
            'status' => 'succeeded',
            'type' => 'payment.completed',
        ];

        $this->postJson('/api/webhooks', $payload, ['Idempotency-Key' => 'key_retry']);

        $webhook = WebhookEvent::where('idempotency_key', 'key_retry')->first();
        $this->assertFalse($webhook->processed);

        $order = Order::factory()->create([
            'id' => 999,
            'hold_id' => $hold->id,
            'status' => 'pending',
            'amount' => 1000,
        ]);

        $webhookService = app(WebhookService::class);
        $result = $webhookService->process($webhook);

        $this->assertEquals('applied', $result);

        $order->refresh();
        $this->assertEquals('paid', $order->status);

        $product->refresh();
        $this->assertEquals(10, $product->stock_sold);
    }

    public function test_command_retries_waiting_webhooks()
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

        $payload = [
            'order_id' => 888,
            'payment_id' => 'pay_cmd_retry',
            'status' => 'succeeded',
            'type' => 'payment.completed',
        ];

        $this->postJson('/api/webhooks', $payload, ['Idempotency-Key' => 'key_cmd_retry']);

        $webhook = WebhookEvent::where('idempotency_key', 'key_cmd_retry')->first();
        $this->assertEquals('waiting_for_order', $webhook->outcome);

        Order::factory()->create([
            'id' => 888,
            'hold_id' => $hold->id,
            'status' => 'pending',
            'amount' => 1000,
        ]);

        $this->artisan('webhooks:process-waiting');

        $webhook->refresh();
        $this->assertTrue($webhook->processed);
        $this->assertEquals('applied', $webhook->outcome);
    }

    public function test_multiple_out_of_order_webhooks_eventually_apply()
    {
        $product = Product::factory()->create([
            'stock_total' => 100,
            'stock_reserved' => 0,
            'stock_sold' => 0,
        ]);

        $holds = [];
        for ($i = 0; $i < 3; $i++) {
            $hold = Hold::factory()->create([
                'product_id' => $product->id,
                'qty' => 10,
                'status' => 'active',
            ]);
            $holds[] = $hold;
        }

        $product->refresh();
        $this->assertEquals(30, $product->stock_reserved);

        $orderIds = [5001, 5002, 5003];
        foreach ($orderIds as $id) {
            $payload = [
                'order_id' => $id,
                'payment_id' => "pay_multi_$id",
                'status' => 'succeeded',
                'type' => 'payment.completed',
            ];
            $this->postJson('/api/webhooks', $payload, ['Idempotency-Key' => "key_multi_$id"]);
        }

        $waitingCount = WebhookEvent::where('outcome', 'waiting_for_order')->count();
        $this->assertEquals(3, $waitingCount);

        foreach (array_values($holds) as $index => $hold) {
            Order::factory()->create([
                'id' => $orderIds[$index],
                'hold_id' => $hold->id,
                'status' => 'pending',
                'amount' => 1000,
            ]);
        }

        $this->artisan('webhooks:process-waiting');

        $appliedCount = WebhookEvent::where('outcome', 'applied')->count();
        $this->assertEquals(3, $appliedCount);

        $product->refresh();
        $this->assertEquals(30, $product->stock_sold);
    }
}
