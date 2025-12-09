<?php
namespace App\Console\Commands;

use App\Models\SlotLock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ClearExpiredSlotLocks extends Command
{
    protected $signature = 'slot-locks:clear';

    public function handle()
    {
        try {
            SlotLock::where('locked_until', '<', now())->delete();
            Log::info('Slots-locks cleared at: ' . date('Y-m-d_h-i-s')); //code...
        } catch (\Exception $e) {
            Log::error('Failed to clear expired slot locks: ' . $e->getMessage(), ['exception' => $e]);
        }

    }
}
