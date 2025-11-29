<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\WebhookService;
use Symfony\Component\HttpFoundation\Response;

class WebhookController extends Controller
{
    public function __construct(protected WebhookService $service)
    {
    }

    public function handle(Request $req)
    {
        // expect idempotency key in header or payload
        $key = $req->header('Idempotency-Key') ?? $req->input('idempotency_key') ?? null;
        if (! $key) {
            return response()->json(['error' => 'missing_idempotency_key'], 400);
        }

        $payload = $req->all();

        $webhook = $this->service->handleIncoming($key, $payload);

        return response()->json(['status' => 'accepted', 'webhook_id' => $webhook->id], Response::HTTP_ACCEPTED);
    }
}
