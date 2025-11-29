# Flash Sale E-commerce System

## Project Overview

This project is an E-commerce system with a focus on **flash sale products**, atomic stock reservation, and webhook-based payment processing. It ensures:

* Real-time stock management with **atomic holds**.
* Automatic expiration of holds.
* Order creation from reserved holds.
* Webhook handling for payment notifications.
* Logging for auditing and debugging.
* Unified API responses using a dedicated trait.
* Validation separated from controllers via Form Requests.
* Business logic separated into a service layer for cleaner controllers.

---

## Features

* **Products Management**

  * SKU, name, description, price, and stock tracking.
  * JSON `metadata` for extra product info.
  * Indexing on stock and name for fast queries.

* **Holds (Stock Reservations)**

  * Reserve stock atomically for a limited time.
  * Status: `active`, `expired`, `consumed`.
  * Unique token for each hold.
  * Automatic expiration via scheduled jobs.

* **Orders**

  * Created from valid holds.
  * Status: `pending`, `paid`, `cancelled`, `failed`.
  * Supports external payment IDs and JSON payment payloads.
  * Stock commitment is atomic and idempotent.

* **Webhook Handling**

  * Receives external payment events.
  * Idempotent processing.
  * Processes `succeeded`, `failed`, `cancelled`, or `declined` statuses.

* **Jobs**

  * `ExpireHoldJob` — expires a single hold.
  * `ForceExpireHoldsJob` — batch expire expired holds.
  * `ProcessWebhookJob` — processes webhook events asynchronously.

---

## Database Migrations

### `products` table

```php
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
```

### `holds` table

```php
Schema::create('holds', function (Blueprint $table) {
    $table->id();
    $table->foreignId('product_id')->constrained('products')->cascadeOnUpdate()->cascadeOnDelete();
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
```

### `orders` table

```php
Schema::create('orders', function (Blueprint $table) {
    $table->id();
    $table->foreignId('hold_id')->constrained('holds')->cascadeOnUpdate()->cascadeOnDelete();
    $table->string('external_payment_id')->nullable()->index();
    $table->enum('status', ['pending', 'paid', 'cancelled', 'failed'])->default('pending');
    $table->decimal('amount', 12, 2)->default(0);
    $table->json('payment_payload')->nullable();
    $table->timestamps();
    $table->unique('hold_id', 'orders_hold_unique');
    $table->index(['status', 'created_at'], 'orders_status_created_idx');
});
```

### `webhook_events` table

```php
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
    $table->foreign('order_id')->references('id')->on('orders')->cascadeOnUpdate()->nullOnDelete();
});
```

---

## Seeders

* `FlashSaleSeeder` creates a special limited stock flash sale product:

```php
Product::create([
    'sku' => 'FLASH-SALE-2024',
    'name' => 'Flash Sale Product',
    'description' => 'Special limited stock product for flash sale',
    'price' => 99.99,
    'stock_total' => 100,
    'stock_reserved' => 0,
    'stock_sold' => 0,
    'metadata' => [
        'flash_sale' => true,
        'sale_duration' => 3600,
    ],
]);
```

* `DatabaseSeeder` calls `TestUsersSeeder` and `FlashSaleSeeder`.

---

## Environment Setup

1. Clone the repository:

```bash
git clone https://github.com/ziadadel001/Flash-Sale-Checkout.git
```

2. Install dependencies:

```bash
composer install
```

3. Copy `.env.example` to `.env` and configure:

```bash
cp .env.example .env
php artisan key:generate
```

4. Configure your database credentials in `.env`.

5. Run migrations and seeders:

```bash
php artisan migrate --seed
```

6. Run the queue worker for scheduled and delayed jobs:

```bash
php artisan queue:work
```

7. Run the scheduler (for ForceExpireHoldsJob every minute):

```bash
php artisan schedule:run
```

---

## API Endpoints

* **Products**

  * `GET /api/products` — List all in-stock products with pagination
  * `GET /api/products/{id}` — Show a single product

* **Holds**

  * `POST /api/holds` — Create a hold for a product

* **Orders**

  * `POST /api/orders` — Create an order from a hold

* **Webhooks**

  * `POST /api/webhooks` — Receive external payment events (requires idempotency key)

---

## Notes

* Stock management is **atomic** and prevents overselling.
* Holds automatically expire after the TTL (default 2 minutes).
* Webhooks are **idempotent**; duplicate events are ignored.
* Logs are written for auditing all critical operations.


