<?php
namespace App\Console\Commands;

use App\Models\Promocode;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\BookingReminderNotification;
use BookingStatus;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SendBookingReminders extends Command
{
    protected $signature   = 'send-booking-reminders';
    protected $description = 'Send recurring booking reminders every X days after last completed booking';

    public function handle()
    {
        $reminderDays = (int) Setting::where('key', 'reminder_emails_after_days')->value('value') ?: 7;
        $discountPercentage = (float) Setting::where('key', 'reminder_promocode_discount_percentage')->value('value') ?: 10;

        if ($reminderDays <= 0) {
            return 0;
        }

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

            if ($lastBooking?->date) {

                $lastBookingDate  = Carbon::parse($lastBooking->date)->startOfDay();
                $daysSinceBooking = $lastBookingDate->diffInDays($today);

                if ($daysSinceBooking >= $reminderDays) {

                    $lastReminder = $user->notifications()
                        ->where('type', BookingReminderNotification::class)
                        ->latest('created_at')
                        ->first();

                    $daysSinceLastReminder = $lastReminder
                        ? Carbon::parse($lastReminder->created_at)->startOfDay()->diffInDays($today)
                        : $reminderDays + 1;

                    if ($daysSinceLastReminder >= $reminderDays) {

                        $sentToday = $user->notifications()
                            ->where('type', BookingReminderNotification::class)
                            ->whereDate('created_at', $today)
                            ->exists();

                        if (! $sentToday) {

                            try {
                                DB::beginTransaction();

                                $reminderNumber = $user->notifications()
                                    ->where('type', BookingReminderNotification::class)
                                    ->count() + 1;

                                // Generate unique promocode
                                $promocodeCode = $this->generateUniquePromocode($user->id);

                                // Create promocode for the user
                                $promocode = Promocode::create([
                                    'code'                  => $promocodeCode,
                                    'status'                => 'active',
                                    'description'           => "Special discount code for coming back! Get {$discountPercentage}% off your next booking.",
                                    'discount_type'         => 'percentage',
                                    'discount_value'         => $discountPercentage,
                                    'valid_from'            => Carbon::today(),
                                    'expires_at'            => Carbon::today()->addDays(30), // Valid for 30 days
                                    'usage_limit'           => 1, // Can be used once
                                    'per_user_limit'         => 1,
                                    'payment_methods'       => 'all',
                                    'min_order_amount'      => null,
                                    'max_discount_amount'   => null,
                                    'is_reminder_promocode' => true,
                                ]);

                                // Attach promocode to user via pivot table
                                $promocode->users()->attach($user->id, ['usage_count' => 0]);

                                $user->notify(new BookingReminderNotification($daysSinceBooking, $promocode));

                                DB::commit();

                                $this->info("Reminder #{$reminderNumber} sent to {$user->name} ({$user->email}) — {$daysSinceBooking} days inactive. Promocode: {$promocodeCode}");
                                Log::info("Reminder #{$reminderNumber} sent to {$user->name} ({$user->email}) — {$daysSinceBooking} days inactive. Promocode: {$promocodeCode}");

                                $sentCount++;

                            } catch (\Exception $e) {
                                DB::rollBack();
                                Log::error('Failed to send booking reminder', [
                                    'user_id' => $user->id,
                                    'email'   => $user->email,
                                    'error'   => $e->getMessage(),
                                ]);
                            }
                        }
                    }
                }
            }
        }
        Log::info("Booking reminders completed. Sent: {$sentCount}");

        return 0;
    }

    /**
     * Generate a unique promocode for the user
     */
    private function generateUniquePromocode(int $userId): string
    {
        $maxAttempts = 10;
        $attempts    = 0;

        do {
            // Generate code: REMINDER + hash
            $code = 'REMINDER' . strtoupper(substr(md5($userId . time() . $attempts), 0, 6));
            $exists = Promocode::where('code', $code)->exists();
            $attempts++;
        } while ($exists && $attempts < $maxAttempts);

        if ($exists) {
            // Fallback: use timestamp-based code
            $code = 'REMINDER' . strtoupper(substr(md5($userId . microtime(true)), 0, 8));
        }

        return $code;
    }
}
