<?php

namespace App\Services;

use App\Models\CustomType;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Provider-suggested venue / programme types.
 *
 * A provider who cannot find their sport in the list types their own; it is stored
 * `pending` and only joins the shared catalogue once a listing or programme using it
 * is approved — promotion happens inside the approval flow (ApprovalsDashboardService),
 * never from the browser, so a provider cannot publish a type by asking nicely.
 */
class CustomTypeService
{
    /** The approved catalogue — everyone sees the same list. */
    public function index(Request $request)
    {
        $rows = CustomType::query()
            ->where('status', CustomType::STATUS_APPROVED)
            ->orderBy('name')
            ->get(['domain', 'name', 'category']);

        return sendSuccess('Custom types', [
            'types' => $rows->map(fn ($t) => [
                'domain'   => $t->domain,
                'name'     => $t->name,
                'category' => $t->category,
            ])->values(),
        ]);
    }

    /**
     * Record a provider-suggested type as pending, deduped against an existing
     * pending row for the same domain + category + name (case-insensitively).
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'domain'   => 'required|string|in:venue,program',
            'name'     => 'required|string|max:120',
            'category' => 'required|string|max:120',
        ]);

        $name = trim($data['name']);
        if ($name === '') {
            return sendError('A type name is required.', [], 422);
        }

        $existing = CustomType::query()
            ->where('domain', $data['domain'])
            ->where('category', $data['category'])
            ->where('status', CustomType::STATUS_PENDING)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();

        if (! $existing) {
            CustomType::create([
                // custom_types.uuid is NOT NULL with no default, and nothing else in
                // the app inserts here (approval only promotes existing rows).
                'uuid'       => (string) Str::uuid(),
                'domain'     => $data['domain'],
                'name'       => $name,
                'category'   => $data['category'],
                'status'     => CustomType::STATUS_PENDING,
                'created_by' => $request->user()?->id,
            ]);
        }

        // The cleaned name is what the listing / programme stores.
        return sendSuccess('Type suggested', ['name' => $name]);
    }
}
