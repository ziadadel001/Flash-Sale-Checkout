<?php

namespace App\Services;

use App\Models\Hold;
use App\Models\Product;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Jobs\ExpireHoldJob;
use Illuminate\Support\Facades\Log;

class HoldService
{
    public function __construct(protected DatabaseManager $db)
    {
    }

    /**
     * Create a hold (atomic reserve on product).
     *
     * @throws \Exception when not enough stock
     */
    public function createHold(int $productId, int $qty, int $minutes = 2, ?string $uniqueToken = null): Hold
    {
        return DB::transaction(function () use ($productId, $qty, $minutes, $uniqueToken) {
            $product = Product::lockForUpdate()->findOrFail($productId);

            $ok = $product->reserveStockAtomic($qty);
            if (! $ok) {
                Log::warning('hold_failed_not_enough_stock', [
                    'product_id' => $product->id,
                    'requested_qty' => $qty,
                    'available_stock' => $product->stock_total - $product->stock_reserved,
                ]);
                throw new \RuntimeException('not_enough_stock');
            }

            $hold = Hold::create([
                'product_id' => $product->id,
                'qty' => $qty,
                'status' => 'active',
                'expires_at' => now()->addMinutes($minutes),
                'unique_token' => $uniqueToken ?? Str::uuid()->toString(),
            ]);

            Log::info('hold_created', [
                'hold_id' => $hold->id,
                'product_id' => $product->id,
                'qty' => $qty,
                'expires_at' => $hold->expires_at,
            ]);

            // Dispatch delayed job to expire the hold 
            ExpireHoldJob::dispatch($hold->id)->delay(now()->addMinutes($minutes + 0.5));

            return $hold->refresh();
        }, 5);
    }

    /**
     * Consume a hold and create (or return) an Order creation placeholder.
     * This method marks the hold consumed (prevents reuse) and returns the hold.
     *
     * Use OrderService to actually create the Order model if you want.
     */
    public function consumeHold(int $holdId)
    {
        return DB::transaction(function () use ($holdId) {
            $hold = Hold::where('id', $holdId)->lockForUpdate()->firstOrFail();

            if ($hold->status !== 'active' || $hold->expires_at->isPast()) {
                Log::warning('hold_invalid_or_expired', [
                    'hold_id' => $hold->id,
                    'status' => $hold->status,
                    'expires_at' => $hold->expires_at,
                ]);
                throw new \RuntimeException('invalid_or_expired_hold');
            }

            // mark as consumed
            $hold->status = 'consumed';
            $hold->used_at = now();
            $hold->save();

            Log::info('hold_consumed', [
                'hold_id' => $hold->id,
                'product_id' => $hold->product_id,
                'user_id' => $hold->user_id ?? null,
            ]);

            return $hold->refresh();
        }, 5);
    }

    /**
     * Safely expire a hold (idempotent).
     * Releases reserved stock atomically and marks hold expired.
     */
    public function expireHold(int $holdId): bool
    {
        return DB::transaction(function () use ($holdId) {
            $hold = Hold::where('id', $holdId)->lockForUpdate()->first();
            if (! $hold) {
                Log::warning('hold_expire_skipped', [
                    'hold_id' => $holdId,
                    'current_status' => 'not_found',
                ]);
                return false;
            }

            if ($hold->status !== 'active') {
                Log::warning('hold_expire_skipped', [
                    'hold_id' => $hold->id,
                    'current_status' => $hold->status,
                ]);
                return false;
            }

            // release stock atomically
            if ($hold->product) {
                $hold->product->releaseStockAtomic($hold->qty);
            }

            $hold->status = 'expired';
            $hold->save();

            Log::info('hold_expired', [
                'hold_id' => $hold->id,
                'product_id' => $hold->product_id,
                'qty' => $hold->qty,
            ]);

            return true;
        }, 5);
    }
}
