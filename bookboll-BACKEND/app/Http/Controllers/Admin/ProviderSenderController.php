<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\CustomTypeService;
use App\Services\EmailSenderService;
use App\Services\ProviderBlogService;
use Illuminate\Http\Request;

/**
 * Two small provider-scoped surfaces: the email sending identity (Marketing →
 * Email sender) and the shared custom-type catalogue used by Listings and
 * Memberships.
 */
class ProviderSenderController extends Controller
{
    public function __construct(
        protected EmailSenderService $email,
        protected CustomTypeService $types,
        protected ProviderBlogService $blog,
    ) {}

    /* ── A provider's own site blog (My Website → Blog) ──────────────────── */

    public function blogIndex(Request $request)
    {
        return $this->blog->index($request);
    }

    public function blogCount(Request $request)
    {
        return $this->blog->publishedCount($request);
    }

    public function blogStore(Request $request)
    {
        return $this->blog->store($request);
    }

    public function blogUpdate(Request $request, $id)
    {
        return $this->blog->update($request, $id);
    }

    public function blogDestroy(Request $request, $id)
    {
        return $this->blog->destroy($request, $id);
    }

    public function showEmail(Request $request)
    {
        return $this->email->show($request);
    }

    public function saveEmail(Request $request)
    {
        return $this->email->update($request);
    }

    /** Really opens the SMTP connection and delivers — see EmailSenderService. */
    public function testEmail(Request $request)
    {
        return $this->email->sendTest($request);
    }

    public function customTypes(Request $request)
    {
        return $this->types->index($request);
    }

    public function suggestCustomType(Request $request)
    {
        return $this->types->store($request);
    }
}
