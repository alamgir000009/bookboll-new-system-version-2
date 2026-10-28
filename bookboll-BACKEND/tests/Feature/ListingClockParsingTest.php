<?php

/**
 * How a listing reads a clock string.
 *
 * A custom slot configured as "5 PM – 6 PM" produced bookings at 05:00, because
 * the only two forms the payload could carry — '17:00' and '05:00' — are both
 * valid 24-hour times and nothing could tell which one the picker meant. The
 * meridiem form is now accepted precisely because it cannot be misread.
 *
 * Note what is NOT fixed here, and cannot be: a bare '05:00' meaning five in the
 * afternoon is indistinguishable from five in the morning. Only the sender can
 * resolve that, by sending '17:00' or '5:00 PM'.
 */

use App\Models\Listing;

it('reads a 24-hour clock', function (string $input, ?string $expected) {
    expect(Listing::normalizeClock($input))->toBe($expected);
})->with([
    ['17:00', '17:00'],
    ['17:00:00', '17:00'],
    ['5:00', '05:00'],
    ['05:00', '05:00'],
    ['00:00', '00:00'],
    ['24:00', '00:00'],
    ['23:59', '23:59'],
]);

it('reads a 12-hour clock, which used to be refused outright', function (string $input, ?string $expected) {
    expect(Listing::normalizeClock($input))->toBe($expected);
})->with([
    ['5:00 PM', '17:00'],
    ['05:00 PM', '17:00'],
    ['5:00pm', '17:00'],
    ['5:00 p.m.', '17:00'],
    ['5:00 AM', '05:00'],
    ['11:30 PM', '23:30'],
    // The two everyone gets wrong.
    ['12:00 AM', '00:00'],
    ['12:00 PM', '12:00'],
    ['12:30 AM', '00:30'],
]);

it('refuses a time that cannot mean one thing', function (string $input) {
    expect(Listing::normalizeClock($input))->toBeNull();
})->with([
    // A 12-hour clock has no hour 0 or 13 — guessing is how 5 pm became 5 am.
    ['0:30 am'],
    ['13:00 pm'],
    ['25:00'],
    ['12:60'],
    ['tea time'],
    [''],
]);

it('builds custom slots from a 12-hour payload', function () {
    $listing = new Listing([
        'custom_slots' => [
            ['start_time' => '5:00 PM', 'end_time' => '6:00 PM', 'days' => [1]],
        ],
    ]);

    expect($listing->customSlots())->toBe([
        ['start_time' => '17:00', 'end_time' => '18:00', 'days' => [1]],
    ]);
});

it('still stores a bare morning hour as the morning', function () {
    // The case this cannot rescue: '05:00' is five in the morning, whatever the
    // picker was showing. A UI sending this for 5 PM is the bug, not the parser.
    $listing = new Listing([
        'custom_slots' => [
            ['start_time' => '05:00', 'end_time' => '06:00', 'days' => [1]],
        ],
    ]);

    expect($listing->customSlots()[0]['start_time'])->toBe('05:00');
});
