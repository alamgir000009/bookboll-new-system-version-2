<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\ClassProgram;
use App\Models\ClassSession;
use App\Models\ClassSubscription;
use App\Models\Payment;
use App\Models\User;
use App\Models\Venue;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Admin dashboard API for /subscriptions (Subscribers.vue).
 */
class ClassMembershipDashboardService
{
    public const PLATFORM_COMMISSION = 15;

    public const DEFAULT_TRIAL_DAYS = 14;

    public const INTERVAL_MONTHS = [
        'weekly'   => 0.25,
        'biweekly' => 0.5,
        'monthly'  => 1,
        'quarterly'=> 3,
        'yearly'   => 12,
    ];

    public function bootstrap($request)
    {
        $this->windDownSweep();

        $programs = ClassProgram::query()->orderByDesc('created_at')->get();
        $subscriptions = ClassSubscription::query()->orderByDesc('created_at')->get();

        $sessionCounts = ClassSession::query()
            ->selectRaw('subscription_id, count(*) as c')
            ->groupBy('subscription_id')
            ->pluck('c', 'subscription_id');

        $sessions = ClassSession::query()
            ->select(['id', 'subscription_id', 'program_id', 'status'])
            ->get()
            ->map(fn (ClassSession $s) => [
                'id'              => $s->id,
                'subscription_id' => $s->subscription_id,
                'program_id'      => $s->program_id,
                'status'          => $s->status,
            ])
            ->values()
            ->all();

        $providers = $this->buildProviders($programs);

        return sendSuccess('Subscriptions dashboard fetched', [
            'subscriptions' => $subscriptions->map(fn (ClassSubscription $s) => $this->mapSubscription(
                $s,
                (int) ($sessionCounts[$s->id] ?? 0)
            ))->values()->all(),
            'programs'      => $programs->map(fn (ClassProgram $p) => $this->mapProgram($p))->values()->all(),
            'sessions'      => $sessions,
            'providers'     => $providers,
        ]);
    }

    public function show($id)
    {
        $sub = ClassSubscription::query()->find($id);
        if (! $sub) {
            return sendError('Subscription not found', [], 404);
        }

        $program = ClassProgram::query()->find($sub->program_id);
        $sessionCount = ClassSession::query()->where('subscription_id', $sub->id)->count();

        return sendSuccess('Subscription fetched', [
            'subscription' => $this->mapSubscription($sub, $sessionCount),
            'program'      => $program ? $this->mapProgram($program) : null,
            'payments'     => $this->paymentsFor($sub->id),
        ]);
    }

    public function payments($id)
    {
        $sub = ClassSubscription::query()->find($id);
        if (! $sub) {
            return sendError('Subscription not found', [], 404);
        }

        return sendSuccess('Payments fetched', [
            'payments' => $this->paymentsFor($sub->id),
        ]);
    }

