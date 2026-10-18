<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Storage;

/**
 * An event photo must be a file this platform actually stored.
 *
 * The hosting form uploads through POST /uploads/image and then submits the URL
 * it got back, so the only legitimate value is a path on our own public disk
 * under `uploads/`. Rejecting anything else stops a host from publishing an
 * event illustrated with a hotlinked image from another site — which we cannot
 * moderate, cannot keep available and did not consent to serve — and confirms
 * the upload really succeeded rather than trusting a URL the browser typed.
 *
 * Note on ownership: uploads are not currently recorded against the user who
 * made them (UploadController stores the file and returns a URL, with no row),
 * so this verifies the file is ours and exists, not which account uploaded it.
 * Per-user ownership needs an uploads/media table; see readmeAlamgir.md.
 */
class UploadedEventImage implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $path = self::relativePath($value);

        if ($path === null) {
            $fail('Upload each photo through this form — an external image link cannot be used.');

            return;
        }

        if (! Storage::disk('public')->exists($path)) {
            $fail('That photo did not finish uploading. Please add it again.');
        }
    }

    /**
     * The disk path behind one of our upload URLs, or null when the value is
     * not one. Accepts the absolute URL the uploader returns and the bare
     * relative path, since both appear in saved drafts.
     */
    public static function relativePath(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 2048) {
            return null;
        }

        // Strip our own disk URL, so a full https URL and a stored relative
        // path both reduce to the same thing.
        $root = rtrim((string) Storage::disk('public')->url(''), '/');
        if ($root !== '' && str_starts_with($value, $root)) {
            $value = substr($value, strlen($root));
        } elseif (preg_match('#^https?://#i', $value)) {
            // Some other host entirely.
            return null;
        }

        $path = ltrim(parse_url($value, PHP_URL_PATH) ?: '', '/');

        // `/storage/...` is how the public disk is served when APP_URL differs
        // from the request host (a proxy, a staging domain).
        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        if (! str_starts_with($path, 'uploads/') || str_contains($path, '..')) {
            return null;
        }

        return $path;
    }
}
