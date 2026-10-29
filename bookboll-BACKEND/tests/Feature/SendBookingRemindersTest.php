<?php

/**
 * send-booking-reminders
 *
 * The "who is due" decision used to be made in PHP over every user with a
 * booking, loaded at once; it is now made in SQL and walked in chunks. These
 * tests pin the rule itself so the rewrite cannot drift from it: a reminder
 * goes to a customer whose newest eligible booking is at least N days old, who
 * has not been reminded in the last N days, and who accepts marketing email.
 */

use App\Models\Booking;
use App\Models\Preference;
use App\Models\Promocode;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\BookingReminderNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function reminderVenue(): Venue
{
    return Venue::create([
        'name' => 'Pitch', 'status' => Venue::ACTIVE,
        'maps_url' => 'https://maps.google.com/?q=0,0',
        'operator_type' => 'bookboll', 'owner_user_id' => null, 'business_id' => null,
        'currency_override' => 'QAR', 'min_advance_booking' => 0, 'max_bookings_per_slot' => 1,
    ]);
}

/** A customer who accepts marketing email unless told otherwise. */
function reminderCustomer(bool $marketing = true): User
{
    $user = User::factory()->create();

    Preference::create([
        'user_id'       => $user->id,
        'notifications' => ['marketing_emails' => $marketing],
    ]);

    return $user;
}

function bookingOn(User $user, string $date, string $status = BookingStatus::COMPLETED): Booking
{
    return Booking::create([
        'user_id' => $user->id, 'venue_id' => reminderVenue()->id,
        'date' => $date, 'start_time' => '10:00', 'end_time' => '11:00',
        'subtotal' => 100, 'total_price' => 100, 'status' => $status,
    ]);
}

/** A reminder that was already sent, as the database channel would have recorded it. */
function remindedOn(User $user, string $date): void
{
    $user->notifications()->create([
        'id'         => (string) Str::uuid(),
        'type'       => BookingReminderNotification::class,
        'data'       => ['type' => 'booking_reminder'],
        'created_at' => $date.' 09:00:00',
        'updated_at' => $date.' 09:00:00',
    ]);
}

function daysAgo(int $days): string
{
    return now()->subDays($days)->toDateString();
}

it('is queued rather than sent inline', function () {
    expect(new BookingReminderNotification(7))->toBeInstanceOf(ShouldQueue::class);
});

it('reminds a customer whose last eligible booking is at least seven days old', function () {
    Notification::fake();
    $user = reminderCustomer();
    bookingOn($user, daysAgo(30));
    bookingOn($user, daysAgo(7));

    $this->artisan('send-booking-reminders')->assertExitCode(0);

    Notification::assertSentTo($user, BookingReminderNotification::class);
    Notification::assertCount(1);

    $promocode = Promocode::where('is_reminder_promocode', true)->sole();
    expect($promocode->code)->toStartWith('REMINDER')
        ->and($promocode->users()->where('users.id', $user->id)->exists())->toBeTrue();
});

it('leaves a customer alone while any eligible booking is newer than the threshold', function () {
    Notification::fake();
    $recent = reminderCustomer();
    bookingOn($recent, daysAgo(30));
    bookingOn($recent, daysAgo(6));

    $upcoming = reminderCustomer();
    bookingOn($upcoming, daysAgo(30));
    bookingOn($upcoming, now()->addDays(3)->toDateString());

    $this->artisan('send-booking-reminders')->assertExitCode(0);

    Notification::assertNothingSent();
    expect(Promocode::count())->toBe(0);
});

it('ignores bookings that are neither completed, confirmed nor fully paid', function () {
    Notification::fake();
    $user = reminderCustomer();
    bookingOn($user, daysAgo(30), BookingStatus::PENDING);

    $this->artisan('send-booking-reminders')->assertExitCode(0);

    Notification::assertNothingSent();
});

it('does not remind again until seven days have passed since the last reminder', function () {
    Notification::fake();
    $recentlyReminded = reminderCustomer();
    bookingOn($recentlyReminded, daysAgo(30));
    remindedOn($recentlyReminded, daysAgo(6));

    $dueAgain = reminderCustomer();
    bookingOn($dueAgain, daysAgo(30));
    remindedOn($dueAgain, daysAgo(7));

    $this->artisan('send-booking-reminders')->assertExitCode(0);

    Notification::assertNotSentTo($recentlyReminded, BookingReminderNotification::class);
    Notification::assertSentTo($dueAgain, BookingReminderNotification::class);
});

it('respects a customer who turned marketing email off', function () {
    Notification::fake();
    $optedOut = reminderCustomer(marketing: false);
    bookingOn($optedOut, daysAgo(30));

    $noPreferences = User::factory()->create();
    bookingOn($noPreferences, daysAgo(30));

    $this->artisan('send-booking-reminders')->assertExitCode(0);

    Notification::assertNothingSent();
    expect(Promocode::count())->toBe(0);
});

it('walks due customers in chunks without losing any', function () {
    Notification::fake();
    $users = collect(range(1, 5))->map(function () {
        $user = reminderCustomer();
        bookingOn($user, daysAgo(20));

        return $user;
    });

    $this->artisan('send-booking-reminders --chunk=2')->assertExitCode(0);

    Notification::assertCount(5);
    $users->each(fn (User $u) => Notification::assertSentTo($u, BookingReminderNotification::class));
    expect(Promocode::where('is_reminder_promocode', true)->count())->toBe(5);
});

it('records the database notification and renders the email once the queued job runs', function () {
    // No Notification::fake() here: the sync queue serializes the job, restores
    // the promocode through SerializesModels and renders both channels.
    $user = reminderCustomer();
    bookingOn($user, daysAgo(10));

    $this->artisan('send-booking-reminders')->assertExitCode(0);

    $row = $user->notifications()->where('type', BookingReminderNotification::class)->sole();
    $code = Promocode::where('is_reminder_promocode', true)->sole()->code;
    expect($row->data['promocode']['code'])->toBe($code);

    $messages = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();
    expect($messages)->toHaveCount(1)
        ->and($messages->first()->getOriginalMessage()->getTo()[0]->getAddress())->toBe($user->email)
        ->and($messages->first()->getOriginalMessage()->getHtmlBody())->toContain($code);
});
