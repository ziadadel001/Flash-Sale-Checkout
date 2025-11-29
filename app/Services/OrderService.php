<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Hold;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderService
{
    public function createOrderFromHold(int $holdId, ?string $externalPaymentId = null): Order
    {
        return DB::transaction(function () use ($holdId, $externalPaymentId) {
            $hold = Hold::where('id', $holdId)->lockForUpdate()->firstOrFail();

            if ($hold->status !== 'active' && $hold->status !== 'consumed') {
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

            // If not yet consumed, mark consumed
            if ($hold->status !== 'consumed') {
                $hold->status = 'consumed';
                $hold->used_at = now();
                $hold->save();
                Log::info('hold_consumed', [
                    'hold_id' => $hold->id,
                    'product_id' => $hold->product_id,
                ]);
            }

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
                'amount' => $amount,
            ]);

            return $order->refresh();
        }, 5);
    }

    /**
     * Finalize payment success: commit reserved -> sold and mark order paid.
     * Idempotent: safe to call multiple times.
     */
    public function finalizePaid(Order $order, ?string $externalPaymentId = null): bool
    {
        if ($order->status === 'paid') return true;

        return DB::transaction(function () use ($order, $externalPaymentId) {
            $order = Order::where('id', $order->id)->lockForUpdate()->first();

            if ($order->status === 'paid') return true;

            $product = $order->hold->product;
            if (! $product->commitStockAtomic($order->hold->qty)) {
                Log::error('order_commit_stock_failed', [
                    'order_id' => $order->id,
                    'hold_id' => $order->hold->id,
                    'product_id' => $product->id,
                    'qty' => $order->hold->qty,
                ]);
                throw new \RuntimeException('commit_stock_failed');
            }

            $order->status = 'paid';
            if ($externalPaymentId) $order->external_payment_id = $externalPaymentId;
            $order->save();

            $order->hold->update(['status' => 'consumed', 'used_at' => now()]);

            Log::info('order_paid', [
                'order_id' => $order->id,
                'hold_id' => $order->hold->id,
                'amount' => $order->amount,
                'product_id' => $product->id,
            ]);

            return true;
        }, 5);
    }

    /**
     * Handle payment failure/cancel -> release stock, mark order failed/cancelled
     */
    public function markAsFailed(Order $order, string $newStatus = 'failed'): bool
    {
        if (in_array($order->status, ['failed', 'cancelled'])) return true;

        return DB::transaction(function () use ($order, $newStatus) {
            $order = Order::where('id', $order->id)->lockForUpdate()->first();

            if (in_array($order->status, ['failed', 'cancelled'])) return true;

            $product = $order->hold->product;
            $product->releaseStockAtomic($order->hold->qty);

            $order->status = $newStatus;
            $order->save();

            $order->hold->update(['status' => 'expired']);

            Log::warning('order_failed', [
                'order_id' => $order->id,
                'hold_id' => $order->hold->id,
                'product_id' => $product->id,
                'amount' => $order->amount,
                'new_status' => $newStatus,
            ]);

            return true;
        }, 5);
    }
}
