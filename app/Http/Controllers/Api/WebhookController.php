<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\WebhookService;
use Symfony\Component\HttpFoundation\Response;
use App\Traits\ApiResponse;

class WebhookController extends Controller
{
    use ApiResponse;

    public function __construct(protected WebhookService $service)
    {
    }

    public function handle(Request $req)
    {
        try {
            // expect idempotency key in header or payload
            $key = $req->header('Idempotency-Key') ?? $req->input('idempotency_key') ?? null;
            if (! $key) {
                return $this->error('missing_idempotency_key', Response::HTTP_BAD_REQUEST);
            }

            $payload = $req->all();
            $webhook = $this->service->handleIncoming($key, $payload);

            return $this->success([
                'status' => 'accepted',
                'webhook_id' => $webhook->id,
            ], Response::HTTP_ACCEPTED);

        } catch (\Exception $e) {
            return $this->error($e->getMessage(), Response::HTTP_BAD_REQUEST);
        }
    }
}
