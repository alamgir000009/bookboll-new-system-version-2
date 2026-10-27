<?php

/**
 * Provider-defined time slots.
 *
 * A listing in `custom` mode sells exactly the slots its provider listed — each
 * with its own start, end and weekdays — instead of the open hours cut into
 * equal pieces. /fetch-time-slots must offer only those, bookings must land on
 * one of them (or a contiguous run), and the older `fixed` mode, which the
 * generator used to ignore, must be honoured by the same path.
 */

use App\Models\Listing;
use App\Models\Schedule;
use App\Models\Venue;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->venue = Venue::create([
        'name' => 'Pitch', 'status' => Venue::ACTIVE,
        'maps_url' => 'https://maps.google.com/?q=0,0',
        'min_advance_booking' => 0, 'max_bookings_per_slot' => 1,
    ]);

    $this->listing = Listing::create([
        'name' => 'Court', 'venue_id' => $this->venue->id, 'status' => 'active',
        'base_price' => 250, 'capacity' => 10, 'slot_duration' => 60,
        'payment_options' => ['cash_on_arrival' => true], 'max_bookings_per_slot' => 1,
    ]);

    // Open 18:00-23:00 on Monday and Tuesday, on both the venue and the listing.
    $this->date = \Carbon\Carbon::parse('next monday');
    foreach (['monday', 'tuesday'] as $day) {
        foreach (['venue_id' => $this->venue->id, 'listing_id' => $this->listing->id] as $key => $id) {
            Schedule::create([$key => $id, 'day' => $day, 'is_open' => true, 'start_time' => '18:00', 'end_time' => '23:00']);
        }
    }
});

/** The slot list for a date, keyed by start time. */
function customSlotsFor(?string $date = null): array
{
    $request = new Illuminate\Http\Request();
    $request->merge(['listing_id' => test()->listing->id, 'date' => $date ?? test()->date->toDateString()]);
    $request->headers->set('from', 'frontend-web');

    $body = json_decode(app(App\Services\ListingService::class)->fetchTimeSlots($request)->getContent(), true);

    return collect($body['data']['timeSlots'] ?? [])->keyBy('start_time')->all();
}

/** validateBooking() is private; the checkout paths all funnel through it. */
function bookingErrors(string $start, string $end, ?string $date = null): array
{
    $service = app(BookingService::class);
    $method  = new ReflectionMethod($service, 'validateBooking');

    return $method->invoke($service, [
        'venue_id' => test()->venue->id, 'listing_id' => test()->listing->id,
        'date' => $date ?? test()->date->toDateString(),
        'start_time' => $start, 'end_time' => $end, 'quantity' => 1,
    ]);
}

it('sells only the custom slots, each with its own length', function () {
    $this->listing->update(['start_time_mode' => 'custom', 'custom_slots' => [
        ['start_time' => '18:30', 'end_time' => '19:30', 'days' => [1]],
        ['start_time' => '20:00', 'end_time' => '21:30', 'days' => [1, 2]],
        ['start_time' => '17:00', 'end_time' => '18:00', 'days' => [1]],   // before opening — never offered
        ['start_time' => '21:45', 'end_time' => '22:45', 'days' => [2]],   // Tuesday only
    ]]);

    $monday = customSlotsFor();

    expect(array_keys($monday))->toBe(['18:30', '20:00'])
        ->and($monday['18:30']['end_time'])->toBe('19:30')
        ->and($monday['18:30']['duration'])->toBe('1 hour')
        ->and($monday['20:00']['end_time'])->toBe('21:30')
        ->and($monday['20:00']['duration'])->toBe('1 hour 30 minutes')
        ->and((float) $monday['20:00']['price'])->toBe(250.0);

    $tuesday = customSlotsFor($this->date->copy()->addDay()->toDateString());
    expect(array_keys($tuesday))->toBe(['20:00', '21:45']);
});

