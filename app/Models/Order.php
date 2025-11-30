<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Order extends Model
{
        use HasFactory;

    protected $fillable = [
        'hold_id',
        'external_payment_id',
        'status',
        'amount',
        'payment_payload',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payment_payload' => 'array',
    ];

    /*
    Default values
    */
    protected $attributes = [
        'status' => 'pending',
    ];

    /*
      Relations  
     */
    public function hold(): BelongsTo
    {
        return $this->belongsTo(Hold::class);
    }


    public function webhookEvents(): HasMany
    {
        return $this->hasMany(WebhookEvent::class);
    }


    /**
     *  Scopes
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', 'cancelled');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopeRecent($query, $hours = 24)
    {
        return $query->where('created_at', '>=', now()->subHours($hours));
    }

    /*
    Methods to manage Order status
     */
  public function markAsPaid($externalPaymentId = null): bool
{
    if ($this->status === 'paid') return true;

    DB::transaction(function () use ($externalPaymentId) {
        $this->update([
            'status' => 'paid',
            'external_payment_id' => $externalPaymentId ?: $this->external_payment_id,
        ]);

        // use atomic commit on product
        $this->hold->product->commitStockAtomic($this->hold->qty);

        $this->hold->update([
            'status' => 'consumed',
            'used_at' => now(),
        ]);
    });

    return $this->fresh()->status === 'paid';
}


public function markAsFailed(): bool
{
    if (in_array($this->status, ['cancelled', 'failed'])) return true;

    DB::transaction(function () {
        $this->update(['status' => 'failed']);
        if ($this->hold && $this->hold->product) {
            // release atomically
            $this->hold->product->releaseStockAtomic($this->hold->qty);
        }
        // mark hold as expired (not consumed)
        $this->hold->update(['status' => 'expired']);
    });

    return $this->fresh()->status === 'failed';
}


    public function markAsCancelled(): bool
    {
        if (in_array($this->status, ['cancelled', 'failed'])) {
            return true; 
        }

        DB::transaction(function () {
            $this->update([
                'status' => 'cancelled',
            ]);

            if ($this->hold && $this->hold->product) {
                $this->hold->product->releaseStockAtomic($this->hold->qty);
            }
        });

        return $this->fresh()->status === 'cancelled';
    }

    /*
    Validate states
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isFinal(): bool
    {
        return in_array($this->status, ['paid', 'cancelled', 'failed']);
    }

}