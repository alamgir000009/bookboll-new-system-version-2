<?php

use App\Rules\UploadedEventImage;
use Illuminate\Support\Facades\Storage;

/**
 * An event photo has to be a file this platform actually stored. Needs the
 * booted app, because the legitimate prefix is the public disk's own URL.
 */
it('refuses an image hosted anywhere but our own uploads', function ($value) {
    expect(UploadedEventImage::relativePath($value))->toBeNull();
})->with([
    'https://evil.test/photo.jpg',
    'https://i.imgur.com/abc.png',
    'uploads/../../../etc/passwd',
    'storage/private/secret.jpg',   // on our disk, but not an upload
    'listings/1/photo.jpg',          // not under uploads/
    '',
    '  ',
    null,
    42,
]);

it('reduces our own upload URL and its bare path to the same disk path', function () {
    $root = rtrim((string) Storage::disk('public')->url(''), '/');

    expect(UploadedEventImage::relativePath($root . '/uploads/games/2026/10/a.jpg'))
        ->toBe('uploads/games/2026/10/a.jpg')
        ->and(UploadedEventImage::relativePath('uploads/games/2026/10/a.jpg'))
        ->toBe('uploads/games/2026/10/a.jpg')
        ->and(UploadedEventImage::relativePath('/storage/uploads/games/2026/10/a.jpg'))
        ->toBe('uploads/games/2026/10/a.jpg');
});

it('refuses an absurdly long value', function () {
    expect(UploadedEventImage::relativePath('uploads/games/' . str_repeat('a', 2100)))->toBeNull();
});
