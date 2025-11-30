<?php

namespace App\Http\Controllers\Api;

use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use App\Services\WebhookService;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\Response;

class WebhookController extends Controller
{
    use ApiResponse;

    public function __construct(protected WebhookService $service)
    {
    }

   public function handle(Request $req)
{
    try {
        $key = $req->header('Idempotency-Key') ?? $req->input('idempotency_key') ?? null;
        if (!$key) {
            return $this->error('missing_idempotency_key', Response::HTTP_BAD_REQUEST);
        }

        $payload = $req->all();
        if (!isset($payload['status']) || !isset($payload['payment_id'])) {
            return $this->error('invalid_payload_missing_fields', Response::HTTP_BAD_REQUEST);
        }

        $webhook = $this->service->handleIncoming($key, $payload);

        return $this->success([
            'status' => 'accepted',
            'webhook_id' => $webhook->id,
        ], Response::HTTP_ACCEPTED);

    } catch (\InvalidArgumentException $e) {
        return $this->error($e->getMessage(), Response::HTTP_BAD_REQUEST);
    } catch (\Exception $e) {
        Log::error('Webhook processing error: ' . $e->getMessage(), [
            'exception' => $e
        ]);
        return $this->error('internal_error', Response::HTTP_INTERNAL_SERVER_ERROR);
    }
}
}