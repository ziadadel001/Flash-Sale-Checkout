<?php

namespace App\Jobs;

use App\Models\Hold;
use App\Services\HoldService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Bus\Dispatchable;

class ForceExpireHoldsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(HoldService $service)
    {
        Hold::where('status', 'active')
            ->where('expires_at', '<=', now())
            ->chunkById(50, function ($holds) use ($service) {
                foreach ($holds as $hold) {
                    $service->expireHold($hold->id);
                }
            });
    }
}
