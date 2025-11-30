<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Hold;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * OrderService - Order & Payment Management
 *
 * Manages order creation from holds and payment state transitions.
 * Provides atomic operations with idempotency at service level.
 *
 * @package App\Services
 */
class OrderService
{
    /**
     * Create an order from an active, unexpired hold.
     *
     * Atomic operation that consumes hold and creates order in same transaction.
     * Handles idempotency by checking if order already exists for consumed hold.
     * Stock remains reserved until payment success/failure.
     *
     * @param int $holdId ID of hold to create order from
     * @param string|null $externalPaymentId Optional payment gateway ID
     *
     * @return Order Created or existing order model instance
     *
     * @throws \RuntimeException If hold is consumed, invalid state, or expired
     */
    public function createOrderFromHold(int $holdId, ?string $externalPaymentId = null): Order
    {
        return DB::transaction(function () use ($holdId, $externalPaymentId) {
            $hold = Hold::where('id', $holdId)->lockForUpdate()->firstOrFail();

            if ($hold->status === 'consumed') {
                $existingOrder = Order::where('hold_id', $hold->id)->first();
                if ($existingOrder) {
                    Log::info('order_already_exists_for_consumed_hold', [
                        'hold_id' => $hold->id,
                        'order_id' => $existingOrder->id,
                    ]);
                    return $existingOrder;
                }

                Log::warning('order_hold_already_consumed', [
                    'hold_id' => $hold->id,
                ]);
                throw new \RuntimeException('hold_already_consumed');
            }

            if ($hold->status !== 'active') {
                Log::warning('order_invalid_hold_state', [
                    'hold_id' => $hold->id,
                    'status' => $hold->status,
                ]);
                throw new \RuntimeException('invalid_hold_state');
            }

            if ($hold->expires_at->isPast()) {
                Log::warning('order_hold_expired', [
                    'hold_id' => $hold->id,
                    'expires_at' => $hold->expires_at,
                ]);
                throw new \RuntimeException('hold_expired');
            }

            $existingOrder = Order::where('hold_id', $hold->id)->first();
            if ($existingOrder) {
                Log::info('order_already_exists_for_hold', [
                    'hold_id' => $hold->id,
                    'order_id' => $existingOrder->id,
                ]);
                return $existingOrder;
            }

            $hold->status = 'consumed';
            $hold->used_at = now();
            $hold->save();

            Log::info('hold_consumed_for_order', [
                'hold_id' => $hold->id,
                'product_id' => $hold->product_id,
            ]);

            $amount = $hold->qty * $hold->product->price;

            $order = Order::create([
                'hold_id' => $hold->id,
                'external_payment_id' => $externalPaymentId,
                'status' => 'pending',
                'amount' => $amount,
            ]);

            Log::info('order_created', [
                'order_id' => $order->id,
                'hold_id' => $hold->id,
                'product_id' => $hold->product_id,
                'qty' => $hold->qty,
                'amount' => $amount,
            ]);

            return $order->refresh();
        }, 5);
    }

