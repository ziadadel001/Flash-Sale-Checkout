<?php

namespace App\Console\Commands;

use App\Models\Hold;
use App\Services\HoldService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ExpireOldHolds extends Command
{
    protected $signature = 'holds:expire-old';

    protected $description = 'Find and expire old holds that were never processed (fallback for failed jobs)';

    public function handle(HoldService $holdService): int
    {
        Log::info('hold_expiry_command_started');

        $expiredHolds = Hold::where('status', 'active')
            ->where('expires_at', '<=', now())
            ->limit(100)
            ->get();

        $count = 0;
        foreach ($expiredHolds as $hold) {
            try {
                if ($holdService->expireHold($hold->id)) {
                    $count++;
                }
            } catch (\Throwable $e) {
                Log::error('hold_expiry_failed', [
                    'hold_id' => $hold->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('hold_expiry_batch_completed', [
            'total_expired' => $count,
            'total_found' => $expiredHolds->count(),
        ]);

        $this->info("Expired {$count} holds");

        return Command::SUCCESS;
    }
}