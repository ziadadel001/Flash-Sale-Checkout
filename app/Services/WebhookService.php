<?php

namespace App\Services;

use App\Models\WebhookEvent;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Jobs\ProcessWebhookJob;

/**
 * WebhookService - Payment Webhook Handler
 *
 * Processes payment status webhooks from external payment gateway with built-in
 * idempotency and out-of-order delivery support. Webhooks may arrive before orders
 * are created (due to network timing), so this service uses "waiting_for_order"
 * state to track pending webhooks until their orders are created.
 */
class WebhookService
{
    /**
     * Store incoming webhook payload with idempotency guarantee.
     *
     * Uses firstOrCreate() pattern to ensure duplicate webhooks (same idempotency_key)
     * are ignored. Handles out-of-order delivery: if order doesn't exist yet, stores
     * webhook with order_id=null and status="waiting_for_order" for later retry.
     *
     * @param string $idempotencyKey Unique webhook identifier from payment gateway
     * @param array $payload Webhook payload with 'status', 'type', 'order_id'
     *
     * @return WebhookEvent Created or existing webhook event record
     *
     * @throws \InvalidArgumentException If required fields missing in payload
     */
    public function handleIncoming(string $idempotencyKey, array $payload): WebhookEvent
    {
        if (!isset($payload['status'])) {
            throw new \InvalidArgumentException('Missing required field: status');
        }

        $orderId = $payload['order_id'] ?? null;
        $order = null;

        if ($orderId) {
            $order = Order::find($orderId);
        }

        $webhook = WebhookEvent::firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'order_id'   => $order ? $order->id : null,
                'event_type' => $payload['type'] ?? 'payment.notification',
                'payload'    => $payload,
                'processed'  => false,
            ]
        );

        if ($webhook->wasRecentlyCreated) {
            Log::info('webhook_received', [
                'webhook_id' => $webhook->id,
                'idempotency_key' => $idempotencyKey,
                'order_id' => $order ? $order->id : null,
            ]);

            ProcessWebhookJob::dispatch($webhook->id);
        } else {
            Log::info('webhook_duplicate_received', [
                'webhook_id' => $webhook->id,
                'idempotency_key' => $idempotencyKey,
            ]);
        }

        return $webhook;
    }

    /**
     * Process a webhook event: handle payment status and update order.
     *
     * Handles out-of-order delivery gracefully: if order doesn't exist yet,
     * returns 'waiting_for_order' for scheduled retry. Once order exists, applies
     * payment status (success/failure) to order atomically. Idempotent - safe to
     * call multiple times, checks if already processed.
     *
     * @param WebhookEvent $webhook Webhook event to process
     *
     * @return string Processing outcome:
     *         - 'applied': Webhook successfully applied to order
     *         - 'waiting_for_order': Order not found, will retry later
     *         - 'skipped': Webhook already processed (duplicate)
     *         - 'failed': Error during processing
     *
     * @throws \Exception On database errors (logged and returned as 'failed')
     */
    public function process(WebhookEvent $webhook): string
    {
        if ($webhook->processed) {
            Log::info('webhook_already_processed', [
                'webhook_id' => $webhook->id,
                'idempotency_key' => $webhook->idempotency_key,
            ]);
            return 'skipped';
        }

        return DB::transaction(function () use ($webhook) {
            $w = WebhookEvent::where('id', $webhook->id)->lockForUpdate()->first();

            if (!$w || $w->processed) {
                Log::info('webhook_already_processed_in_transaction', [
                    'webhook_id' => $webhook->id,
                ]);
                return 'skipped';
            }

            $payload = $w->payload ?? [];
            $orderId = $w->order_id ?? ($payload['order_id'] ?? null);

            if (!$w->order_id && $orderId) {
                $order = Order::find($orderId);
                if ($order) {
                    $w->order_id = $order->id;
                    $w->save();
                } else {
                    $w->outcome = 'waiting_for_order';
                    $w->save();
                    Log::info('webhook_waiting_for_order_creation', [
                        'webhook_id' => $w->id,
                        'expected_order_id' => $orderId,
                        'idempotency_key' => $w->idempotency_key,
                    ]);
                    return 'waiting_for_order';
                }
            }

            if (!$w->order_id) {
                $w->outcome = 'waiting_for_order';
                $w->save();
                Log::info('webhook_no_order_information', [
                    'webhook_id' => $w->id,
                    'idempotency_key' => $w->idempotency_key,
                ]);
                return 'waiting_for_order';
            }

            $order = Order::where('id', $w->order_id)->lockForUpdate()->first();
            if (!$order) {
                $w->outcome = 'waiting_for_order';
                $w->save();
                Log::info('webhook_order_still_not_found', [
                    'webhook_id' => $w->id,
                    'order_id' => $w->order_id,
                    'idempotency_key' => $w->idempotency_key,
                ]);
                return 'waiting_for_order';
            }

            // Process  on payment status            $status = $payload['status'] ?? null;

            try {
                if ($status === 'succeeded') {
                    app(OrderService::class)->finalizePaid($order, $payload['payment_id'] ?? null);
                    $w->outcome = 'applied';
                    $w->processed = true;
                    $w->processed_at = now();
                    $w->save();
                    Log::info('webhook_applied_payment_succeeded', [
                        'webhook_id' => $w->id,
                        'order_id' => $order->id,
                        'idempotency_key' => $w->idempotency_key,
                    ]);
                    return 'applied';
                }

                if (in_array($status, ['failed', 'cancelled', 'declined'])) {
                    app(OrderService::class)->markAsFailed($order, 'failed');
                    $w->outcome = 'applied';
                    $w->processed = true;
                    $w->processed_at = now();
                    $w->save();
                    Log::warning('webhook_applied_payment_failed', [
                        'webhook_id' => $w->id,
                        'order_id' => $order->id,
                        'payment_status' => $status,
                        'idempotency_key' => $w->idempotency_key,
                    ]);
                    return 'applied';
                }

                $w->outcome = 'failed';
                $w->processed = true;
                $w->processed_at = now();
                $w->save();
                Log::error('webhook_unknown_status', [
                    'webhook_id' => $w->id,
                    'order_id' => $order->id,
                    'status' => $status,
                    'idempotency_key' => $w->idempotency_key,
                ]);
                return 'failed';
            } catch (\Throwable $e) {
                $w->outcome = 'failed';
                $w->processed = false;
                $w->save();
                Log::error('webhook_processing_error', [
                    'webhook_id' => $w->id,
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                    'idempotency_key' => $w->idempotency_key,
                ]);
                throw $e; 
            }
        });
    }

    /**
     * Batch retry processing for webhooks waiting on order creation.
     *
     * Called by scheduled task to retry webhooks that previously returned
     * 'waiting_for_order' because order didn't exist yet. Processes up to 100
     * waiting webhooks per call. Returns count successfully processed.
     *
     * @return int Number of webhooks successfully processed in this batch
     *
     * @see \App\Console\Commands\ProcessWaitingWebhooks
     */
    public function processWaitingWebhooks(): int
    {
        $waitingWebhooks = WebhookEvent::where('outcome', 'waiting_for_order')
            ->where('processed', false)
            ->orderBy('created_at')
            ->limit(100)
            ->get();

        $processed = 0;
        foreach ($waitingWebhooks as $webhook) {
            try {
                $result = $this->process($webhook);
                if ($result === 'applied') {
                    $processed++;
                }
            } catch (\Throwable $e) {
                Log::error('webhook_retry_failed', [
                    'webhook_id' => $webhook->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('webhook_retry_batch_completed', [
            'total_processed' => $processed,
            'total_attempted' => $waitingWebhooks->count(),
        ]);

        return $processed;
    }
}