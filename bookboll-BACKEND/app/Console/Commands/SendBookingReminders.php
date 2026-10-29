<?php

namespace App\Console\Commands;

use App\Models\Promocode;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\BookingReminderNotification;
use BookingStatus;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * "We miss you" reminders, with a one-off promocode, for customers whose last
 * eligible booking is at least N days old and who have not been reminded in the
 * last N days.
 *
 * Runs on shared hosting under a hard memory ceiling, so it never loads the
 * customer base at once: the "who is due" decision is made in SQL and the
 * matches are walked in chunks. The notification itself is queued, so this
 * command only writes rows; the queue worker does the SMTP round trips.
 */
class SendBookingReminders extends Command
{
    protected $signature = 'send-booking-reminders
        {--chunk=200 : How many due users to load per batch}';

    protected $description = 'Send recurring booking reminders every X days after last eligible booking';

    public function handle(): int
    {
        $reminderDays       = (int) Setting::where('key', 'reminder_emails_after_days')->value('value') ?: 7;
        $discountPercentage = (float) Setting::where('key', 'reminder_promocode_discount_percentage')->value('value') ?: 10;
        $validityDays       = (int) Setting::where('key', 'reminder_promocode_validity_days')->value('value') ?: 7;

        if ($reminderDays <= 0) {
            return self::SUCCESS;
        }

        $today = Carbon::today();

        // A booking (or reminder) dated on/after this day is fewer than
        // $reminderDays days old and therefore makes the user not yet due.
        $since = $today->copy()->subDays($reminderDays - 1);

        $eligible = fn (Builder $q) => $q->where(function (Builder $b) {
            $b->whereIn('status', [BookingStatus::COMPLETED, BookingStatus::CONFIRMED])
                ->orWhere('full_payment_received', true);
        });

        $due = User::query()
            // Has at least one eligible booking...
            ->whereHas('bookings', $eligible)
            // ...none of which is recent (a future booking counts as recent).
            ->whereDoesntHave('bookings', fn (Builder $q) => $eligible($q)
                ->where('date', '>=', $since->toDateString()))
            // ...and no reminder in the last $reminderDays days, today included.
            ->whereDoesntHave('notifications', fn (Builder $q) => $q
                ->where('type', BookingReminderNotification::class)
                ->where('created_at', '>=', $since))
            ->withMax(['bookings as last_booking_date' => $eligible], 'date')
            ->withCount(['notifications as reminder_count' => fn (Builder $q) => $q
                ->where('type', BookingReminderNotification::class)])
            ->with('preferences');

        $sent    = 0;
        $skipped = 0;
        $failed  = 0;

        $due->chunkById(max(1, (int) $this->option('chunk')), function (Collection $users) use (
            $today, $discountPercentage, $validityDays, &$sent, &$skipped, &$failed
        ) {
            foreach ($users as $user) {
                if (! $user->last_booking_date) {
                    continue;
                }

                if (! ($user->preferences?->notifications['marketing_emails'] ?? false)) {
                    $skipped++;
                    continue;
                }

                if (! filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
                    $skipped++;
                    Log::warning('Skipping booking reminder — invalid email address', [
                        'user_id' => $user->id,
                        'email'   => $user->email,
                    ]);
                    continue;
                }

                $daysSinceBooking = (int) abs(Carbon::parse($user->last_booking_date)->startOfDay()->diffInDays($today));
                $reminderNumber   = (int) $user->reminder_count + 1;

                try {
                    $promocode = DB::transaction(function () use ($user, $discountPercentage, $validityDays, $daysSinceBooking) {
                        $promocode = Promocode::create([
                            'usage_limit'           => 1,
                            'per_user_limit'        => 1,
                            'min_order_amount'      => null,
                            'max_discount_amount'   => null,
                            'is_reminder_promocode' => true,
                            'payment_methods'       => 'all',
                            'status'                => 'active',
                            'discount_type'         => 'percentage',
                            'code'                  => $this->generateUniquePromocode(),
                            'valid_from'            => Carbon::today(),
                            'discount_value'        => $discountPercentage,
                            'expires_at'            => Carbon::today()->addDays($validityDays),
                            'description'           => "Special discount code for coming back! Get {$discountPercentage}% off your next booking.",
                        ]);

                        $promocode->users()->attach($user->id, ['usage_count' => 0]);

                        // Queued: this inserts jobs on the same connection, so they
                        // commit (or roll back) together with the promocode.
                        $user->notify(new BookingReminderNotification($daysSinceBooking, $promocode));

                        return $promocode;
                    });

                    $sent++;
                    Log::info("Reminder #{$reminderNumber} queued for {$user->name} ({$user->email}) — {$daysSinceBooking} days inactive. Promocode: {$promocode->code}");
                } catch (\Throwable $e) {
                    $failed++;
                    Log::error('Failed to queue booking reminder', [
                        'user_id' => $user->id,
                        'email'   => $user->email,
                        'error'   => $e->getMessage(),
                    ]);
                }
            }
        });

        $summary = "Booking reminders completed. Queued: {$sent}, skipped: {$skipped}, failed: {$failed}";
        Log::info($summary);
        $this->info($summary);

        return self::SUCCESS;
    }

    private function generateUniquePromocode(): string
    {
        do {
            $code = 'REMINDER' . Str::upper(Str::random(6));
        } while (Promocode::where('code', $code)->exists());

        return $code;
    }
}
