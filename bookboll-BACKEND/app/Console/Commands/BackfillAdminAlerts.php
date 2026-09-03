<?php

namespace App\Console\Commands;

use App\Models\ClassProgram;
use App\Models\Listing;
use App\Models\ProviderWallet;
use App\Models\User;
use App\Models\Venue;
use App\Models\WithdrawalRequest;
use App\Notifications\AdminAlert;
use App\Support\AdminAlerts;
use Illuminate\Console\Command;

/**
 * Seed the bell from work that is already outstanding.
 *
 * Notifications are written as things happen, so a fresh install shows an empty
 * bell even when items are waiting for review. This raises one alert per open
 * item. Safe to re-run: existing alerts with the same title are skipped.
 */
class BackfillAdminAlerts extends Command
{
    protected $signature = 'bell:backfill {--fresh : Delete existing bell notifications first}';

    protected $description = 'Create bell notifications for work that is currently outstanding';

    public function handle(): int
    {
        if ($this->option('fresh')) {
            $n = \DB::table('notifications')->where('type', AdminAlert::class)->delete();
            $this->warn("Deleted {$n} existing bell notification(s).");
        }

        $made = 0;
        $made += $this->pendingListings();
        $made += $this->pendingPrograms();
        $made += $this->pendingWallets();
        $made += $this->pendingWithdrawals();

        $this->info("Created {$made} notification(s).");

        return self::SUCCESS;
    }

    protected function pendingListings(): int
    {
        $n = 0;
        foreach (Listing::where('approval_status', 'pending')->get() as $l) {
            $venueName = Venue::where('id', $l->venue_id)->value('name');
            if ($this->raisePlatform('approval', 'Listing awaiting approval — “'.$l->name.'”', $venueName ?: 'Review it in Approvals')) {
                $n++;
            }
        }

        return $n;
    }

    protected function pendingPrograms(): int
    {
        $n = 0;
        foreach (ClassProgram::where('approval_status', 'pending')->get() as $p) {
            $venueName = Venue::where('id', $p->venue_id)->value('name');
            if ($this->raisePlatform('approval', 'Program awaiting approval — “'.$p->name.'”', $venueName ?: 'Review it in Approvals')) {
                $n++;
            }
        }

        return $n;
    }

    protected function pendingWallets(): int
    {
        $n = 0;
        foreach (ProviderWallet::where('setup_status', 'pending_review')->get() as $w) {
            $name = $this->ownerName($w->owner_user_id);
            if ($this->raisePlatform('wallet', 'Payout method to review — '.$name, 'Verify the payout details in Approvals')) {
                $n++;
            }
        }

        return $n;
    }

    protected function pendingWithdrawals(): int
    {
        $n = 0;
        foreach (WithdrawalRequest::where('status', 'pending')->get() as $w) {
            if ($this->raisePlatform('wallet', 'Withdrawal request — '.$w->amount.' '.$w->currency, $this->ownerName($w->owner_user_id))) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * Notify every staff member who doesn't already have this alert.
     * The check is PER USER — a title existing for one admin must not stop
     * another admin (or a newly added one) from getting it.
     *
     * @return bool true when at least one notification was created
     */
    protected function raisePlatform(string $kind, string $title, string $sub): bool
    {
        $missing = AdminAlerts::platformStaff()->filter(
            fn ($u) => ! $u->notifications()
                ->where('type', AdminAlert::class)
                ->where('data->title', $title)
                ->exists()
        );

        if ($missing->isEmpty()) {
            return false;
        }

        AdminAlerts::toUsers($missing, $kind, 'info', $title, $sub, '/approvals');

        return true;
    }

    protected function ownerName(?string $ownerUserId): string
    {
        if (! $ownerUserId) {
            return 'a provider';
        }

        $user = is_numeric($ownerUserId)
            ? User::find((int) $ownerUserId)
            : User::where('uuid', $ownerUserId)->first();

        return $user?->name ?: 'a provider';
    }
}
