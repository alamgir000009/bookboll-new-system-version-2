<?php

namespace App\Rules;

use App\Models\Upload;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Log;
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
 * Ownership is checked too: these URLs are public, so without it one account
 * could illustrate its event with a photo another account uploaded. A file
 * stored before the `uploads` table existed has no owner on record and no way
 * to recover one, so it is still accepted and its use logged — see
 * config/uploads.php for turning that grace off once the logs go quiet.
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

            return;
        }

        $upload = Upload::forPath($path);
        $actorId = optional(auth()->user())->id;

        switch (self::ownershipVerdict(
            $upload ? (int) $upload->user_id : null,
            $actorId === null ? null : (int) $actorId,
            (bool) config('uploads.strict_ownership')
        )) {
            case self::OWNERSHIP_OK:
                return;

            case self::OWNERSHIP_UNTRACKED:
                // Predates ownership tracking and accepted on sufferance.
                // Logged so it is visible how much legacy content is still in
                // play before strict mode is switched on.
                Log::info('Event photo has no upload owner on record', [
                    'path' => $path, 'user_id' => $actorId,
                ]);

                return;

            default:
                // Deliberately vague: confirming "that belongs to someone else"
                // would tell a prober whose photo they had found.
                $fail('Upload each photo through this form — that image is not available to use.');
        }
    }

    public const OWNERSHIP_OK        = 'ok';
    public const OWNERSHIP_UNTRACKED = 'untracked';
    public const OWNERSHIP_REFUSED   = 'refused';

    /**
     * Whether the account submitting a photo may use it.
     *
     * Split out from the rule so the decision table is testable on its own —
     * the interesting cases are the awkward ones (no owner recorded, nobody
     * signed in), and they are easy to get subtly wrong.
     *
     * @param  int|null  $ownerId  recorded uploader, or null if the file predates tracking
     * @param  int|null  $actorId  the account submitting it, or null if unauthenticated
     * @param  bool      $strict   refuse files with no recorded owner
     */
    public static function ownershipVerdict(?int $ownerId, ?int $actorId, bool $strict): string
    {
        // Nobody signed in has no claim on anything, whatever the file's state.
        // Every route using this rule is authenticated, so this is a backstop.
        if ($actorId === null) {
            return self::OWNERSHIP_REFUSED;
        }

        if ($ownerId === null) {
            return $strict ? self::OWNERSHIP_REFUSED : self::OWNERSHIP_UNTRACKED;
        }

        return $ownerId === $actorId ? self::OWNERSHIP_OK : self::OWNERSHIP_REFUSED;
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
