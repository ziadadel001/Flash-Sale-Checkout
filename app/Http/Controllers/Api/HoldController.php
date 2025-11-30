<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreHoldRequest;
use App\Services\HoldService;
use Symfony\Component\HttpFoundation\Response;
use App\Traits\ApiResponse;

class HoldController extends Controller
{
    use ApiResponse;

    public function __construct(protected HoldService $service)
    {
    }

    public function store(StoreHoldRequest $request)
    {
        try {
            $hold = $this->service->createHold(
                $request->product_id,
                $request->qty,
                $request->ttl_minutes ?? 2
            );

            return $this->success([
                'hold_id' => $hold->id,
                'expires_at' => $hold->expires_at,
            ], Response::HTTP_CREATED);

       } catch (\RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'not_enough_stock') {
            return $this->error('not_enough_stock', Response::HTTP_CONFLICT);
        }

        if ($msg === 'invalid_or_expired_hold') {
            return $this->error('hold_expired', Response::HTTP_GONE);
        }

        // default
        return $this->error($msg, Response::HTTP_BAD_REQUEST);
    } catch (\Throwable $e) {
        return $this->error('internal_error', Response::HTTP_INTERNAL_SERVER_ERROR);
    }
    }
}
