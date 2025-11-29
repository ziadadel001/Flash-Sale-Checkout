<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id(); 
            $table->string('sku')->nullable()->unique(); 
            $table->string('name')->index();
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->unsignedInteger('stock_total')->default(0);
            $table->unsignedInteger('stock_reserved')->default(0);
            $table->unsignedInteger('stock_sold')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['stock_total', 'stock_reserved', 'stock_sold'], 'products_stock_idx');
        });

    }

    public function down()
    {
        Schema::dropIfExists('products');
    }
};
