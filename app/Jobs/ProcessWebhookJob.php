<?php

namespace App\Jobs;

use App\Models\WebhookEvent;
use App\Services\WebhookService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Bus\Dispatchable;

class ProcessWebhookJob implements ShouldQueue
{
    use Dispatchable , InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(protected int $webhookId)
    {
    }

    public function handle(WebhookService $service)
    {
        $webhook = WebhookEvent::find($this->webhookId);
        if (! $webhook) return;
        $service->process($webhook);
    }
}
