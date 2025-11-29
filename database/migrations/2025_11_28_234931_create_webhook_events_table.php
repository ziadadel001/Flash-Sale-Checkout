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
        Schema::create('webhook_events', function (Blueprint $table) {
           $table->id();
            $table->string('idempotency_key', 191)->unique();
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->string('event_type')->nullable()->index();
            $table->json('payload')->nullable();
            $table->boolean('processed')->default(false)->index();
            $table->enum('outcome', ['applied', 'skipped', 'failed', 'waiting_for_order'])->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->foreign('order_id')
                  ->references('id')->on('orders')
                  ->onUpdate('cascade')
                  ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
