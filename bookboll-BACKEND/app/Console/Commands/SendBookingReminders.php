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
        $reminderDays       = (int) Setting::where('key', 'reminder_emails_after_days')->value('value') ?: 7;
        $discountPercentage = (float) Setting::where('key', 'reminder_promocode_discount_percentage')->value('value') ?: 10;
        $validityDays       = (int) Setting::where('key', 'reminder_promocode_validity_days')->value('value') ?: 7;

        if ($reminderDays <= 0) {
            return 0;
        }

        $users = User::whereHas('bookings', fn($q) => $q->where('status', BookingStatus::COMPLETED))
            ->with(['bookings' => fn($q) => $q->completed()->orderByDesc('date')->select('user_id', 'date'), 'preferences'])
            ->get();

        if ($users->isEmpty()) {
            Log::info('No users found with completed bookings.');
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

                            // Check if user has marketing_emails enabled in preferences
                            $marketingEmailsEnabled = $user->preferences?->notifications['marketing_emails'] ?? false;
                            
                            if (! $marketingEmailsEnabled) {
                                Log::info("Skipping reminder for {$user->name} ({$user->email}) — marketing emails disabled in preferences");
                                continue;
                            }

                            try {
                                DB::beginTransaction();

                                $reminderNumber = $user->notifications()
                                    ->where('type', BookingReminderNotification::class)
                                    ->count() + 1;

                                $promocodeCode = $this->generateUniquePromocode($user->id);

                                $promocode = Promocode::create([
                                    'usage_limit'           => 1,
                                    'per_user_limit'        => 1,
                                    'min_order_amount'      => null,
                                    'max_discount_amount'   => null,
                                    'is_reminder_promocode' => true,
                                    'payment_methods'       => 'all',
                                    'status'                => 'active',
                                    'discount_type'         => 'percentage',
                                    'code'                  => $promocodeCode,
                                    'valid_from'            => Carbon::today(),
                                    'discount_value'        => $discountPercentage,
                                    'expires_at'            => Carbon::today()->addDays($validityDays),
                                    'description'           => "Special discount code for coming back! Get {$discountPercentage}% off your next booking.",
                                ]);

                                $promocode->users()->attach($user->id, ['usage_count' => 0]);

                                $user->notify(new BookingReminderNotification($daysSinceBooking, $promocode));

                                DB::commit();

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

    private function generateUniquePromocode(int $userId): string
    {
        $maxAttempts = 10;
        $attempts    = 0;

        do {
            $code   = 'REMINDER' . strtoupper(substr(md5($userId . time() . $attempts), 0, 6));
            $exists = Promocode::where('code', $code)->exists();
            $attempts++;
        } while ($exists && $attempts < $maxAttempts);

        if ($exists) {
            $code = 'REMINDER' . strtoupper(substr(md5($userId . microtime(true)), 0, 8));
        }

        return $code;
    }
}
