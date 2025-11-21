<?php
namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\User;
use App\Notifications\BookingReminderNotification;
use BookingStatus;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendBookingReminders extends Command
{
    protected $signature   = 'send-booking-reminders';
    protected $description = 'Send recurring booking reminders every X days after last completed booking';

    public function handle()
    {
        $reminderDays = (int) Setting::where('key', 'reminder_emails_after_days')->value('value') ?: 7;

        if ($reminderDays <= 0) {
            $this->info('Reminder days not configured. Skipping.');
            return 0;
        }

        $this->info("Sending reminders every {$reminderDays} days...");

        // Get users with completed bookings + their latest booking date
        $users = User::whereHas('bookings', fn($q) => $q->where('status', BookingStatus::COMPLETED))
            ->with(['bookings' => fn($q) => $q->completed()->orderByDesc('date')->select('user_id', 'date')])
            ->get();

        if ($users->isEmpty()) {
            $this->info('No users found with completed bookings.');
            return 0;
        }

        $sentCount = 0;
        $today     = Carbon::today();

        foreach ($users as $user) {
            $lastBooking = $user->bookings->first();

            if (! $lastBooking?->date) {
                continue;
            }

            $lastBookingDate  = Carbon::parse($lastBooking->date)->startOfDay();
            $daysSinceBooking = $lastBookingDate->diffInDays($today);

            if ($daysSinceBooking < $reminderDays) {
                continue;
            }

            $lastReminder = $user->notifications()
                ->where('type', BookingReminderNotification::class)
                ->latest('created_at')
                ->first();

            $daysSinceLastReminder = $lastReminder
                ? Carbon::parse($lastReminder->created_at)->startOfDay()->diffInDays($today)
                : $reminderDays + 1;

            if ($daysSinceLastReminder < $reminderDays) {
                continue;
            }

            $sentToday = $user->notifications()
                ->where('type', BookingReminderNotification::class)
                ->whereDate('created_at', $today)
                ->exists();

            if (! $sentToday) {

                try {
                    $reminderNumber = $user->notifications()
                        ->where('type', BookingReminderNotification::class)
                        ->count() + 1;

                    $user->notify(new BookingReminderNotification($daysSinceBooking));

                    $this->info("Reminder #{$reminderNumber} sent to {$user->name} ({$user->email}) — {$daysSinceBooking} days inactive");
                    Log::info("Reminder #{$reminderNumber} sent to {$user->name} ({$user->email}) — {$daysSinceBooking} days inactive");

                    $sentCount++;

                } catch (\Exception $e) {
                    Log::error('Failed to send booking reminder', [
                        'user_id' => $user->id,
                        'email'   => $user->email,
                        'error'   => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->newLine();
        $this->info("Done! Sent {$sentCount} reminder(s) today.");
        Log::info("Booking reminders completed. Sent: {$sentCount}");

        return 0;
    }
}
