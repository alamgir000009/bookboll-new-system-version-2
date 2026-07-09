@php
    use Illuminate\Support\Str;

    $causerName = $activity->causer
        ? (trim($activity->causer->name ?? '') ?: ($activity->causer->email ?? 'User #' . $activity->causer->id))
        : 'System';

    $properties = $activity->properties?->toArray() ?? [];
    $oldAttributes = $properties['old'] ?? null;
    $newAttributes = $properties['attributes'] ?? null;
    $extraProperties = collect($properties)->except(['old', 'attributes'])->filter(fn ($v) => $v !== null && $v !== []);

    $backUrl = route('activities.index', array_filter([
        'q' => $q,
        'log_name' => $log_name,
        'event' => $event,
        'records_per_page' => $records_per_page,
        'page' => $page,
    ], fn ($v) => $v !== '' && $v !== 10 && $v !== 1));
@endphp

<div>
    <header class="border-b border-gray-200 bg-white">
        <div class="px-6 py-5 sm:px-8">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div>
                    <a href="{{ $backUrl }}" wire:navigate class="inline-flex items-center text-sm text-sidebar hover:text-sidebar-dark transition mb-2">
                        <svg class="mr-1 h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                        </svg>
                        Back to activities
                    </a>
                    <h2 class="text-xl font-bold text-gray-800">Activity #{{ $activity->id }}</h2>
                </div>
                <x-activity-event-badge :event="$activity->event" class="self-start" />
            </div>
        </div>
    </header>

    <main class="px-4 py-6 sm:px-8 sm:py-8">
        <div class="mx-auto space-y-6">
            <div class="content-card">
                <h3 class="text-sm font-semibold uppercase tracking-wider text-gray-400 mb-4">Summary</h3>
                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-4">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Description</dt>
                        <dd class="mt-1 text-sm font-medium text-gray-900">{{ $activity->description }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Log Name</dt>
                        <dd class="mt-1">
                            <span class="inline-flex rounded-md bg-sidebar/10 px-2.5 py-1 text-sm font-medium text-sidebar">
                                {{ $activity->log_name ?? 'default' }}
                            </span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Event</dt>
                        <dd class="mt-1"><x-activity-event-badge :event="$activity->event" /></dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Date</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $activity->created_at?->format('F j, Y \a\t g:i:s A') }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Causer</dt>
                        <dd class="mt-1 text-sm text-gray-900">
                            {{ $causerName }}
                            @if ($activity->causer?->email)
                                <span class="block text-xs text-gray-500">{{ $activity->causer->email }}</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Subject</dt>
                        <dd class="mt-1 text-sm text-gray-900">
                            @if ($activity->subject_type)
                                {{ Str::afterLast($activity->subject_type, '\\') }}
                                <span class="text-gray-500">#{{ $activity->subject_id }}</span>
                                @if ($activity->subject)
                                    <span class="block text-xs text-gray-500 mt-0.5">{{ class_basename($activity->subject_type) }} record loaded</span>
                                @endif
                            @else
                                —
                            @endif
                        </dd>
                    </div>
                    @if ($activity->batch_uuid)
                        <div class="sm:col-span-2">
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Batch UUID</dt>
                            <dd class="mt-1 text-sm font-mono text-gray-700 break-all">{{ $activity->batch_uuid }}</dd>
                        </div>
                    @endif
                </dl>
            </div>

            @if ($oldAttributes || $newAttributes)
                <div class="content-card">
                    <h3 class="text-sm font-semibold uppercase tracking-wider text-gray-400 mb-4">Changes</h3>
                    <div class="grid grid-cols-1 lg:grid-cols-{{ $oldAttributes && $newAttributes ? 2 : 1 }} gap-4">
                        @if ($oldAttributes)
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-red-600 mb-2">Before</p>
                                <pre class="overflow-x-auto rounded-lg bg-red-50 border border-red-100 p-4 text-xs text-red-900">{{ json_encode($oldAttributes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                            </div>
                        @endif
                        @if ($newAttributes)
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-emerald-600 mb-2">After</p>
                                <pre class="overflow-x-auto rounded-lg bg-emerald-50 border border-emerald-100 p-4 text-xs text-emerald-900">{{ json_encode($newAttributes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                            </div>
                        @endif
                    </div>
                </div>
            @endif

            @if ($extraProperties->isNotEmpty())
                <div class="content-card">
                    <h3 class="text-sm font-semibold uppercase tracking-wider text-gray-400 mb-4">Additional Properties</h3>
                    <pre class="overflow-x-auto rounded-lg bg-gray-50 border border-gray-200 p-4 text-xs text-gray-800">{{ json_encode($extraProperties->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                </div>
            @endif

            @if (! empty($properties))
                <div class="content-card">
                    <h3 class="text-sm font-semibold uppercase tracking-wider text-gray-400 mb-4">Raw Properties</h3>
                    <pre class="overflow-x-auto rounded-lg bg-gray-900 p-4 text-xs text-gray-100">{{ json_encode($properties, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                </div>
            @endif

            @if ($activity->subject)
                <div class="content-card">
                    <h3 class="text-sm font-semibold uppercase tracking-wider text-gray-400 mb-4">Subject Record</h3>
                    <pre class="overflow-x-auto rounded-lg bg-gray-50 border border-gray-200 p-4 text-xs text-gray-800">{{ json_encode($activity->subject->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                </div>
            @endif
        </div>
    </main>
</div>
