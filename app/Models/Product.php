<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Casts\Attribute;

class Product extends Model
{

    protected $fillable = [
        'sku',
        'name',
        'description',
        'price',
        'stock_total',
        'stock_reserved',
        'stock_sold',
        'metadata',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'metadata' => 'array',
    ];

    /*
     Relations
     */
    public function holds(): HasMany
    {
        return $this->hasMany(Hold::class);
    }

}