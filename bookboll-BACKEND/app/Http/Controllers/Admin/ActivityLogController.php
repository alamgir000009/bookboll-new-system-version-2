<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Spatie\Activitylog\Models\Activity;

class ActivityLogController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $filters = [
            'q' => trim((string) $request->input('q', '')),
            'log_name' => trim((string) $request->input('log_name', '')),
            'event' => trim((string) $request->input('event', '')),
        ];

        $recordsPerPage = min(100, max(1, (int) $request->input('records_per_page', 10)));

        $query = Activity::latest('created_at');

        if ($filters['q'] !== '') {
            $search = addcslashes($filters['q'], '%_\\');

            $query->where(function ($builder) use ($search) {
                $builder->where('description', 'like', "%{$search}%")
                    ->orWhere('log_name', 'like', "%{$search}%")
                    ->orWhere('event', 'like', "%{$search}%")
                    ->orWhere('subject_type', 'like', "%{$search}%");
            });
        }

        if ($filters['log_name'] !== '') {
            $query->where('log_name', $filters['log_name']);
        }

        if ($filters['event'] !== '') {
            $query->where('event', $filters['event']);
        }

        $activities = $query
            ->with(['causer'])
            ->paginate($recordsPerPage)
            ->appends(array_filter([
                'q' => $filters['q'] !== '' ? $filters['q'] : null,
                'log_name' => $filters['log_name'] !== '' ? $filters['log_name'] : null,
                'event' => $filters['event'] !== '' ? $filters['event'] : null,
                'records_per_page' => $recordsPerPage,
            ], fn($value) => $value !== null && $value !== ''));

        if ($activities->isEmpty() && $activities->currentPage() > 1) {
            return redirect()->route('activities.index', array_merge(
                $request->except('page'),
                ['page' => 1]
            ));
        }

        $logNames = Activity::select('log_name')
            ->whereNotNull('log_name')
            ->distinct()
            ->orderBy('log_name')
            ->pluck('log_name');

        $events = Activity::select('event')
            ->whereNotNull('event')
            ->distinct()
            ->orderBy('event')
            ->pluck('event');

        $hasActiveFilters = $filters['q'] !== ''
            || $filters['log_name'] !== ''
            || $filters['event'] !== ''
            || $recordsPerPage !== 10;

        return view('admin.activities.index', compact(
            'activities',
            'logNames',
            'events',
            'recordsPerPage',
            'filters',
            'hasActiveFilters',
        ));
    }

    public function show(Activity $activity): View
    {
        $activity->load(['causer', 'subject']);

        return view('admin.activities.show', compact('activity'));
    }
}
