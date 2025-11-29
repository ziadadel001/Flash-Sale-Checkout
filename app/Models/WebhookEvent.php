<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookEvent extends Model
{

    protected $fillable = [
        'idempotency_key',
        'order_id',
        'event_type',
        'payload',
        'processed',
        'outcome',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'processed' => 'boolean',
        'processed_at' => 'datetime',
    ];

    /*
     Relations 
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

 /*
      Scopes
     */
    public function scopeProcessed($query)
    {
        return $query->where('processed', true);
    }

    public function scopeUnprocessed($query)
    {
        return $query->where('processed', false);
    }

    public function scopeByIdempotencyKey($query, $key)
    {
        return $query->where('idempotency_key', $key);
    }

    public function scopeWaitingForOrder($query)
    {
        return $query->where('outcome', 'waiting_for_order');
    }

    /*
    Methods to handle Webhook
     */
    public function markAsProcessed(string $outcome): bool
    {
        return $this->update([
            'processed' => true,
            'outcome' => $outcome,
            'processed_at' => now(),
        ]);
    }

    public function markAsWaitingForOrder(): bool
    {
        return $this->update([
            'outcome' => 'waiting_for_order',
        ]);
    }

    /*
    Validate states
     */
    public function isProcessed(): bool
    {
        return $this->processed;
    }

    public function isDuplicate(): bool
    {
        return self::where('idempotency_key', $this->idempotency_key)
            ->where('processed', true)
            ->exists();
    }

    /*
    Retrieve important payload
     */
    public function getPaymentStatus(): ?string
    {
        return $this->payload['status'] ?? null;
    }

    public function getPaymentId(): ?string
    {
        return $this->payload['payment_id'] ?? null;
    }

    public function isPaymentSuccessful(): bool
    {
        return $this->getPaymentStatus() === 'succeeded';
    }

    public function isPaymentFailed(): bool
    {
        return in_array($this->getPaymentStatus(), ['failed', 'cancelled', 'declined']);
    }
}