    /**
     * Finalize payment success: commit reserved stock to sold.
     *
     * Atomically transfers stock from reserved to sold state.
     * Idempotent - safe to call multiple times, double-checks in transaction.
     * Returns false if order is already paid (no-op).
     *
     * @param Order $order Order to mark as paid
     * @param string|null $externalPaymentId Optional payment gateway ID
     *
     * @return bool True if finalized, false if already paid
     *
     * @throws \RuntimeException If hold or product missing (data integrity error)
     */
    public function finalizePaid(Order $order, ?string $externalPaymentId = null): bool
    {
        if ($order->status === 'paid') {
            Log::info('order_already_paid', [
                'order_id' => $order->id,
            ]);
            return true;
        }

        return DB::transaction(function () use ($order, $externalPaymentId) {
            $order = Order::where('id', $order->id)->lockForUpdate()->firstOrFail();

            // Double-check idempotency
            if ($order->status === 'paid') {
                Log::info('order_already_paid_in_transaction', [
                    'order_id' => $order->id,
                ]);
                return true;
            }
            if (!$order->hold) {
                Log::error('order_missing_hold', [
                    'order_id' => $order->id,
                ]);
                throw new \RuntimeException('missing_hold');
            }

            $product = $order->hold->product;
            if (!$product) {
                Log::error('order_missing_product', [
                    'order_id' => $order->id,
                    'hold_id' => $order->hold->id,
                ]);
                throw new \RuntimeException('missing_product');
            }

            $product = \App\Models\Product::where('id', $product->id)->lockForUpdate()->firstOrFail();

            if (!$product->commitStockAtomic($order->hold->qty)) {
                Log::error('order_commit_stock_failed', [
                    'order_id' => $order->id,
                    'hold_id' => $order->hold->id,
                    'product_id' => $product->id,
                    'qty' => $order->hold->qty,
                ]);
                throw new \RuntimeException('commit_stock_failed');
            }

            $order->status = 'paid';
            if ($externalPaymentId) {
                $order->external_payment_id = $externalPaymentId;
            }
            $order->save();

            $order->hold->update([
                'status' => 'consumed',
                'used_at' => now(),
            ]);

            Log::info('order_finalized_paid', [
                'order_id' => $order->id,
                'hold_id' => $order->hold->id,
                'product_id' => $product->id,
                'qty' => $order->hold->qty,
                'amount' => $order->amount,
                'external_payment_id' => $externalPaymentId,
            ]);

            return true;
        }, 5);
    }

    /**
     * Mark order as payment failed: release reserved stock back to available.
     *
     * Atomically releases hold and marks order as failed. Idempotent - safe to call
     * multiple times, checks if already failed. Logs failure reason for audit trail.
     *
     * @param Order $order Order to mark as failed
     * @param string $newStatus Status to set (default: 'failed', can be 'cancelled')
     *
     * @return bool True if marked failed, false if already failed
     *
     * @throws \RuntimeException If hold or product missing (data integrity error)
     */
    public function markAsFailed(Order $order, string $newStatus = 'failed'): bool
    {
        if (in_array($order->status, ['failed', 'cancelled'])) {
            Log::info('order_already_failed_or_cancelled', [
                'order_id' => $order->id,
                'status' => $order->status,
            ]);
            return true;
        }

        return DB::transaction(function () use ($order, $newStatus) {
            $order = Order::where('id', $order->id)->lockForUpdate()->firstOrFail();

            if (in_array($order->status, ['failed', 'cancelled'])) {
                Log::info('order_already_terminal', [
                    'order_id' => $order->id,
                    'status' => $order->status,
                ]);
                return true;
            }

            if ($order->status === 'paid') {
                Log::warning('order_cannot_fail_after_paid', [
                    'order_id' => $order->id,
                ]);
                throw new \RuntimeException('cannot_fail_paid_order');
            }

            $product = $order->hold->product;
            if (!$product->releaseStockAtomic($order->hold->qty)) {
                Log::error('order_release_stock_failed', [
                    'order_id' => $order->id,
                    'hold_id' => $order->hold->id,
                    'product_id' => $product->id,
                    'qty' => $order->hold->qty,
                ]);
                throw new \RuntimeException('release_stock_failed');
            }

            $order->status = $newStatus;
            $order->save();

            $order->hold->update(['status' => 'expired']);

            Log::warning('order_marked_failed', [
                'order_id' => $order->id,
                'hold_id' => $order->hold->id,
                'product_id' => $product->id,
                'qty' => $order->hold->qty,
                'amount' => $order->amount,
                'new_status' => $newStatus,
            ]);

            return true;
        }, 5);
    }

    /**
     * Retrieve order by ID
     */
    public function getOrder(int $orderId): ?Order
    {
        return Order::with(['hold.product'])->find($orderId);
    }
}