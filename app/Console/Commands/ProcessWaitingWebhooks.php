<?php

namespace App\Console\Commands;

use App\Services\WebhookService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessWaitingWebhooks extends Command
{
    protected $signature = 'webhooks:process-waiting';

    protected $description = 'Process webhooks that are waiting for order creation (called by scheduler)';

    public function handle(WebhookService $webhookService): int
    {
        Log::info('webhook_retry_command_started');

        $processed = $webhookService->processWaitingWebhooks();

        $this->info("Processed {$processed} waiting webhooks");

        return Command::SUCCESS;
    }
}
