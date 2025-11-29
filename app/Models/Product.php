<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Casts\Attribute;
     use Illuminate\Support\Facades\DB;

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

    public function activeHolds(): HasMany
    {
        return $this->holds()->active();
    }

    /*
      Accessors
     */
    protected function availableStock(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->stock_total - $this->stock_reserved - $this->stock_sold
        );
    }

    protected function isInStock(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->available_stock > 0
        );
    }

    /*
      Scopes
     */
    public function scopeInStock($query)
    {
        return $query->whereRaw('(stock_total - stock_reserved - stock_sold) > 0');
    }

    public function scopeLowStock($query, $threshold = 10)
    {
        return $query->whereRaw('(stock_total - stock_reserved - stock_sold) <= ?', [$threshold]);
    }

    /*
     Methods for handling stock
    */

public function reserveStockAtomic(int $quantity): bool
{
    $updated = DB::update(
        'UPDATE products SET stock_reserved = stock_reserved + ? 
         WHERE id = ? AND (stock_reserved + stock_sold + ?) <= stock_total',
        [$quantity, $this->id, $quantity]
    );

    if ($updated > 0) {
        // refresh model state
        $this->refresh();
        return true;
    }

    return false;
}
public function commitStockAtomic(int $quantity): bool
{
    $updated = DB::update(
        'UPDATE products 
         SET stock_reserved = stock_reserved - ?, stock_sold = stock_sold + ?
         WHERE id = ? AND stock_reserved >= ?',
        [$quantity, $quantity, $this->id, $quantity]
    );

    if ($updated > 0) {
        $this->refresh();
        return true;
    }

    return false;
}
public function releaseStockAtomic(int $quantity): bool
{
    $updated = DB::update(
        'UPDATE products 
         SET stock_reserved = GREATEST(0, stock_reserved - ?)
         WHERE id = ?',
        [$quantity, $this->id]
    );

    if ($updated > 0) {
        $this->refresh();
        return true;
    }

    return false;
}


 

}