    public function store($request)
    {
        $data = $request->validate([
            'program_id'      => 'required|uuid|exists:class_programs,id',
            'customer_name'   => 'required|string|max:255',
            'customer_email'  => 'nullable|string|max:255',
            'started_at'      => 'required|date',
            'card_on_file_id' => 'nullable|string|max:255',
            'trial_consent'   => 'nullable|boolean',
            'comp'            => 'nullable|boolean',
            'comp_reason'     => 'nullable|string|max:255',
        ]);

        $program = ClassProgram::query()->findOrFail($data['program_id']);
        if ($this->rowState($program) !== 'active' || ($program->status ?? '') !== 'active') {
            return sendError('This program is not active.', [], 422);
        }

        $isComped = ! empty($data['comp']);
        if (! $isComped && (empty($data['card_on_file_id']) || empty($data['trial_consent']))) {
            return sendError('Please complete the required fields and trial consent.', [], 422);
        }

        $plan = $this->planFor($program);
        $startedAt = Carbon::parse($data['started_at'])->toDateString();
        $billing = $this->computeTrialBilling($program, $plan, $startedAt, $isComped);

        $commission = (float) ($program->commission_percentage ?: self::PLATFORM_COMMISSION);
        $amount = $isComped ? 0.0 : (float) $plan['price'];
        $platformFee = $isComped ? 0.0 : round($amount * $commission / 100, 2);
        $providerPayout = $isComped ? 0.0 : round($amount - $platformFee, 2);

        DB::beginTransaction();
        try {
            $sub = ClassSubscription::query()->create([
                'id'               => (string) Str::uuid(),
                'program_id'       => $program->id,
                'customer_name'    => trim($data['customer_name']),
                'customer_email'   => $data['customer_email'] ?? '',
                'started_at'       => $startedAt,
                'next_billing_at'  => $billing['next_billing_at'],
                'trial_ends_at'    => $billing['trial_ends_at'],
                'trial_status'     => $billing['trial_status'],
                'card_on_file_id'  => $data['card_on_file_id'] ?? '',
                'monthly_amount'   => $amount,
                'provider_payout'  => $providerPayout,
                'platform_fee'     => $platformFee,
                'joining_fee'      => (float) ($program->joining_fee ?? 0),
                'status'           => 'active',
                'billing_interval' => $plan['interval'],
                'is_comp'          => $isComped,
                'comp_reason'      => $isComped ? ($data['comp_reason'] ?? null) : null,
                'form_answers'     => [],
                'country_id'       => $program->country_id ?? null,
                'wallet_id'        => null,
            ]);

            $this->generateFirstMonthSessions($sub, $program, $startedAt);

            if ($billing['trial_status'] === 'active' && ! $isComped) {
                $this->recordSubscriptionPayment($sub, $program, true);
                $sub->update(['first_charge_at' => now()]);
            }

            DB::commit();

            if ($program->venue_id) {
                notifyVenueStaffRefresh((int) $program->venue_id, [
                    'event'           => 'subscription_created',
                    'subscription_id' => $sub->id,
                    'program_id'      => $program->id,
                    'bell'            => true,
                ]);
            }

            $sub->refresh();

            return sendSuccess('Subscriber added', [
                'subscription' => $this->mapSubscription($sub, ClassSession::where('subscription_id', $sub->id)->count()),
                'charged'      => $billing['trial_status'] === 'active' && ! $isComped,
                'has_trial'    => $billing['trial_status'] === 'in_trial',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('subscription store failed: ' . $e->getMessage(), ['exception' => $e]);

            return sendError('Could not add subscriber.');
        }
    }

    public function charge($request, string $id)
    {
        return $this->doCharge($id, false);
    }

    public function retryCharge($request, string $id)
    {
        return $this->doCharge($id, true);
    }

    public function cancel($request, string $id)
    {
        $data = $request->validate([
            'cancel_reason' => 'nullable|string|max:255',
        ]);

        $sub = ClassSubscription::query()->find($id);
        if (! $sub) {
            return sendError('Subscription not found', [], 404);
        }

        $patch = ['status' => 'cancelled', 'cancelled_at' => now()];
        if ($sub->trial_status === 'in_trial') {
            $patch['trial_status'] = 'cancelled_in_trial';
            $patch['cancelled_during_trial_at'] = now();
        }
        if (! empty($data['cancel_reason'])) {
            $patch['cancel_reason'] = $data['cancel_reason'];
        }

        $sub->update($patch);
        ClassSession::query()
            ->where('subscription_id', $sub->id)
            ->where('status', 'scheduled')
            ->update(['status' => 'cancelled']);

        $this->refreshVenue($sub);

        return sendSuccess('Subscription cancelled', [
            'subscription' => $this->mapSubscription($sub->fresh()),
        ]);
    }

    public function bulkCancel($request)
    {
        $data = $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'uuid',
        ]);

        $n = 0;
        foreach ($data['ids'] as $id) {
            $sub = ClassSubscription::query()->find($id);
            if (! $sub) {
                continue;
            }
            $patch = ['status' => 'cancelled', 'cancelled_at' => now()];
            if ($sub->trial_status === 'in_trial') {
                $patch['trial_status'] = 'cancelled_in_trial';
                $patch['cancelled_during_trial_at'] = now();
            }
            $sub->update($patch);
            ClassSession::query()
                ->where('subscription_id', $sub->id)
                ->where('status', 'scheduled')
                ->update(['status' => 'cancelled']);
            $n++;
        }

        return sendSuccess("Cancelled {$n} subscription(s)", ['count' => $n]);
    }

