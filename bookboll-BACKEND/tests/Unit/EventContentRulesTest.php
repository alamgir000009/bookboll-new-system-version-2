<?php

use App\Rules\NotBlank;
use App\Rules\UploadedEventImage;

/**
 * A hosted event has to carry real content before it reserves a court or takes
 * the host's money, and its photos have to be files we actually stored.
 */
it('treats whitespace-only text as blank', function (string $value) {
    expect(NotBlank::isBlank($value))->toBeTrue();
})->with([
    '',
    ' ',
    "\t\n  ",
    "\u{00A0}\u{00A0}",        // non-breaking spaces
    "\u{200B}",                 // zero-width space
    "\u{FEFF} \u{200D}",
]);

it('accepts text with any real character in it', function (string $value) {
    expect(NotBlank::isBlank($value))->toBeFalse();
})->with(['a', ' casual padel ', "line\nline", '.']);

/* ---------------------------------------------------------------------------
 * Photo ownership. These URLs are public — they appear in pages, get shared and
 * sit in browser history — so "the file is on our disk" was never enough to say
 * the person submitting it may use it.
 * ------------------------------------------------------------------------- */

use App\Rules\UploadedEventImage as Img;

it('lets an account use the photo it uploaded', function () {
    expect(Img::ownershipVerdict(7, 7, false))->toBe(Img::OWNERSHIP_OK)
        ->and(Img::ownershipVerdict(7, 7, true))->toBe(Img::OWNERSHIP_OK);
});

it("refuses another account's photo, strict or not", function () {
    expect(Img::ownershipVerdict(7, 8, false))->toBe(Img::OWNERSHIP_REFUSED)
        ->and(Img::ownershipVerdict(7, 8, true))->toBe(Img::OWNERSHIP_REFUSED);
});

it('accepts a file uploaded before tracking existed, and flags it as untracked', function () {
    // No owner can be recovered for these, so refusing them would break every
    // event published before this release.
    expect(Img::ownershipVerdict(null, 7, false))->toBe(Img::OWNERSHIP_UNTRACKED);
});

it('refuses an untracked file once strict ownership is switched on', function () {
    expect(Img::ownershipVerdict(null, 7, true))->toBe(Img::OWNERSHIP_REFUSED);
});

it('refuses everything when nobody is signed in', function (array $case) {
    expect(Img::ownershipVerdict($case[0], null, $case[1]))->toBe(Img::OWNERSHIP_REFUSED);
})->with([
    [[7, false]],
    [[7, true]],
    [[null, false]],   // untracked file + no actor is still no claim
    [[null, true]],
]);
