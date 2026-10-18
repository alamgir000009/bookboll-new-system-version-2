<?php

namespace App\Http\Controllers;

use App\Models\Upload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Image uploads for every dashboard and storefront form — banners, blog covers,
 * listing photos, avatars, community art, payout proof.
 *
 * These used to go to imgbb from the browser, which meant the API key shipped in the
 * bundle, the files lived on a third party we cannot delete from or back up, and a
 * signed-out visitor could upload with a key lifted from the page source. They are
 * now stored on our own `public` disk and served from our own domain.
 */
class UploadController extends Controller
{
    /** Formats browsers actually produce, and that we are willing to serve back. */
    private const ALLOWED = ['jpeg', 'jpg', 'png', 'gif', 'webp'];

    /** The client already compresses to ~1 MB; this is the ceiling, not the target. */
    private const MAX_KB = 8192;

    public function image(Request $request)
    {
        $request->validate([
            'image'  => 'required|image|mimes:'.implode(',', self::ALLOWED).'|max:'.self::MAX_KB,
            // A caller may group its uploads (e.g. "banners"), but only from a fixed
            // vocabulary — a free-form path from the browser is a directory traversal.
            'folder' => 'nullable|string|max:32',
        ]);

        $folder = $this->safeFolder($request->input('folder'));
        $file   = $request->file('image');

        // Dated subfolders keep any single directory from growing without bound, which
        // matters on filesystems that slow down with very large directories.
        $path = $file->storeAs(
            $folder.'/'.now()->format('Y/m'),
            Str::uuid().'.'.strtolower($file->getClientOriginalExtension() ?: 'jpg'),
            'public'
        );

        if (! $path) {
            return sendError('Could not store the image. Please try again.', [], 500);
        }

        // Record who uploaded it. Without this the server can tell that a photo
        // submitted later is on our disk, but not whose it is — so one account
        // could illustrate its content with another's image, a deleted account
        // would leave its files behind, and nothing could find the files that
        // no longer belong to anything.
        Upload::create([
            'user_id'       => $request->user()->id,
            'disk'          => 'public',
            'path'          => $path,
            'folder'        => $folder,
            'mime'          => $file->getClientMimeType(),
            'size_bytes'    => $file->getSize(),
            // Only for support ("which file did I send?") — never used to build
            // a path, so a hostile filename cannot escape the upload directory.
            'original_name' => mb_substr((string) $file->getClientOriginalName(), 0, 255),
        ]);

        return sendSuccess('Image uploaded', [
            'url'  => Storage::disk('public')->url($path),
            'path' => $path,
        ]);
    }

    /** An allow-list, so `folder` can never climb out of the uploads directory. */
    private function safeFolder(?string $folder): string
    {
        $clean = strtolower(preg_replace('/[^a-z0-9_-]/i', '', (string) $folder));

        return in_array($clean, [
            'banners', 'blog', 'listings', 'venues', 'programs',
            'avatars', 'communities', 'games', 'brand', 'wallet', 'business',
        ], true) ? 'uploads/'.$clean : 'uploads/misc';
    }
}