    public function pause($request, string $id)
    {
        $sub = ClassSubscription::query()->find($id);
        if (! $sub) {
            return sendError('Subscription not found', [], 404);
        }

        $sub->update([
            'status'          => 'paused',
            'next_billing_at' => null,
            'paused_until'    => null,
        ]);
        ClassSession::query()
            ->where('subscription_id', $sub->id)
            ->where('status', 'scheduled')
            ->update(['status' => 'skipped']);

        $this->refreshVenue($sub);

        return sendSuccess('Subscription paused', [
            'subscription' => $this->mapSubscription($sub->fresh()),
        ]);
    }

    public function resume($request, string $id)
    {
        $sub = ClassSubscription::query()->find($id);
        if (! $sub) {
            return sendError('Subscription not found', [], 404);
        }
        $program = ClassProgram::query()->find($sub->program_id);
        $today = Carbon::today()->toDateString();
        $next = $this->nextBillingAfterToday($program, $sub, $today);

        $sub->update([
            'status'          => 'active',
            'next_billing_at' => $next,
            'paused_until'    => null,
        ]);
        ClassSession::query()
            ->where('subscription_id', $sub->id)
            ->where('status', 'skipped')
            ->where('session_date', '>=', $today)
            ->update(['status' => 'scheduled']);

        $this->refreshVenue($sub);

        return sendSuccess('Subscription resumed', [
            'subscription' => $this->mapSubscription($sub->fresh()),
        ]);
    }

    public function markPastDue($request, string $id)
    {
        $sub = ClassSubscription::query()->find($id);
        if (! $sub) {
            return sendError('Subscription not found', [], 404);
        }

        $sub->update([
            'status'            => 'past_due',
            'payment_failed_at' => now(),
        ]);

        $this->refreshVenue($sub);

        return sendSuccess('Member marked past-due', [
            'subscription' => $this->mapSubscription($sub->fresh()),
        ]);
    }

    public function retentionMonthOff($request, string $id)
    {
        $data = $request->validate([
            'cancel_reason' => 'nullable|string|max:255',
        ]);

        $sub = ClassSubscription::query()->find($id);
        if (! $sub) {
            return sendError('Subscription not found', [], 404);
        }

        $base = $sub->next_billing_at
            ? Carbon::parse($sub->next_billing_at)->toDateString()
            : Carbon::today()->toDateString();
        $next = $this->addIntervalToDate($base, 'monthly');
        $reason = ! empty($data['cancel_reason'])
            ? ('saved:' . $data['cancel_reason'])
            : 'saved';

        $sub->update([
            'next_billing_at' => $next,
            'cancel_reason'   => $reason,
        ]);

        return sendSuccess('1 month applied', [
            'subscription' => $this->mapSubscription($sub->fresh()),
        ]);
    }

    public function winback($request, string $id)
    {
        $sub = ClassSubscription::query()->find($id);
        if (! $sub) {
            return sendError('Subscription not found', [], 404);
        }

        // Customer WhatsApp is still FE-driven; acknowledge for API parity.
        return sendSuccess('Win-back acknowledged', [
            'subscription' => $this->mapSubscription($sub),
        ]);
    }

