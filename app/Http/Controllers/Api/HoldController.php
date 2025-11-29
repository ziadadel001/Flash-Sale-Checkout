<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\HoldService;
use Symfony\Component\HttpFoundation\Response;

class HoldController extends Controller
{
    public function __construct(protected HoldService $service)
    {
    }

    public function store(Request $req)
    {
        $data = $req->validate([
            'product_id' => 'required|integer|exists:products,id',
            'qty' => 'required|integer|min:1',
            'ttl_minutes' => 'sometimes|integer|min:1|max:60',
        ]);

        $hold = $this->service->createHold($data['product_id'], $data['qty'], $data['ttl_minutes'] ?? 2);

        return response()->json([
            'hold_id' => $hold->id,
            'expires_at' => $hold->expires_at,
        ], Response::HTTP_CREATED);
    }
}
