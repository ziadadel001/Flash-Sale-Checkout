<?php

namespace App\Services;

use App\Models\WebhookEvent;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Jobs\ProcessWebhookJob;

class WebhookService
{
    /**
     * Store and enqueue processing for webhook payload (idempotent).
     *
     * $payload should include 'order_id' or data to match order.
     */
    public function handleIncoming(string $idempotencyKey, array $payload): WebhookEvent
    {
        $webhook = WebhookEvent::firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'order_id'   => $payload['order_id'] ?? null,
                'event_type' => $payload['type'] ?? null,
                'payload'    => $payload,
                'processed'  => false,
            ]
        );

        if ($webhook->wasRecentlyCreated) {
            Log::info('webhook_received', [
                'webhook_id' => $webhook->id,
                'idempotency_key' => $idempotencyKey,
            ]);
        } else {
            Log::warning('webhook_duplicate', [
                'webhook_id' => $webhook->id,
                'idempotency_key' => $idempotencyKey,
            ]);
        }

        // Dispatch job to process the webhook 
        if (! $webhook->processed) {
            ProcessWebhookJob::dispatch($webhook->id);
        }

        return $webhook;
    }

    /**
     * Synchronous small helper to process payload.
     * Returns outcome: 'applied' | 'waiting_for_order' | 'skipped' | 'failed'
     */
    public function process(WebhookEvent $webhook): string
    {
        if ($webhook->processed) {
            Log::info('webhook_skipped', [
                'webhook_id' => $webhook->id,
            ]);
            return 'skipped';
        }

        return DB::transaction(function () use ($webhook) {
            $w = WebhookEvent::where('id', $webhook->id)->lockForUpdate()->first();

            if (! $w || $w->processed) {
                Log::info('webhook_skipped', [
                    'webhook_id' => $webhook->id,
                ]);
                return 'skipped';
            }

            $payload = $w->payload ?? [];
            $orderId = $w->order_id ?? ($payload['order_id'] ?? null);

            if (! $orderId) {
                $w->outcome = 'waiting_for_order';
                $w->save();
                Log::warning('webhook_waiting_for_order', [
                    'webhook_id' => $w->id,
                ]);
                return 'waiting_for_order';
            }

            $order = Order::where('id', $orderId)->lockForUpdate()->first();
            if (! $order) {
                $w->outcome = 'waiting_for_order';
                $w->save();
                Log::warning('webhook_waiting_for_order', [
                    'webhook_id' => $w->id,
                    'order_id' => $orderId,
                ]);
                return 'waiting_for_order';
            }

            $status = $payload['status'] ?? null;

            if ($status === 'succeeded') {
                app(OrderService::class)->finalizePaid($order, $payload['payment_id'] ?? null);
                $w->outcome = 'applied';
                $w->processed = true;
                $w->processed_at = now();
                $w->save();
                Log::info('webhook_processed', [
                    'webhook_id' => $w->id,
                    'order_id' => $order->id,
                    'status' => 'succeeded',
                ]);
                return 'applied';
            }

            if (in_array($status, ['failed', 'cancelled', 'declined'])) {
                app(OrderService::class)->markAsFailed($order, 'failed');
                $w->outcome = 'applied';
                $w->processed = true;
                $w->processed_at = now();
                $w->save();
                Log::warning('webhook_processed_failed', [
                    'webhook_id' => $w->id,
                    'order_id' => $order->id,
                    'status' => $status,
                ]);
                return 'applied';
            }

            $w->outcome = 'failed';
            $w->processed = true;
            $w->processed_at = now();
            $w->save();
            Log::error('webhook_failed', [
                'webhook_id' => $w->id,
                'order_id' => $orderId,
                'status' => $status,
            ]);
            return 'failed';
        }, 5);
    }
}