    /**
     * Hard-delete a membership program (and cascade subs/sessions).
     * Mirrors admin ClassesSubscriptions.hardDeleteProgram.
     */
    public function destroyProgram(string $id)
    {
        $program = ClassProgram::query()->find($id);
        if (! $program) {
            return sendError('Program not found', [], 404);
        }

        $venueId = $program->venue_id ? (int) $program->venue_id : null;

        DB::beginTransaction();
        try {
            Booking::query()
                ->where('program_id', $program->id)
                ->where('origin', 'class_program')
                ->delete();

            // Explicitly clear children first in case FK cascade is missing on some envs.
            ClassSession::query()->where('program_id', $program->id)->delete();
            ClassSubscription::query()->where('program_id', $program->id)->delete();
            $program->delete();

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('program destroy failed: ' . $e->getMessage(), ['exception' => $e]);

            return sendError('Could not delete program.');
        }

        if ($venueId) {
            notifyVenueStaffRefresh($venueId, [
                'event'      => 'program_deleted',
                'program_id' => $id,
                'bell'       => true,
            ]);
        }

        return sendSuccess('Program deleted');
    }

    // ── Charge core ──────────────────────────────────────────────────────────

    protected function doCharge(string $id, bool $isRetry)
    {
        $sub = ClassSubscription::query()->find($id);
        if (! $sub) {
            return sendError('Subscription not found', [], 404);
        }
        $program = ClassProgram::query()->find($sub->program_id);
        if (! $program || ($program->status ?? '') !== 'active' || $this->rowState($program) !== 'active') {
            return sendError('This program is paused or archived — its subscriptions can’t be charged.', [], 422);
        }

        $isFirst = $sub->trial_status === 'in_trial'
            || (! $sub->first_charge_at && $sub->trial_status !== 'active');

        $today = Carbon::today()->toDateString();
        $next = $this->nextBillingAfterToday($program, $sub, $today);

        DB::beginTransaction();
        try {
            $this->recordSubscriptionPayment($sub, $program, $isFirst);
            $patch = [
                'trial_status'    => 'converted',
                'status'          => 'active',
                'next_billing_at' => $next,
            ];
            if ($isFirst) {
                $patch['first_charge_at'] = now();
            }
            if ($isRetry) {
                $patch['payment_failed_at'] = null;
            }
            $sub->update($patch);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('subscription charge failed: ' . $e->getMessage(), ['exception' => $e]);

            return sendError($isRetry ? 'Retry failed — charge could not be collected.' : 'Could not collect charge.');
        }

        $this->refreshVenue($sub);

        return sendSuccess($isRetry ? 'Charge retried & collected' : 'Charged', [
            'subscription' => $this->mapSubscription($sub->fresh()),
        ]);
    }

