<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')
                  ->constrained('products')
                  ->onUpdate('cascade')
                  ->onDelete('cascade');
            $table->unsignedInteger('qty')->default(1);
            $table->enum('status', ['active', 'expired', 'consumed'])->default('active');
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('used_at')->nullable();
            $table->string('unique_token', 191)->nullable()->unique();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'status'], 'holds_product_status_idx');
            $table->index(['status', 'expires_at'], 'holds_status_expires_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('holds');
    }
};
