<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Order extends Model
{
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

    public function product()
    {
        return $this->hasOneThrough(Product::class, Hold::class, 'id', 'id', 'hold_id', 'product_id');
    }

    public function webhookEvents(): HasMany
    {
        return $this->hasMany(WebhookEvent::class);
    }

}