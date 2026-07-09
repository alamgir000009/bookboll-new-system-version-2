@php
    use Illuminate\Support\Str;

    $causerName = function ($activity) {
        if (! $activity->causer) {
            return 'System';
        }

        $name = trim($activity->causer->name ?? '');

        return $name !== '' ? $name : ($activity->causer->email ?? 'User #' . $activity->causer->id);
    };

    $subjectLabel = function ($activity) {
        if (! $activity->subject_type) {
            return null;
        }

        return [
            'type' => Str::afterLast($activity->subject_type, '\\'),
            'id' => $activity->subject_id,
        ];
    };

    $causerInitials = function ($activity) use ($causerName) {
        $name = $causerName($activity);

        if ($name === 'System') {
            return 'SY';
        }

        return collect(explode(' ', $name))
            ->map(fn ($part) => strtoupper(substr($part, 0, 1)))
            ->take(2)
            ->join('');
    };
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <h2 class="text-xl font-bold text-gray-800">Activity Log</h2>
                <p class="mt-0.5 text-sm text-gray-500">
                    {{ number_format($activities->total()) }} total records
                    @if ($activities->total() > 0)
                        &middot; showing {{ $activities->firstItem() }}–{{ $activities->lastItem() }}
                    @endif
                </p>
            </div>
        </div>
    </x-slot>

    <div class="space-y-6">
        <div class="content-card">
            <form method="GET"
                  action="{{ route('activities.index') }}"
                  id="activities-filter-form"
                  class="space-y-4">
                <input type="hidden" name="page" value="1">

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
                    <div class="lg:col-span-2">
                        <label for="q" class="block text-xs font-medium uppercase tracking-wide text-gray-500 mb-1">Search</label>
                        <input type="text"
                               id="q"
                               name="q"
                               value="{{ $filters['q'] }}"
                               placeholder="Description, log name, event, subject..."
                               class="input-brand"
                               onkeydown="if (event.key === 'Enter') { event.preventDefault(); this.form.requestSubmit(); }" />
                    </div>
                    <div>
                        <label for="log_name" class="block text-xs font-medium uppercase tracking-wide text-gray-500 mb-1">Log Name</label>
                        <select id="log_name" name="log_name" class="input-brand" onchange="this.form.requestSubmit()">
                            <option value="">All logs</option>
                            @foreach ($logNames as $name)
                                <option value="{{ $name }}" @selected($filters['log_name'] === (string) $name)>{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="event" class="block text-xs font-medium uppercase tracking-wide text-gray-500 mb-1">Event</label>
                        <select id="event" name="event" class="input-brand" onchange="this.form.requestSubmit()">
                            <option value="">All events</option>
                            @foreach ($events as $evt)
                                <option value="{{ $evt }}" @selected($filters['event'] === (string) $evt)>{{ $evt }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="records_per_page" class="block text-xs font-medium uppercase tracking-wide text-gray-500 mb-1">Per Page</label>
                        <select id="records_per_page" name="records_per_page" class="input-brand" onchange="this.form.requestSubmit()">
                            @foreach ([10, 25, 50, 100] as $size)
                                <option value="{{ $size }}" @selected((int) $recordsPerPage === $size)>{{ $size }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <button type="submit" class="inline-flex items-center rounded-lg bg-sidebar px-4 py-2 text-sm font-semibold text-white hover:bg-sidebar-dark transition">
                        Apply filters
                    </button>
                    @if ($hasActiveFilters)
                        <a href="{{ route('activities.index') }}" class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition">
                            Clear
                        </a>
                    @endif
                    <span class="text-xs text-gray-400">Dropdowns apply instantly · press Enter to search</span>
                </div>
            </form>

            @if ($hasActiveFilters)
                <div class="mt-4 flex flex-wrap gap-2 border-t border-gray-100 pt-4">
                    <span class="text-xs font-medium uppercase tracking-wide text-gray-400 self-center">Active:</span>
                    @if ($filters['q'] !== '')
                        <span class="inline-flex items-center rounded-full bg-sidebar/10 px-2.5 py-1 text-xs font-medium text-sidebar">
                            Search: {{ $filters['q'] }}
                        </span>
                    @endif
                    @if ($filters['log_name'] !== '')
                        <span class="inline-flex items-center rounded-full bg-sidebar/10 px-2.5 py-1 text-xs font-medium text-sidebar">
                            Log: {{ $filters['log_name'] }}
                        </span>
                    @endif
                    @if ($filters['event'] !== '')
                        <span class="inline-flex items-center rounded-full bg-sidebar/10 px-2.5 py-1 text-xs font-medium text-sidebar">
                            Event: {{ $filters['event'] }}
                        </span>
                    @endif
                    @if ((int) $recordsPerPage !== 10)
                        <span class="inline-flex items-center rounded-full bg-sidebar/10 px-2.5 py-1 text-xs font-medium text-sidebar">
                            Per page: {{ $recordsPerPage }}
                        </span>
                    @endif
                </div>
            @endif
        </div>

        <div class="activities-table-wrap">
            <div class="flex items-center justify-between border-b border-gray-100 bg-white px-5 py-4">
                <div>
                    <h3 class="text-sm font-semibold text-gray-900">Recent activity</h3>
                    <p class="text-xs text-gray-500 mt-0.5">Click a row or View to inspect full details</p>
                </div>
                @if ($activities->total() > 0)
                    <span class="hidden sm:inline-flex items-center rounded-full bg-sidebar/10 px-3 py-1 text-xs font-semibold text-sidebar-dark">
                        {{ $activities->count() }} on this page
                    </span>
                @endif
            </div>

            <div class="overflow-x-auto">
                <table class="activities-table">
                    <thead>
                        <tr>
                            <th class="w-16">ID</th>
                            <th class="min-w-[220px]">Description</th>
                            <th>Log</th>
                            <th>Event</th>
                            <th>Subject</th>
                            <th>Causer</th>
                            <th class="min-w-[140px]">When</th>
                            <th class="w-24 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($activities as $activity)
                            @php
                                $subject = $subjectLabel($activity);
                                $detailUrl = route('activities.show', array_merge(['activity' => $activity], request()->only(['q', 'log_name', 'event', 'records_per_page', 'page'])));
                            @endphp
                            <tr class="group cursor-pointer" data-href="{{ $detailUrl }}" onclick="if (!event.target.closest('a,button')) window.location=this.dataset.href">
                                <td>
                                    <span class="activities-id">#{{ $activity->id }}</span>
                                </td>
                                <td>
                                    <p class="text-sm font-medium text-gray-900 line-clamp-2 group-hover:text-sidebar-dark transition-colors" title="{{ $activity->description }}">
                                        {{ $activity->description }}
                                    </p>
                                </td>
                                <td>
                                    <span class="activities-log-badge">
                                        {{ $activity->log_name ?? 'default' }}
                                    </span>
                                </td>
                                <td>
                                    <x-activity-event-badge :event="$activity->event" />
                                </td>
                                <td>
                                    @if ($subject)
                                        <span class="activities-subject" title="{{ $subject['type'] }} #{{ $subject['id'] }}">
                                            {{ $subject['type'] }} #{{ $subject['id'] }}
                                        </span>
                                    @else
                                        <span class="text-xs text-gray-400">—</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="activities-causer">
                                        <span class="activities-causer-avatar">{{ $causerInitials($activity) }}</span>
                                        <span class="truncate text-sm text-gray-700 max-w-[8rem]" title="{{ $causerName($activity) }}">
                                            {{ $causerName($activity) }}
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <div class="text-sm font-medium text-gray-800">
                                        {{ $activity->created_at?->diffForHumans() }}
                                    </div>
                                    <div class="text-[11px] text-gray-400 mt-0.5">
                                        {{ $activity->created_at?->format('M j, Y · g:i A') }}
                                    </div>
                                </td>
                                <td class="text-right" onclick="event.stopPropagation()">
                                    <a href="{{ $detailUrl }}" class="activities-view-btn">
                                        View
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                                        </svg>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8">
                                    <div class="activities-empty">
                                        <span class="mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-sidebar/10 text-sidebar">
                                            <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                        </span>
                                        <p class="text-sm font-semibold text-gray-900">
                                            @if ($hasActiveFilters)
                                                No activities match your filters
                                            @else
                                                No activities yet
                                            @endif
                                        </p>
                                        <p class="mt-1 max-w-sm text-sm text-gray-500">
                                            @if ($hasActiveFilters)
                                                Try adjusting your search or clearing filters to see more results.
                                            @else
                                                Activity will appear here when models are created, updated, or deleted.
                                            @endif
                                        </p>
                                        @if ($hasActiveFilters)
                                            <a href="{{ route('activities.index') }}" class="mt-4 inline-flex items-center rounded-lg bg-sidebar px-4 py-2 text-sm font-semibold text-white hover:bg-sidebar-dark transition">
                                                Clear filters
                                            </a>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($activities->hasPages() || $activities->total() > 0)
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 border-t border-gray-100 bg-gray-50/80 px-5 py-4">
                    <p class="text-sm text-gray-600">
                        Showing <span class="font-semibold text-gray-900">{{ $activities->firstItem() ?? 0 }}–{{ $activities->lastItem() ?? 0 }}</span>
                        of <span class="font-semibold text-gray-900">{{ number_format($activities->total()) }}</span>
                        · {{ $recordsPerPage }} per page
                    </p>
                    @if ($activities->hasPages())
                        <div class="activities-pagination">
                            {{ $activities->links() }}
                        </div>
                    @endif
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
