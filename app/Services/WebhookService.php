<?php

namespace App\Services;

use App\Models\WebhookEvent;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
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
        // Use firstOrCreate to ensure idempotency at DB level (unique constraint on idempotency_key)
        $webhook = null;
        try {
            $webhook = WebhookEvent::create([
                'idempotency_key' => $idempotencyKey,
                'order_id' => $payload['order_id'] ?? null,
                'event_type' => $payload['type'] ?? null,
                'payload' => $payload,
                'processed' => false,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // unique constraint violation => event already exists
            $webhook = WebhookEvent::where('idempotency_key', $idempotencyKey)->first();
            if (! $webhook) {
                throw $e;
            }
            // If it's already processed, return it
            if ($webhook->processed) {
                return $webhook;
            }
        }

        // Dispatch job to process the webhook (idempotent processing)
        ProcessWebhookJob::dispatch($webhook->id);

        return $webhook;
    }

    /**
     * Synchronous small helper to process payload .
     * Returns outcome: 'applied' | 'waiting_for_order' | 'skipped' | 'failed'
     */
    public function process(WebhookEvent $webhook): string
    {
        if ($webhook->processed) {
            return 'skipped';
        }

        return DB::transaction(function () use ($webhook) {
            $w = WebhookEvent::where('id', $webhook->id)->lockForUpdate()->first();

            if (! $w || $w->processed) return 'skipped';

            $payload = $w->payload ?? [];

            $orderId = $w->order_id ?? ($payload['order_id'] ?? null);

            if (! $orderId) {
                $w->outcome = 'waiting_for_order';
                $w->save();
                return 'waiting_for_order';
            }

            $order = Order::where('id', $orderId)->lockForUpdate()->first();
            if (! $order) {
                $w->outcome = 'waiting_for_order';
                $w->save();
                return 'waiting_for_order';
            }

            // Decide by payload: example payload.status = succeeded | failed
            $status = $payload['status'] ?? null;

            if ($status === 'succeeded') {
                app(OrderService::class)->finalizePaid($order, $payload['payment_id'] ?? null);
                $w->outcome = 'applied';
                $w->processed = true;
                $w->processed_at = now();
                $w->save();
                return 'applied';
            }

            if (in_array($status, ['failed', 'cancelled', 'declined'])) {
                app(OrderService::class)->markAsFailed($order, 'failed');
                $w->outcome = 'applied';
                $w->processed = true;
                $w->processed_at = now();
                $w->save();
                return 'applied';
            }

            $w->outcome = 'failed';
            $w->processed = true;
            $w->processed_at = now();
            $w->save();
            return 'failed';
        }, 5);
    }
}
