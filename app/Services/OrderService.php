<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Hold;
use Illuminate\Support\Facades\DB;
use App\Models\Product;

class OrderService
{
    public function createOrderFromHold(int $holdId, ?string $externalPaymentId = null): Order
    {
        return DB::transaction(function () use ($holdId, $externalPaymentId) {
            $hold = Hold::where('id', $holdId)->lockForUpdate()->firstOrFail();

            if ($hold->status !== 'active' && $hold->status !== 'consumed') {
                throw new \RuntimeException('invalid_hold_state');
            }

            if ($hold->expires_at->isPast()) {
                throw new \RuntimeException('hold_expired');
            }

            // If not yet consumed, mark consumed
            if ($hold->status !== 'consumed') {
                $hold->status = 'consumed';
                $hold->used_at = now();
                $hold->save();
            }

            $amount = $hold->qty * $hold->product->price;

            $order = Order::create([
                'hold_id' => $hold->id,
                'external_payment_id' => $externalPaymentId,
                'status' => 'pending',
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
            // reload with lock
            $order = Order::where('id', $order->id)->lockForUpdate()->first();

            if ($order->status === 'paid') return true;

            // commit stock atomically
            $product = $order->hold->product;
            if (! $product->commitStockAtomic($order->hold->qty)) {
                throw new \RuntimeException('commit_stock_failed');
            }

            $order->status = 'paid';
            if ($externalPaymentId) $order->external_payment_id = $externalPaymentId;
            $order->save();

            // mark hold consumed (safety)
            $order->hold->update(['status' => 'consumed', 'used_at' => now()]);

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

            // mark hold expired to allow reuse
            $order->hold->update(['status' => 'expired']);

            return true;
        }, 5);
    }
}