    protected function recordSubscriptionPayment(ClassSubscription $sub, ClassProgram $program, bool $isFirstCharge): void
    {
        $fullGross = (float) ($sub->monthly_amount ?? 0);
        $fee = max(0, (float) ($sub->joining_fee ?? $program->joining_fee ?? 0));
        if ($isFirstCharge && $fee > 0) {
            $fullGross = round($fullGross + $fee, 2);
        }
        $feeRate = ((float) ($sub->monthly_amount ?? 0)) > 0
            ? ((float) ($sub->platform_fee ?? 0)) / (float) $sub->monthly_amount
            : ((float) ($program->commission_percentage ?: self::PLATFORM_COMMISSION) / 100);
        $platformFee = round($fullGross * $feeRate, 2);
        $providerShare = round($fullGross - $platformFee, 2);
        $currency = $program->currency ?: 'AED';

        $recipient = '@provider';
        if ($program->mapped_owner_user_id) {
            $u = User::query()->find($program->mapped_owner_user_id);
            if ($u) {
                $recipient = '@' . preg_replace('/\s+/', '', strtolower($u->name ?: 'provider'));
            }
        }

        $desc = $isFirstCharge ? 'First membership charge' : 'Membership subscription charge';
        $now = now();

        $userId = $program->mapped_owner_user_id
            ?: (auth()->id() ?: 0);

        Payment::query()->create([
            'booking_id'      => null,
            'subscription_id' => $sub->id,
            'source_type'     => 'subscription',
            'type'            => 'subscription_commission',
            'method'          => 'online',
            'amount'          => $platformFee,
            'currency'        => $currency,
            'recipient'       => '@bookboll',
            'status'          => 'paid',
            'paid_at'         => $now,
            'date'            => $now->toDateString(),
            'notes'           => $desc,
            'payment_id'      => 'sub-c-' . Str::uuid(),
            'user_id'         => $userId,
        ]);

        Payment::query()->create([
            'booking_id'      => null,
            'subscription_id' => $sub->id,
            'source_type'     => 'subscription',
            'type'            => 'subscription_earning',
            'method'          => 'online',
            'amount'          => $providerShare,
            'currency'        => $currency,
            'recipient'       => $recipient,
            'status'          => 'paid',
            'paid_at'         => $now,
            'date'            => $now->toDateString(),
            'notes'           => $desc,
            'payment_id'      => 'sub-e-' . Str::uuid(),
            'user_id'         => $userId,
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    protected function windDownSweep(): void
    {
        $today = Carbon::today()->toDateString();
        $programs = ClassProgram::query()->get()->keyBy('id');

        $due = ClassSubscription::query()
            ->where('status', '!=', 'paused')
            ->where(function ($q) {
                $q->where('status', 'active')->orWhere('trial_status', 'in_trial');
            })
            ->whereNotNull('next_billing_at')
            ->where('next_billing_at', '<', $today)
            ->get();

        $ids = [];
        foreach ($due as $s) {
            $prog = $programs->get($s->program_id);
            if (! $prog) {
                continue;
            }
            $live = ($prog->status === 'active') && ($this->rowState($prog) === 'active');
            if ($live) {
                continue;
            }
            $ids[] = $s->id;
        }

        if (! $ids) {
            return;
        }

        ClassSubscription::query()->whereIn('id', $ids)->update(['status' => 'cancelled']);
        ClassSession::query()
            ->whereIn('subscription_id', $ids)
            ->where('status', 'scheduled')
            ->update(['status' => 'cancelled']);
    }

    protected function planFor(ClassProgram $program): array
    {
        $interval = $program->billing_interval ?: 'monthly';
        $mode = $this->offerModeFor($program);
        $list = (float) ($program->monthly_price ?? 0);
        $discountMonths = (int) ($program->discount_months ?? 0);
        $price = $mode === 'discount'
            ? $this->discountedPrice($list, $interval, $discountMonths)
            : $list;

        return [
            'interval'        => $interval,
            'mode'            => $mode,
            'price'           => $price,
            'trial_days'      => (int) ($program->free_trial_days ?? 0),
            'trial_sessions'  => (int) ($program->free_trial_sessions ?? 0),
            'discount_months' => $discountMonths,
        ];
    }

    protected function offerModeFor(ClassProgram $program): string
    {
        $ongoing = ($program->program_type ?? 'ongoing') !== 'fixed_duration';
        $interval = $program->billing_interval ?: 'monthly';

        return ($ongoing && $this->isLongerThanMonthly($interval)) ? 'discount' : 'trial';
    }

    protected function isLongerThanMonthly(string $interval): bool
    {
        return (self::INTERVAL_MONTHS[$interval] ?? 1) > 1;
    }

    protected function chargesUpfront(string $interval): bool
    {
        return $interval === 'one_time' || $this->isLongerThanMonthly($interval);
    }

    protected function discountedPrice(float $price, string $interval, int $discountMonths): float
    {
        $m = self::INTERVAL_MONTHS[$interval] ?? 12;
        $d = min($discountMonths, $m);

        return max(0, round($price * (1 - $d / $m), 2));
    }

    protected function computeTrialBilling(ClassProgram $program, array $plan, string $startedAt, bool $isComped): array
    {
        $isFixed = ($program->program_type ?? '') === 'fixed_duration';
        $oneTime = $plan['interval'] === 'one_time';
        $upfront = $this->chargesUpfront($plan['interval']) || $plan['mode'] === 'discount' || $oneTime;
        $hasTrial = ! $isComped && ! $upfront && ((int) $plan['trial_days'] > 0 || (int) $plan['trial_sessions'] > 0);

        if ($isComped) {
            return [
                'trial_status'    => 'active',
                'trial_ends_at'   => $startedAt,
                'next_billing_at' => null,
            ];
        }

        if ($hasTrial) {
            if ((int) $plan['trial_sessions'] > 0) {
                $next = $this->nthSessionDate($startedAt, $program->day_of_week_mask ?? [], 2)
                    ?: $this->addIntervalToDate($startedAt, 'monthly');
            } else {
                $next = Carbon::parse($startedAt)->addDays((int) $plan['trial_days'])->toDateString();
            }

            return [
                'trial_status'    => 'in_trial',
                'trial_ends_at'   => $next,
                'next_billing_at' => $next,
            ];
        }

        if ($oneTime) {
            $next = null;
        } elseif ($isFixed) {
            $nb = $this->addIntervalToDate($startedAt, 'monthly');
            $end = $program->end_date ? Carbon::parse($program->end_date)->toDateString() : null;
            $next = ($end && $nb >= $end) ? null : $nb;
        } else {
            $next = $this->addIntervalToDate($startedAt, $plan['interval']);
        }

        return [
            'trial_status'    => 'active',
            'trial_ends_at'   => $startedAt,
            'next_billing_at' => $next,
        ];
    }

    protected function nextBillingAfterToday(?ClassProgram $program, ClassSubscription $sub, string $today): ?string
    {
        $interval = $program?->billing_interval ?: ($sub->billing_interval ?: 'monthly');
        if ($interval === 'one_time') {
            return null;
        }
        if (($program?->program_type ?? '') === 'fixed_duration') {
            $nb = $this->addIntervalToDate($today, 'monthly');
            $end = $program?->end_date ? Carbon::parse($program->end_date)->toDateString() : null;

            return ($end && $nb >= $end) ? null : $nb;
        }

        return $this->addIntervalToDate($today, $interval);
    }

    protected function addIntervalToDate(string $dateStr, string $interval): string
    {
        $d = Carbon::parse($dateStr);
        if ($interval === 'weekly') {
            $d->addDays(7);
        } elseif ($interval === 'biweekly') {
            $d->addDays(14);
        } elseif ($interval === 'quarterly') {
            $d->addMonths(3);
        } elseif ($interval === 'yearly') {
            $d->addYear();
        } else {
            $d->addMonth();
        }

        return $d->toDateString();
    }

    protected function nthSessionDate(string $startISO, array $dayMask, int $n): ?string
    {
        $set = array_fill_keys(array_map('intval', $dayMask), true);
        if (! $set || $n < 1) {
            return null;
        }
        $cur = Carbon::parse($startISO);
        $count = 0;
        for ($i = 0; $i < 400; $i++) {
            if (isset($set[(int) $cur->dayOfWeek])) {
                if (++$count === $n) {
                    return $cur->toDateString();
                }
            }
            $cur->addDay();
        }

        return null;
    }

    protected function generateFirstMonthSessions(ClassSubscription $sub, ClassProgram $program, string $startedAt): void
    {
        $mask = $program->day_of_week_mask ?? [];
        if (! is_array($mask) || ! $mask) {
            return;
        }
        $set = array_fill_keys(array_map('intval', $mask), true);
        $start = Carbon::parse($startedAt)->startOfDay();
        $end = $start->copy()->addMonth();
        $time = substr((string) ($program->session_time ?: '18:00'), 0, 5);
        $duration = (int) ($program->session_duration_minutes ?: 60);

        $cur = $start->copy();
        while ($cur < $end) {
            if (isset($set[(int) $cur->dayOfWeek])) {
                ClassSession::query()->create([
                    'id'               => (string) Str::uuid(),
                    'subscription_id'  => $sub->id,
                    'program_id'       => $program->id,
                    'listing_id'       => null,
                    'session_date'     => $cur->toDateString(),
                    'session_time'     => $time,
                    'duration_minutes' => $duration,
                    'status'           => 'scheduled',
                ]);
            }
            $cur->addDay();
        }
    }

    protected function rowState($row): string
    {
        $eligibility = $row->eligibility_status ?? 'eligible';
        $approval = $row->approval_status ?? 'approved';
        $hasSnapshot = ! empty($row->previous_snapshot);

        if ($eligibility === 'paused' && $approval === 'pending' && $hasSnapshot) {
            return 'resubmitted';
        }
        if ($eligibility === 'paused') {
            return 'paused';
        }
        if ($approval === 'pending') {
            return 'pending_new';
        }

        return 'active';
    }

    protected function paymentsFor(string $subscriptionId): array
    {
        return Payment::query()
            ->where('subscription_id', $subscriptionId)
            ->orderBy('created_at')
            ->get()
            ->map(fn (Payment $p) => [
                'id'              => $p->id,
                'subscription_id' => $p->subscription_id,
                'source_type'     => $p->source_type,
                'payment_type'    => $p->type,
                'type'            => $p->type,
                'method'          => $p->method,
                'amount'          => (float) $p->amount,
                'currency'        => $p->currency,
                'recipient'       => $p->recipient,
                'status'          => $p->status,
                'paid_at'         => optional($p->paid_at)?->toISOString(),
                'created_at'      => optional($p->created_at)?->toISOString(),
                'notes'           => $p->notes,
            ])
            ->values()
            ->all();
    }

    protected function mapProgram(ClassProgram $p): array
    {
        $ownerId = $p->mapped_owner_user_id
            ? (string) $p->mapped_owner_user_id
            : ($p->owner_user_id ? (string) $p->owner_user_id : null);

        return [
            'id'                       => $p->id,
            'name'                     => $p->name,
            'description'              => $p->description,
            'sport_category'           => $p->sport_category,
            'coach_name'               => $p->coach_name,
            'monthly_price'            => (float) $p->monthly_price,
            'currency'                 => $p->currency,
            'sessions_per_week'        => $p->sessions_per_week,
            'session_duration_minutes' => $p->session_duration_minutes,
            'day_of_week_mask'         => $p->day_of_week_mask ?? [],
            'session_time'             => $p->session_time,
            'capacity'                 => $p->capacity,
            'commission_percentage'    => (float) $p->commission_percentage,
            'status'                   => $p->status,
            'photo_url'                => $p->photo_url,
            'images'                   => $p->images ?? [],
            'location_id'              => $p->location_id,
            'venue_id'                 => $p->venue_id,
            'owner_user_id'            => $ownerId,
            'owner_user_id_uuid'       => $p->owner_user_id,
            'mapped_owner_user_id'     => $p->mapped_owner_user_id,
            'listing_id'               => $p->listing_id,
            'listing_ids'              => $p->listing_ids ?? [],
            'billing_interval'         => $p->billing_interval,
            'program_type'             => $p->program_type,
            'duration_weeks'           => $p->duration_weeks,
            'start_date'               => optional($p->start_date)?->toDateString(),
            'end_date'                 => optional($p->end_date)?->toDateString(),
            'bonus_months'             => $p->bonus_months,
            'free_trial_days'          => $p->free_trial_days,
            'free_trial_enabled'       => (bool) $p->free_trial_enabled,
            'free_trial_sessions'      => $p->free_trial_sessions,
            'discount_months'          => $p->discount_months,
            'joining_fee'              => (float) $p->joining_fee,
            'is_draft'                 => (bool) $p->is_draft,
            'show_on_marketplace'      => (bool) $p->show_on_marketplace,
            'show_on_provider_site'    => (bool) $p->show_on_provider_site,
            'category'                 => $p->category,
            'audience'                 => $p->audience,
            'gender_policy'            => $p->gender_policy,
            'signup_form'              => $p->signup_form ?? [],
            'color'                    => $p->color,
            'sort_order'               => $p->sort_order,
            'approval_status'          => $p->approval_status,
            'eligibility_status'       => $p->eligibility_status,
            'admin_note'               => $p->admin_note,
            'previous_snapshot'        => $p->previous_snapshot,
            'pending_content'          => $p->pending_content,
            'created_at'               => optional($p->created_at)?->toISOString(),
        ];
    }

    protected function mapSubscription(ClassSubscription $s, ?int $sessionCount = null): array
    {
        return [
            'id'                        => $s->id,
            'program_id'                => $s->program_id,
            'customer_id'               => $s->customer_id,
            'customer_name'             => $s->customer_name,
            'customer_email'            => $s->customer_email,
            'started_at'                => optional($s->started_at)?->toDateString(),
            'next_billing_at'           => optional($s->next_billing_at)?->toDateString(),
            'trial_ends_at'             => optional($s->trial_ends_at)?->toDateString(),
            'status'                    => $s->status,
            'trial_status'              => $s->trial_status,
            'billing_interval'          => $s->billing_interval,
            'monthly_amount'            => (float) $s->monthly_amount,
            'provider_payout'           => (float) $s->provider_payout,
            'platform_fee'              => (float) $s->platform_fee,
            'joining_fee'               => (float) ($s->joining_fee ?? 0),
            'card_on_file_id'           => $s->card_on_file_id,
            'first_charge_at'           => optional($s->first_charge_at)?->toISOString(),
            'cancelled_during_trial_at' => optional($s->cancelled_during_trial_at)?->toISOString(),
            'cancelled_at'              => optional($s->cancelled_at)?->toISOString(),
            'paused_until'              => optional($s->paused_until)?->toISOString(),
            'payment_failed_at'         => optional($s->payment_failed_at)?->toISOString(),
            'country_id'                => $s->country_id,
            'wallet_id'                 => $s->wallet_id,
            'form_answers'              => $s->form_answers ?? [],
            'is_comp'                   => (bool) $s->is_comp,
            'comp_reason'               => $s->comp_reason,
            'cancel_reason'             => $s->cancel_reason,
            'session_count'             => $sessionCount,
            'created_at'                => optional($s->created_at)?->toISOString(),
            'updated_at'                => optional($s->updated_at)?->toISOString(),
        ];
    }

    protected function buildProviders($programs): array
    {
        $out = [];
        $seen = [];
        foreach ($programs as $p) {
            $key = $p->mapped_owner_user_id
                ? ('L' . $p->mapped_owner_user_id)
                : ('U' . ($p->owner_user_id ?: ''));
            if ($key === 'U' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if ($p->mapped_owner_user_id) {
                $u = User::query()->find($p->mapped_owner_user_id);
                $out[] = [
                    'id'            => (string) $p->mapped_owner_user_id,
                    'full_name'     => $u?->name,
                    'business_name' => $u?->name,
                    'role'          => 'provider_admin',
                ];
            } elseif ($p->owner_user_id) {
                $out[] = [
                    'id'            => (string) $p->owner_user_id,
                    'full_name'     => 'Provider',
                    'business_name' => 'Provider',
                    'role'          => 'provider_admin',
                ];
            }
        }

        return $out;
    }

    protected function refreshVenue(ClassSubscription $sub): void
    {
        $program = ClassProgram::query()->find($sub->program_id);
        if ($program?->venue_id) {
            notifyVenueStaffRefresh((int) $program->venue_id, [
                'event'           => 'subscription_updated',
                'subscription_id' => $sub->id,
                'bell'            => true,
            ]);
        }
    }
}
