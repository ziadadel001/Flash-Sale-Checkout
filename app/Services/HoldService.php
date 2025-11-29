<?php

namespace App\Services;

use App\Models\Hold;
use App\Models\Product;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Jobs\ExpireHoldJob;

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
                throw new \RuntimeException('not_enough_stock');
            }

            $hold = Hold::create([
                'product_id' => $product->id,
                'qty' => $qty,
                'status' => 'active',
                'expires_at' => now()->addMinutes($minutes),
                'unique_token' => $uniqueToken ?? Str::uuid()->toString(),
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
                throw new \RuntimeException('invalid_or_expired_hold');
            }

            // mark as consumed
            $hold->status = 'consumed';
            $hold->used_at = now();
            $hold->save();

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
            if (! $hold) return false;

            if ($hold->status !== 'active') {
                return false;
            }

            // release stock atomically
            if ($hold->product) {
                $hold->product->releaseStockAtomic($hold->qty);
            }

            $hold->status = 'expired';
            $hold->save();

            return true;
        }, 5);
    }
}
