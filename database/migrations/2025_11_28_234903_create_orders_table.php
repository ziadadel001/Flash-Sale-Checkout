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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hold_id')
                  ->constrained('holds')
                  ->onUpdate('cascade')
                  ->onDelete('cascade');
            $table->string('external_payment_id')->nullable()->index();
            $table->enum('status', ['pending', 'paid', 'cancelled', 'failed'])->default('pending');
            $table->decimal('amount', 12, 2)->default(0);
            $table->json('payment_payload')->nullable();
            $table->timestamps();

            $table->unique('hold_id', 'orders_hold_unique');

            $table->index(['status', 'created_at'], 'orders_status_created_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
