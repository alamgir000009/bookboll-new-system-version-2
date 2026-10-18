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
