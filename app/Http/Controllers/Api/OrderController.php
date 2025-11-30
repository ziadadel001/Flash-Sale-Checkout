<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Services\OrderService;
use App\Models\Order;
use Symfony\Component\HttpFoundation\Response;
use App\Traits\ApiResponse;

class OrderController extends Controller
{
    use ApiResponse;

    public function __construct(protected OrderService $service) {}

    /**
     * Create an order from a hold
     */
    public function store(StoreOrderRequest $request)
    {
        try {
            $order = $this->service->createOrderFromHold(
                $request->hold_id,
                $request->external_payment_id ?? null
            );

            return $this->success([
                'order_id' => $order->id,
                'status' => $order->status,
                'amount' => (float)$order->amount,
                'created_at' => $order->created_at->toDateTimeString(),
            ], Response::HTTP_CREATED);
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            if ($msg === 'hold_already_consumed') {
                return $this->error('hold_already_consumed', Response::HTTP_CONFLICT);
            }
            if ($msg === 'invalid_hold_state') {
                return $this->error('invalid_hold_state', Response::HTTP_GONE);
            }
            if ($msg === 'hold_expired') {
                return $this->error('hold_expired', Response::HTTP_GONE);
            }
            return $this->error($msg, Response::HTTP_BAD_REQUEST);
        } catch (\Throwable $e) {
            return $this->error('internal_error', Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Retrieve order status
     */
    public function show(Order $order)
    {
        return $this->success([
            'order_id' => $order->id,
            'status' => $order->status,
            'amount' => (float)$order->amount,
            'hold_id' => $order->hold_id,
            'external_payment_id' => $order->external_payment_id,
            'created_at' => $order->created_at->toDateTimeString(),
            'updated_at' => $order->updated_at->toDateTimeString(),
        ]);
    }
}
