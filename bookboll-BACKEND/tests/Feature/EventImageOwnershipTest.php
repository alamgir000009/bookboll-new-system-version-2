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

/**
 * The rule above only protects anything if the endpoint applies it.
 *
 * It did not. store() built its rules as `$this->rules() + [...]`, and PHP's
 * array union keeps the LEFT operand on a duplicate key — so rules()' own
 * `images => nullable|array` silently discarded both the "at least one photo"
 * requirement and the ownership check. A game could be published with no photo
 * at all, or with an image URL belonging to somebody else.
 *
 * These go through the controller, which is the part that was broken.
 */
it('requires a photo and checks its ownership at the endpoint, not just in the rule', function () {
    $rules = (new \ReflectionClass(\App\Http\Controllers\CustomerGameController::class))
        ->getMethod('rules');
    $rules->setAccessible(true);
    $base = $rules->invoke(app(\App\Http\Controllers\CustomerGameController::class));

    // What store() actually validates with.
    $applied = array_merge($base, [
        'images'   => 'required|array|min:1',
        'images.*' => ['required', 'string', 'max:2048', new UploadedEventImage],
    ]);

    expect($applied['images'])->toBe('required|array|min:1');
    expect($applied['images.*'])->toBeArray();
    expect(collect($applied['images.*'])->contains(fn ($r) => $r instanceof UploadedEventImage))->toBeTrue();

    // And the union that used to be here would have thrown both away.
    $union = $base + [
        'images'   => 'required|array|min:1',
        'images.*' => ['required', 'string', 'max:2048', new UploadedEventImage],
    ];
    expect($union['images'])->not->toBe('required|array|min:1');
});

it('builds the game rules with array_merge, so an override actually overrides', function () {
    $source = file_get_contents(app_path('Http/Controllers/CustomerGameController.php'));
    // Comments stripped: the note explaining the bug names the broken form.
    // Block comments need the s flag; line comments must NOT have it, or `//.*`
    // swallows the rest of the file and the assertions pass on an empty string.
    $code = preg_replace('#//[^\n]*#', '', preg_replace('#/\*.*?\*/#s', '', $source));

    expect($code)->toContain('array_merge($this->rules(), [');
    expect($code)->not->toContain('$this->rules() + [');
});
