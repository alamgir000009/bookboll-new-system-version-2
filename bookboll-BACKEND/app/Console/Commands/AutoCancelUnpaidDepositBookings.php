<?php
namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\Setting;
use App\Models\StripeSession;
use App\Services\BookingService;
use BookingPaymentMethod;
use BookingPaymentStatus;
use BookingStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;

class AutoCancelUnpaidDepositBookings extends Command
{
    protected $signature = 'bookings:auto-cancel-unpaid-deposit';
    protected $description = 'Auto-cancel unpaid online/deposit bookings after configured timeout';

    public function handle(): int
    {
        $timeoutMinutes = (int) (Setting::where('key', 'deposit_payment_timeout_minutes')->value('value') ?: 10);

        if ($timeoutMinutes <= 0) {
            Log::info('Auto-cancel unpaid deposit bookings skipped because timeout is <= 0.');
            return self::SUCCESS;
        }

        $reason = "The required payment was not completed within {$timeoutMinutes} minutes, so the booking has been cancelled.";
        $cutoff = now()->subMinutes($timeoutMinutes);
        $cancelledCount = 0;
        $stripeSecret = $this->resolveStripeSecret();
        $stripeClient = $stripeSecret ? new StripeClient($stripeSecret) : null;

        Booking::whereIn('payment_method', [
                BookingPaymentMethod::ONLINE_PAYMENT,
                BookingPaymentMethod::DEPOSIT_REMAINING_ON_ARRIVAL,
            ])
            ->where('payment_status', BookingPaymentStatus::PENDING)
            ->whereIn('status', [BookingStatus::PENDING, BookingStatus::CONFIRMED])
            ->where('created_at', '<=', $cutoff)
            ->with(['user', 'venue:id,name,maps_url', 'listing:id,name'])
            ->orderBy('id')
            ->chunkById(100, function ($bookings) use ($reason, &$cancelledCount, $stripeClient) {
                foreach ($bookings as $booking) {
                    try {
                        DB::transaction(function () use ($booking, $reason, &$cancelledCount, $stripeClient) {
                            $lockedBooking = Booking::whereKey($booking->id)
                                ->lockForUpdate()
                                ->first();

                            if (! $lockedBooking) {
                                return;
                            }

                            if (
                                ! in_array($lockedBooking->payment_method, [
                                    BookingPaymentMethod::DEPOSIT_REMAINING_ON_ARRIVAL,
                                    BookingPaymentMethod::ONLINE_PAYMENT,
                                ], true)
                                || $lockedBooking->payment_status !== BookingPaymentStatus::PENDING
                                || ! in_array($lockedBooking->status, [BookingStatus::PENDING, BookingStatus::CONFIRMED], true)
                            ) {
                                return;
                            }

                            // Expire Stripe checkout sessions FIRST so the customer cannot complete
                            // payment after we decide to cancel (avoids race where payment lands seconds later).
                            if ($stripeClient) {
                                try {
                                    $this->expireOpenStripeCheckoutSessions($lockedBooking, $stripeClient);
                                } catch (\Throwable $e) {
                                    Log::error('Failed to expire Stripe checkout sessions for auto-cancelled booking', [
                                        'booking_id' => $lockedBooking->id,
                                        'error'      => $e->getMessage(),
                                    ]);
                                }
                            }

                            // Re-check payment status after expiring sessions (payment may have completed in the meantime).
                            $lockedBooking->refresh();
                            if (
                                $lockedBooking->payment_status !== BookingPaymentStatus::PENDING
                                || ! in_array($lockedBooking->status, [BookingStatus::PENDING, BookingStatus::CONFIRMED], true)
                            ) {
                                return;
                            }

                            $previousStatus        = $lockedBooking->status;
                            $previousPaymentStatus = $lockedBooking->payment_status;

                            expireOpenStripeCheckoutSessionsForBooking($lockedBooking);

                            $lockedBooking->update([
                                'cancelled_by'  => null,
                                'cancelled_at'  => now(),
                                'cancel_reason' => $reason,
                                'status'        => BookingStatus::CANCELLED,
                            ]);

                            processBookingCancellation(
                                $lockedBooking,
                                $lockedBooking->user_id,
                                $reason,
                                true,
                                $previousStatus,
                                $previousPaymentStatus
                            );

                            $lockedBooking->loadMissing(['user', 'venue:id,name,maps_url', 'listing:id,name']);
                            app(BookingService::class)->sendBookingNotification($lockedBooking, 'cancelled');

                            $cancelledCount++;

                            Log::info('Auto-cancelled unpaid booking', [
                                'booking_id' => $lockedBooking->booking_id,
                                'user_id'    => $lockedBooking->user_id,
                                'venue_id'   => $lockedBooking->venue_id,
                                'listing_id' => $lockedBooking->listing_id,
                                'method'     => $lockedBooking->payment_method,
                            ]);
                        });
                    } catch (\Throwable $e) {
                        Log::error('Failed to auto-cancel unpaid booking', [
                            'booking_id' => $booking->id,
                            'error'      => $e->getMessage(),
                        ]);
                    }
                }
            });

        Log::info("Auto-cancel unpaid bookings completed. Cancelled: {$cancelledCount}");

        return self::SUCCESS;
    }

    protected function resolveStripeSecret(): ?string
    {
        $configSecret = trim((string) config('services.stripe.secret'));
        if ($configSecret !== '') {
            return $configSecret;
        }

        $settingSecret = trim((string) Setting::where('key', 'stripe_secret_key')->value('value'));
        if ($settingSecret !== '') {
            return $settingSecret;
        }

        Log::warning('Stripe secret key is missing. Stripe checkout sessions will not be expired by auto-cancel command.');
        return null;
    }

    protected function expireOpenStripeCheckoutSessions(Booking $booking, StripeClient $stripeClient): void
    {
        $sessions = StripeSession::where('processed', false)
            ->where(function ($query) use ($booking) {
                $query->where('booking_id', $booking->id)
                    ->orWhereJsonContains('booking_ids', $booking->id);
            })
            ->get();

        foreach ($sessions as $stripeSession) {
            try {
                $session = $stripeClient->checkout->sessions->retrieve($stripeSession->session_id);

                if (($session->status ?? null) === 'open') {
                    $stripeClient->checkout->sessions->expire($stripeSession->session_id);
                    Log::info('Expired Stripe checkout session for auto-cancelled booking', [
                        'booking_id'  => $booking->id,
                        'session_id'  => $stripeSession->session_id,
                        'booking_ref' => $booking->booking_id,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error('Failed to expire Stripe checkout session', [
                    'booking_id' => $booking->id,
                    'session_id' => $stripeSession->session_id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }
    }
}
