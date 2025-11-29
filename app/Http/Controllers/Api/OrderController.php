<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\OrderService;
use Symfony\Component\HttpFoundation\Response;
use App\Traits\ApiResponse;

class OrderController extends Controller
{
    use ApiResponse;

    public function __construct(protected OrderService $service)
    {
    }

    public function store(Request $req)
    {
        $data = $req->validate([
            'hold_id' => 'required|integer|exists:holds,id',
            'external_payment_id' => 'sometimes|string',
        ]);

        try {
            $order = $this->service->createOrderFromHold(
                $data['hold_id'],
                $data['external_payment_id'] ?? null
            );

            return $this->success([
                'order_id' => $order->id,
                'status' => $order->status,
                'amount' => $order->amount,
            ], Response::HTTP_CREATED);

        } catch (\Exception $e) {
            return $this->error($e->getMessage(), Response::HTTP_BAD_REQUEST);
        }
    }
}
