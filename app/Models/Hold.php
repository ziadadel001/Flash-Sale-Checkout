<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Hold extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'qty',
        'status',
        'expires_at',
        'used_at',
        'unique_token',
        'metadata',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'metadata' => 'array',
    ];

    /*
     Relations
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function order(): HasOne
    {
        return $this->hasOne(Order::class);
    }


    /*
       Accessors
     */
    protected function isActive(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->status === 'active' && $this->expires_at->isFuture()
        );
    }

    protected function isExpired(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->expires_at->isPast()
        );
    }

    protected function isConsumed(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->status === 'consumed'
        );
    }

    /*
      Scopes
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active')
                    ->where('expires_at', '>', now());
    }

    public function scopeExpired($query)
    {
        return $query->where(function ($q) {
            $q->where('status', 'expired')
              ->orWhere('expires_at', '<=', now());
        });
    }

    public function scopeExpiringSoon($query, $minutes = 5)
    {
        return $query->where('status', 'active')
                    ->where('expires_at', '<=', now()->addMinutes($minutes))
                    ->where('expires_at', '>', now());
    }

    public function scopeNotConsumed($query)
    {
        return $query->where('status', '!=', 'consumed');
    }

    /*
    Methods to manage Hold status
    */
    public function markAsConsumed(): bool
    {
        return $this->update([
            'status' => 'consumed',
            'used_at' => now(),
        ]);
    }

    public function markAsExpired(): bool
    {
        if ($this->product && $this->status === 'active') {
            $this->product->releaseStockAtomic($this->qty);
        }

        return $this->update([
            'status' => 'expired',
        ]);
    }

    public function renew($minutes = 2): bool
    {
        return $this->update([
            'expires_at' => now()->addMinutes($minutes),
        ]);
    }

    /*
     Check Hold expiration
    */
    public function isValid(): bool
    {
        return $this->status === 'active' 
            && $this->expires_at->isFuture()
            && !$this->order;
    }
}