it('ignores the listing weekly schedule — only the venue hours limit custom slots', function () {
    // Venue open Wednesday; the listing's own schedule says nothing about Wednesday.
    Schedule::create(['venue_id' => $this->venue->id, 'day' => 'wednesday', 'is_open' => true, 'start_time' => '10:00', 'end_time' => '14:00']);
    $this->listing->update(['start_time_mode' => 'custom', 'custom_slots' => [
        ['start_time' => '10:30', 'end_time' => '11:30', 'days' => [3]],
        ['start_time' => '15:00', 'end_time' => '16:00', 'days' => [3]],   // venue shut by then
        ['start_time' => '12:00', 'end_time' => '13:00', 'days' => [4]],   // Thursday: venue closed
    ]]);

    $wednesday = $this->date->copy()->addDays(2)->toDateString();
    expect(array_keys(customSlotsFor($wednesday)))->toBe(['10:30'])
        ->and(customSlotsFor($this->date->copy()->addDays(3)->toDateString()))->toBe([])
        ->and(bookingErrors('10:30', '11:30', $wednesday))->not->toHaveKey('start_time')
        ->and(bookingErrors('10:30', '11:30', $wednesday))->not->toHaveKey('date')
        ->and(bookingErrors('15:00', '16:00', $wednesday))->toHaveKey('start_time');
});

it('accepts day names and seconds in stored slots', function () {
    $this->listing->update(['start_time_mode' => 'custom', 'custom_slots' => [
        ['start_time' => '19:00:00', 'end_time' => '20:00:00', 'days' => ['Monday', 'tue']],
    ]]);

    expect(array_keys(customSlotsFor()))->toBe(['19:00'])
        ->and($this->listing->fresh()->customSlots())->toBe([
            ['start_time' => '19:00', 'end_time' => '20:00', 'days' => [1, 2]],
        ]);
});

it('falls back to automatic slots when the custom list is empty', function () {
    $this->listing->update(['start_time_mode' => 'custom', 'custom_slots' => []]);

    expect(array_keys(customSlotsFor()))->toBe(['18:00', '19:00', '20:00', '21:00', '22:00'])
        ->and($this->listing->fresh()->startTimeMode())->toBe('auto');
});

it('honours the fixed start-time subset', function () {
    $this->listing->update(['start_time_mode' => 'fixed', 'fixed_start_times' => ['19:00', '21:00', '03:00']]);

    expect(array_keys(customSlotsFor()))->toBe(['19:00', '21:00']);
});

it('lets a custom slot run past a block that ends at midnight, like an automatic one', function () {
    Schedule::where('listing_id', $this->listing->id)->where('day', 'monday')->update(['end_time' => '00:00']);
    Schedule::where('venue_id', $this->venue->id)->where('day', 'monday')->update(['end_time' => '00:00']);
    $this->listing->update(['start_time_mode' => 'custom', 'custom_slots' => [
        ['start_time' => '23:00', 'end_time' => '01:00', 'days' => [1]],
    ]]);

    $monday = customSlotsFor();
    expect(array_keys($monday))->toBe(['23:00'])
        ->and($monday['23:00']['end_time'])->toBe('01:00')
        ->and($monday['23:00']['duration'])->toBe('2 hours');

    // Tuesday's first hour is taken by that spill.
    $tuesday = customSlotsFor($this->date->copy()->addDay()->toDateString());
    expect($tuesday)->toBe([]);   // 18:00-23:00 open but no custom slot on Tuesday
});

it('only books a custom slot, or a contiguous run of them', function () {
    $this->listing->update(['start_time_mode' => 'custom', 'custom_slots' => [
        ['start_time' => '18:30', 'end_time' => '19:30', 'days' => [1]],
        ['start_time' => '19:30', 'end_time' => '20:30', 'days' => [1]],
        ['start_time' => '21:00', 'end_time' => '22:00', 'days' => [1]],
    ]]);

    expect(bookingErrors('18:30', '19:30'))->not->toHaveKey('start_time')
        ->and(bookingErrors('18:30', '20:30'))->not->toHaveKey('start_time')   // two adjacent slots
        ->and(bookingErrors('18:00', '19:00'))->toHaveKey('start_time')       // inside hours, not a slot
        ->and(bookingErrors('19:30', '22:00'))->toHaveKey('start_time')       // gap in the run
        ->and(bookingErrors('18:30', '19:00'))->toHaveKey('start_time');      // half a slot
});

it('leaves automatic listings to the hours check alone', function () {
    expect(bookingErrors('18:00', '19:00'))->not->toHaveKey('start_time')
        ->and(bookingErrors('18:30', '19:30'))->not->toHaveKey('start_time');
});
