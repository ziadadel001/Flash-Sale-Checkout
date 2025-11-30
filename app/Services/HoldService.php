<?php

namespace App\Services;

use App\Models\Hold;
use App\Models\Product;
use App\Jobs\ExpireHoldJob;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\DatabaseManager;

/**
 * HoldService - Stock Hold Management
 *
 * Manages temporary stock reservations (holds) for flash-sale checkouts.
 * Provides atomic operations with database-level row locking to prevent
 * race conditions and ensure zero-overselling.
 *
 * @package App\Services
 */
class HoldService
{
    public function __construct(protected DatabaseManager $db) {}

    /**
     * Create an atomic stock hold (reservation).
     *
     * Uses database row-locking and atomic SQL WHERE clauses to guarantee
     * no race conditions. If stock is insufficient, fails with RuntimeException.
     * Automatically dispatches ExpireHoldJob to release stock after TTL.
     *
     * @param int $productId ID of product to hold stock for
     * @param int $qty Quantity to reserve
     * @param int $minutes Time-to-live in minutes (default: 2)
     *
     * @return Hold Created hold model instance
     *
     * @throws \RuntimeException If insufficient stock available
     * @throws \Exception On database error or lock timeout
     */
    public function createHold(int $productId, int $qty, int $minutes = 2): Hold
    {
        try {
        return DB::transaction(function () use ($productId, $qty, $minutes) {
            $product = Product::lockForUpdate()->findOrFail($productId);

                $availableStock = $product->stock_total - $product->stock_reserved - $product->stock_sold;

                if ($availableStock < $qty) {
                    Log::warning('createHold_insufficient_stock', [
                        'product_id' => $productId,
                        'requested_qty' => $qty,
                        'available_stock' => $availableStock,
                    ]);
                    throw new \RuntimeException('not_enough_stock');
                }

                $updated = DB::table('products')
                    ->where('id', $productId)
                    ->whereRaw('stock_total - stock_reserved - stock_sold >= ?', [$qty])
                    ->increment('stock_reserved', $qty);

                if ($updated === 0) {
                    Log::warning('createHold_atomic_check_failed', [
                        'product_id' => $productId,
                        'requested_qty' => $qty,
                    ]);
                    throw new \RuntimeException('not_enough_stock');
                }

                $hold = Hold::create([
                    'product_id' => $productId,
                    'qty' => $qty,
                    'status' => 'active',
                    'expires_at' => now()->addMinutes($minutes),
                    'unique_token' => Str::random(32),
                ]);

                Log::info('hold_created', [
                    'hold_id' => $hold->id,
                    'product_id' => $productId,
                    'qty' => $qty,
                    'expires_at' => $hold->expires_at,
                ]);

              ExpireHoldJob::dispatch($hold->id)->delay(now()->addMinutes($minutes));

                return $hold;
            }, attempts: 10);
        } catch (\Throwable $e) {
            Log::error('createHold_failed', [
                'product_id' => $productId,
                'qty' => $qty,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Expire a hold and release reserved stock.
     *
     * Marks hold as expired and atomically decrements stock_reserved.
     * Idempotent - can be called multiple times safely for the same hold.
     * Returns false if hold is already expired/consumed (no-op).
     *
     * @param int $holdId ID of hold to expire
     *
     * @return bool True if hold was expired, false if already in terminal state
     */
    public function expireHold(int $holdId): bool
    {
        return DB::transaction(function () use ($holdId) {
            $hold = Hold::where('id', $holdId)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if (!$hold) {
                Log::info('hold_already_expired_or_consumed', [
                    'hold_id' => $holdId,
                ]);
                return false;
            }

            try {
                $updated = DB::table('products')
                    ->where('id', $hold->product_id)
                    ->decrement('stock_reserved', $hold->qty);

                if ($updated === 0) {
                    Log::warning('hold_expire_decrement_returned_zero', [
                        'hold_id' => $holdId,
                        'product_id' => $hold->product_id,
                        'qty' => $hold->qty,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error('hold_expire_decrement_error', [
                    'hold_id' => $holdId,
                    'error' => $e->getMessage(),
                ]);
            }

            $hold->update(['status' => 'expired']);

            Log::info('hold_expired', [
                'hold_id' => $holdId,
                'product_id' => $hold->product_id,
                'qty' => $hold->qty,
            ]);

            return true;
        });
    }
    /**
     * Mark a hold as consumed.
     *
     * Marks an active hold as "consumed" to prevent reuse when creating an order.
     * Must be called atomically within order creation transaction.
     * Does NOT release stock - stock remains reserved until payment success/failure.
     *
     * @param int $holdId ID of hold to consume
     *
     * @return Hold Consumed hold model instance
     *
     * @throws \RuntimeException If hold is not active or has expired
     */
    public function consumeHold(int $holdId)
    {
        return DB::transaction(function () use ($holdId) {
            $hold = Hold::where('id', $holdId)->lockForUpdate()->firstOrFail();

            if ($hold->status !== 'active') {
                Log::warning('hold_already_consumed_or_expired', [
                    'hold_id' => $hold->id,
                    'status' => $hold->status,
                ]);
                throw new \RuntimeException('invalid_hold_state');
            }

            if ($hold->expires_at->isPast()) {
                Log::warning('hold_already_expired', [
                    'hold_id' => $hold->id,
                    'expires_at' => $hold->expires_at,
                ]);
                throw new \RuntimeException('hold_expired');
            }

            // Mark as consumed atomically - prevents reuse
            $hold->status = 'consumed';
            $hold->used_at = now();
            $hold->save();

            Log::info('hold_consumed', [
                'hold_id' => $hold->id,
                'product_id' => $hold->product_id,
                'qty' => $hold->qty,
            ]);

            return $hold->refresh();
        }, 5);
    }
}