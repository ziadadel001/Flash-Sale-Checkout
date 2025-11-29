<?php

namespace App\Jobs;

use App\Models\Hold;
use App\Services\HoldService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Bus\Dispatchable;

class ExpireHoldJob implements ShouldQueue
{
    use Dispatchable ,InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(protected int $holdId)
    {
    }

    public function handle(HoldService $holdService)
    {
        // Try expire idempotent
        $holdService->expireHold($this->holdId);
    }
}
