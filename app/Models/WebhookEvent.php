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


}