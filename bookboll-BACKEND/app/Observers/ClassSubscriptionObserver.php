<?php

namespace App\Observers;

use App\Models\ClassSubscription;
use App\Models\User;
use App\Models\Venue;
use App\Support\AdminAlerts;

/**
 * A membership becomes real when it leaves 'incomplete' (an unpaid Stripe
 * checkout), so that is when the provider is told — not when the row appears.
 */
class ClassSubscriptionObserver
{
    public function created(ClassSubscription $sub): void
    {
        if ($sub->status !== 'incomplete') {
            $this->announce($sub);
        }
    }

    public function updated(ClassSubscription $sub): void
    {
        if ($sub->wasChanged('status') && $sub->getOriginal('status') === 'incomplete' && $sub->status !== 'incomplete') {
            $this->announce($sub);
        }
    }

    protected function announce(ClassSubscription $sub): void
    {
        $program = $sub->program;

        AdminAlerts::toUser(
            $this->ownerId($program),
            'member',
            'ok',
            'New member — '.($sub->customer_name ?: ($sub->customer_email ?: 'a customer')),
            $program?->name ?: 'Membership',
            '/subscriptions'
        );
    }

    protected function ownerId($program): ?int
    {
        if (! $program) {
            return null;
        }
        if ($program->mapped_owner_user_id) {
            return (int) $program->mapped_owner_user_id;
        }
        if ($program->venue_id) {
            return (int) Venue::where('id', $program->venue_id)->value('owner_user_id') ?: null;
        }

        return $program->owner_user_id
            ? (int) User::where('uuid', $program->owner_user_id)->value('id') ?: null
            : null;
    }
}
