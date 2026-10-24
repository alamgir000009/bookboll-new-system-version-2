@php
    use App\Models\PersonalAccessToken;

    $userLabel = function ($session) {
        $name = trim($session->user->name ?? '');

        return $name !== '' ? $name : ($session->user->email ?? 'User #'.$session->tokenable_id);
    };

    $initials = function ($session) use ($userLabel) {
        return collect(explode(' ', $userLabel($session)))
            ->map(fn ($part) => strtoupper(substr($part, 0, 1)))
            ->take(2)
            ->join('') ?: 'U';
    };

    $statusStyles = [
        PersonalAccessToken::STATUS_ACTIVE => 'border-green-200 bg-green-50 text-green-700',
        PersonalAccessToken::STATUS_IDLE => 'border-amber-200 bg-amber-50 text-amber-700',
        PersonalAccessToken::STATUS_UNUSED => 'border-gray-200 bg-gray-50 text-gray-600',
        PersonalAccessToken::STATUS_EXPIRED => 'border-red-200 bg-red-50 text-red-700',
    ];

    $deviceIcons = [
        'mobile' => '<path stroke-linecap="round" stroke-linejoin="round" d="M10.5 1.5H8.25A2.25 2.25 0 006 3.75v16.5a2.25 2.25 0 002.25 2.25h7.5A2.25 2.25 0 0018 20.25V3.75a2.25 2.25 0 00-2.25-2.25H13.5m-3 0V3h3V1.5m-3 0h3m-3 18.75h3" />',
        'tablet' => '<path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5h3M6.75 2.25h10.5a1.5 1.5 0 011.5 1.5v16.5a1.5 1.5 0 01-1.5 1.5H6.75a1.5 1.5 0 01-1.5-1.5V3.75a1.5 1.5 0 011.5-1.5z" />',
        'desktop' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25A2.25 2.25 0 015.25 3h13.5A2.25 2.25 0 0121 5.25z" />',
        'bot' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />',
        'unknown' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z" />',
    ];
@endphp

<div>
    <header class="border-b border-gray-200 bg-white">
        <div class="px-6 py-5 sm:px-8">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 class="text-xl font-bold text-gray-800">Active Sessions</h2>
                    <p class="mt-0.5 text-sm text-gray-500">
                        {{ number_format($stats['total']) }} API session{{ $stats['total'] === 1 ? '' : 's' }}
                        across {{ number_format($stats['users']) }} user{{ $stats['users'] === 1 ? '' : 's' }}
                        @if ($filteredTotal !== $stats['total'])
                            &middot; {{ number_format($filteredTotal) }} matching your filters
                        @endif
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs font-medium text-gray-700">
                        <input type="checkbox"
                               wire:model.live="protectOwnSessions"
                               class="h-4 w-4 rounded border-gray-300 text-sidebar focus:ring-sidebar" />
                        Protect my own sessions
                    </label>

                    <button type="button"
                            wire:click="revokeAll"
                            wire:confirm="Revoke EVERY API session in the system?&#10;&#10;All users will be signed out of the apps and must log in again. This cannot be undone."
                            wire:loading.attr="disabled"
                            class="inline-flex items-center gap-2 rounded-lg border border-red-300 bg-white px-4 py-2 text-sm font-semibold text-red-700 transition hover:bg-red-600 hover:text-white disabled:opacity-60">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5.636 5.636a9 9 0 1012.728 0M12 3v9" />
                        </svg>
                        Revoke every session
                    </button>
                </div>
            </div>
        </div>
    </header>

    <main class="px-4 py-6 sm:px-8 sm:py-8">
        <div class="space-y-6">
            @if ($statusMessage)
                <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                    {{ $statusMessage }}
                </div>
            @endif

            @if ($errorMessage)
                <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                    {{ $errorMessage }}
                </div>
            @endif

            {{-- Headline counts. Each tile is also a shortcut into the matching filter. --}}
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                @foreach ([
                    ['key' => '', 'label' => 'Total sessions', 'value' => $stats['total'], 'tone' => 'text-gray-900'],
                    ['key' => PersonalAccessToken::STATUS_ACTIVE, 'label' => 'Active', 'value' => $stats['active'], 'tone' => 'text-green-600'],
                    ['key' => PersonalAccessToken::STATUS_IDLE, 'label' => 'Idle', 'value' => $stats['idle'], 'tone' => 'text-amber-600'],
                    ['key' => PersonalAccessToken::STATUS_UNUSED, 'label' => 'Never used', 'value' => $stats['unused'], 'tone' => 'text-gray-500'],
                    ['key' => PersonalAccessToken::STATUS_EXPIRED, 'label' => 'Expired', 'value' => $stats['expired'], 'tone' => 'text-red-600'],
                ] as $tile)
                    <button type="button"
                            wire:click="$set('status', '{{ $tile['key'] }}')"
                            @class([
                                'rounded-lg border bg-white p-4 text-left shadow-sm transition hover:border-sidebar',
                                'border-sidebar ring-1 ring-sidebar' => $status === $tile['key'] && ($tile['key'] !== '' || $status === ''),
                                'border-gray-200' => ! ($status === $tile['key']),
                            ])>
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500">{{ $tile['label'] }}</p>
                        <p class="mt-1 text-2xl font-bold tabular-nums {{ $tile['tone'] }}">{{ number_format($tile['value']) }}</p>
                    </button>
                @endforeach
            </div>

            <div class="content-card">
                <div class="space-y-4">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
                        <div class="lg:col-span-2">
                            <label for="q" class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">Search</label>
                            <input type="text"
                                   id="q"
                                   wire:model.live.debounce.400ms="q"
                                   placeholder="Name, email, phone, IP, device..."
                                   class="input-brand" />
                        </div>
                        <div>
                            <label for="client" class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">App</label>
                            <select id="client" wire:model.live="client" class="input-brand">
                                <option value="">All apps</option>
                                @foreach ($clientOptions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="platform" class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">Platform</label>
                            <select id="platform" wire:model.live="platform" class="input-brand">
                                <option value="">All platforms</option>
                                @foreach ($platformOptions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="status" class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">Status</label>
                            <select id="status" wire:model.live="status" class="input-brand">
                                <option value="">Any status</option>
                                @foreach ($statusOptions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div>
                            <label for="records_per_page" class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">Per Page</label>
                            <select id="records_per_page" wire:model.live="records_per_page" class="input-brand">
                                @foreach ([10, 25, 50, 100] as $size)
                                    <option value="{{ $size }}">{{ $size }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="sm:col-span-2 flex items-end gap-2">
                            @if ($activeFilters !== [])
                                <button type="button"
                                        wire:click="clearFilters"
                                        class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50">
                                    Clear filters
                                </button>
                                <button type="button"
                                        wire:click="revokeMatchingFilters"
                                        wire:confirm="Revoke all {{ number_format($filteredTotal) }} session(s) matching the active filters?&#10;&#10;Those users will be signed out and must log in again. This cannot be undone."
                                        class="inline-flex items-center gap-2 rounded-lg border border-red-300 bg-white px-4 py-2.5 text-sm font-semibold text-red-700 transition hover:bg-red-600 hover:text-white">
                                    Revoke all {{ number_format($filteredTotal) }} matching
                                </button>
                            @endif
                            <span wire:loading class="self-center text-xs text-sidebar">Updating...</span>
                        </div>
                    </div>
                </div>

                @if ($activeFilters !== [])
                    <div class="mt-4 flex flex-wrap gap-2 border-t border-gray-100 pt-4">
                        <span class="self-center text-xs font-medium uppercase tracking-wide text-gray-400">Active:</span>
                        @foreach ($activeFilters as $name => $value)
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-sidebar/10 px-2.5 py-1 text-xs font-medium text-sidebar">
                                {{ ucfirst(str_replace('_', ' ', $name)) }}:
                                @if ($name === 'client')
                                    {{ $clientOptions[$value] ?? $value }}
                                @elseif ($name === 'platform')
                                    {{ $platformOptions[$value] ?? $value }}
                                @elseif ($name === 'status')
                                    {{ ucfirst($value) }}
                                @else
                                    {{ $value }}
                                @endif
                            </span>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="sessions-table-wrap">
                <div class="flex flex-col gap-3 border-b border-gray-100 bg-white px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900">API sessions</h3>
                        <p class="mt-0.5 text-xs text-gray-500">
                            A session counts as active when its token was used within the last {{ $activeWindowLabel }}.
                            Tokens here never expire on their own — revoking is what ends them.
                        </p>
                    </div>

                    <button type="button"
                            wire:click="revokeSelected"
                            wire:confirm="Revoke the {{ count($selected) }} selected session(s)?&#10;&#10;Those users will be signed out and must log in again. This cannot be undone."
                            @disabled(count($selected) === 0)
                            class="inline-flex shrink-0 items-center gap-2 rounded-lg border border-red-300 bg-white px-4 py-2 text-sm font-semibold text-red-700 transition hover:bg-red-600 hover:text-white disabled:cursor-not-allowed disabled:border-gray-200 disabled:text-gray-400 disabled:hover:bg-white">
                        Revoke selected
                        @if (count($selected) > 0)
                            <span class="inline-flex min-w-[1.25rem] items-center justify-center rounded-full bg-red-100 px-1.5 py-0.5 text-[10px] font-bold text-red-700">{{ count($selected) }}</span>
                        @endif
                    </button>
                </div>

                <div class="overflow-x-auto">
                    <table class="sessions-table">
                        <thead>
                            <tr>
                                <th class="w-10">
                                    <span class="sr-only">Select</span>
                                </th>
                                <th class="min-w-[200px]">User</th>
                                <th class="min-w-[140px]">App</th>
                                <th class="min-w-[200px]">Device</th>
                                <th class="min-w-[170px]">IP address</th>
                                <th>
                                    <button type="button" wire:click="sortBy('last_used_at')" class="inline-flex items-center gap-1 hover:text-sidebar">
                                        Last active
                                        @if ($sort === 'last_used_at')
                                            <span class="text-[10px]">{{ $direction === 'asc' ? '▲' : '▼' }}</span>
                                        @endif
                                    </button>
                                </th>
                                <th>
                                    <button type="button" wire:click="sortBy('created_at')" class="inline-flex items-center gap-1 hover:text-sidebar">
                                        Signed in
                                        @if ($sort === 'created_at')
                                            <span class="text-[10px]">{{ $direction === 'asc' ? '▲' : '▼' }}</span>
                                        @endif
                                    </button>
                                </th>
                                <th>Status</th>
                                <th class="w-40 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($sessions as $session)
                                @php
                                    $device = $session->device();
                                    $address = $session->address();
                                    $sessionStatus = $session->status();
                                    $isOwn = (int) $session->tokenable_id === $currentUserId;
                                @endphp
                                <tr wire:key="session-{{ $session->id }}">
                                    <td>
                                        <input type="checkbox"
                                               wire:model.live="selected"
                                               value="{{ $session->id }}"
                                               aria-label="Select session {{ $session->id }}"
                                               class="h-4 w-4 rounded border-gray-300 text-sidebar focus:ring-sidebar" />
                                    </td>

                                    <td>
                                        <div class="flex min-w-0 items-center gap-2.5">
                                            <span class="sessions-avatar">{{ $initials($session) }}</span>
                                            <div class="min-w-0">
                                                <p class="truncate text-sm font-semibold text-gray-900" title="{{ $userLabel($session) }}">
                                                    {{ $userLabel($session) }}
                                                    @if ($isOwn)
                                                        <span class="ml-1 rounded bg-sidebar/10 px-1.5 py-0.5 text-[10px] font-bold uppercase text-sidebar-dark">You</span>
                                                    @endif
                                                </p>
                                                <p class="truncate text-xs text-gray-500" title="{{ $session->user->email ?? '' }}">
                                                    {{ $session->user->email ?? '—' }}
                                                </p>
                                                @if ($session->user && $session->user->roles->isNotEmpty())
                                                    <p class="mt-0.5 truncate text-[11px] text-gray-400">
                                                        {{ $session->user->roles->pluck('name')->join(', ') }}
                                                    </p>
                                                @endif
                                            </div>
                                        </div>
                                    </td>

                                    <td>
                                        <span class="sessions-app-badge">{{ $session->clientLabel() }}</span>
                                    </td>

                                    <td>
                                        <div class="flex min-w-0 items-start gap-2">
                                            <svg class="mt-0.5 h-4 w-4 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                                                {!! $deviceIcons[$device->deviceType] ?? $deviceIcons['unknown'] !!}
                                            </svg>
                                            <div class="min-w-0">
                                                <p class="truncate text-sm text-gray-800" title="{{ $device->raw ?? 'No user agent recorded' }}">
                                                    {{ $device->summary() }}
                                                </p>
                                                @if ($session->isUntracked())
                                                    <p class="text-[11px] text-gray-400">Issued before device tracking</p>
                                                @elseif ($session->hasChangedDevice())
                                                    <p class="text-[11px] font-medium text-amber-600">Device changed since sign-in</p>
                                                @endif
                                            </div>
                                        </div>
                                    </td>

                                    <td>
                                        @if ($address->isKnown())
                                            <p class="font-mono text-[12px] text-gray-800">{{ $address->label() }}</p>
                                            <p class="text-[11px] text-gray-400">{{ $address->locationLabel() }}</p>
                                            @if ($session->hasMovedAddress())
                                                <p class="text-[11px] font-medium text-amber-600" title="Issued to {{ $session->ip_address }}">
                                                    Moved from {{ $session->ip_address }}
                                                </p>
                                            @endif
                                        @else
                                            <span class="text-xs text-gray-400">Not recorded</span>
                                        @endif
                                    </td>

                                    <td>
                                        @if ($session->last_used_at)
                                            <div class="text-sm font-medium text-gray-800">{{ $session->last_used_at->diffForHumans() }}</div>
                                            <div class="mt-0.5 text-[11px] text-gray-400">{{ $session->last_used_at->format('M j, Y · g:i A') }}</div>
                                        @else
                                            <span class="text-xs text-gray-400">Never</span>
                                        @endif
                                    </td>

                                    <td>
                                        @if ($session->created_at)
                                            <div class="text-sm text-gray-700">{{ $session->created_at->diffForHumans() }}</div>
                                            <div class="mt-0.5 text-[11px] text-gray-400">{{ $session->created_at->format('M j, Y · g:i A') }}</div>
                                        @else
                                            <span class="text-xs text-gray-400">—</span>
                                        @endif
                                    </td>

                                    <td>
                                        <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-[11px] font-semibold capitalize {{ $statusStyles[$sessionStatus] ?? $statusStyles[PersonalAccessToken::STATUS_UNUSED] }}">
                                            {{ $sessionStatus === PersonalAccessToken::STATUS_UNUSED ? 'Never used' : $sessionStatus }}
                                        </span>
                                    </td>

                                    <td class="text-right">
                                        <div class="flex items-center justify-end gap-1.5">
                                            <button type="button"
                                                    wire:click="inspect({{ $session->id }})"
                                                    class="sessions-view-btn"
                                                    title="Full session details">
                                                Details
                                            </button>
                                            <button type="button"
                                                    wire:click="revoke({{ $session->id }})"
                                                    wire:confirm="Revoke this session?&#10;&#10;{{ $userLabel($session) }} will be signed out of {{ $session->clientLabel() }} and must log in again."
                                                    class="inline-flex items-center rounded-lg border border-red-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-red-600 transition hover:border-red-500 hover:bg-red-600 hover:text-white"
                                                    title="Sign this device out">
                                                Revoke
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr wire:key="sessions-empty">
                                    <td colspan="9">
                                        <div class="sessions-empty">
                                            <span class="mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-sidebar/10 text-sidebar">
                                                <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                                                </svg>
                                            </span>
                                            <p class="text-sm font-semibold text-gray-900">
                                                {{ $activeFilters !== [] ? 'No sessions match your filters' : 'No API sessions' }}
                                            </p>
                                            <p class="mt-1 max-w-sm text-sm text-gray-500">
                                                {{ $activeFilters !== []
                                                    ? 'Try a different app, platform, or status — or clear the filters.'
                                                    : 'Sessions appear here as soon as someone signs in to one of the apps.' }}
                                            </p>
                                            @if ($activeFilters !== [])
                                                <button type="button"
                                                        wire:click="clearFilters"
                                                        class="mt-4 inline-flex items-center rounded-lg bg-sidebar px-4 py-2 text-sm font-semibold text-white transition hover:bg-sidebar-dark">
                                                    Clear filters
                                                </button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($sessions->total() > 0)
                    <div class="flex flex-col gap-3 border-t border-gray-100 bg-gray-50/80 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-sm text-gray-600">
                            Showing <span class="font-semibold text-gray-900">{{ $sessions->firstItem() ?? 0 }}–{{ $sessions->lastItem() ?? 0 }}</span>
                            of <span class="font-semibold text-gray-900">{{ number_format($sessions->total()) }}</span>
                            · {{ $recordsPerPage }} per page
                        </p>
                        @if ($sessions->hasPages())
                            <div class="sessions-pagination">
                                {{ $sessions->links() }}
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </main>

    {{-- Full detail for one session, plus the per-user revoke that only makes
         sense once you can see who the session belongs to. --}}
    @if ($inspected)
        @php
            $inspectedDevice = $inspected->device();
            $issuedDevice = $inspected->issuedDevice();
            $inspectedAddress = $inspected->address();
            $issuedAddress = $inspected->issuedAddress();
        @endphp
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/50 p-4 sm:p-8"
             wire:click.self="closeInspector"
             wire:key="inspector-{{ $inspected->id }}">
            <div class="w-full max-w-2xl rounded-xl bg-white shadow-xl">
                <div class="flex items-start justify-between border-b border-gray-200 px-6 py-4">
                    <div>
                        <h3 class="text-base font-bold text-gray-900">Session details</h3>
                        <p class="mt-0.5 text-xs text-gray-500">Token #{{ $inspected->id }} · {{ $inspected->clientLabel() }}</p>
                    </div>
                    <button type="button" wire:click="closeInspector" class="rounded-lg p-1.5 text-gray-400 transition hover:bg-gray-100 hover:text-gray-700" aria-label="Close">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="space-y-5 px-6 py-5">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">Account</p>
                            <p class="mt-1 text-sm font-semibold text-gray-900">{{ $inspected->user->name ?? '—' }}</p>
                            <p class="text-xs text-gray-500">{{ $inspected->user->email ?? '—' }}</p>
                            @if ($inspected->user?->phone)
                                <p class="text-xs text-gray-500">{{ $inspected->user->phone }}</p>
                            @endif
                            @if ($inspected->user && $inspected->user->roles->isNotEmpty())
                                <p class="mt-1 text-xs text-gray-400">Roles: {{ $inspected->user->roles->pluck('name')->join(', ') }}</p>
                            @endif
                            @if ($inspected->user?->status)
                                <p class="text-xs text-gray-400">Account status: {{ $inspected->user->status }}</p>
                            @endif
                        </div>

                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">Session</p>
                            <p class="mt-1 text-sm text-gray-800">Status: <span class="font-semibold capitalize">{{ $inspected->status() }}</span></p>
                            <p class="text-xs text-gray-500">Signed in: {{ $inspected->created_at?->format('M j, Y · g:i A') ?? '—' }}</p>
                            <p class="text-xs text-gray-500">Last active: {{ $inspected->last_used_at?->format('M j, Y · g:i A') ?? 'Never used' }}</p>
                            <p class="text-xs text-gray-500">Expires: {{ $inspected->expires_at?->format('M j, Y · g:i A') ?? 'Never (no expiry configured)' }}</p>
                            <p class="mt-1 text-xs text-gray-400">Raw token name: <span class="font-mono">{{ $inspected->name }}</span></p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-4 border-t border-gray-100 pt-4 sm:grid-cols-2">
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">Signed in from</p>
                            <p class="mt-1 font-mono text-[12px] text-gray-800">{{ $issuedAddress->label() }}</p>
                            <p class="text-xs text-gray-500">{{ $issuedAddress->locationLabel() }}</p>
                            <p class="mt-1 text-sm text-gray-800">{{ $issuedDevice->summary() }}</p>
                        </div>
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">Last used from</p>
                            <p class="mt-1 font-mono text-[12px] text-gray-800">{{ $inspectedAddress->label() }}</p>
                            <p class="text-xs text-gray-500">{{ $inspectedAddress->locationLabel() }}</p>
                            <p class="mt-1 text-sm text-gray-800">{{ $inspectedDevice->summary() }}</p>
                        </div>
                    </div>

                    @if ($inspected->hasMovedAddress() || $inspected->hasChangedDevice())
                        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-800">
                            <p class="font-semibold">Worth a look</p>
                            <ul class="mt-1 list-inside list-disc space-y-0.5">
                                @if ($inspected->hasMovedAddress())
                                    <li>This session is being used from a different address than it was issued to. Normal on mobile networks, but not on a fixed connection.</li>
                                @endif
                                @if ($inspected->hasChangedDevice())
                                    <li>The user agent changed since sign-in. An app update explains this; a different device does not.</li>
                                @endif
                            </ul>
                        </div>
                    @endif

                    <div class="border-t border-gray-100 pt-4">
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">Abilities</p>
                        <p class="mt-1 font-mono text-[11px] text-gray-600">
                            {{ empty($inspected->abilities) ? '—' : implode(', ', (array) $inspected->abilities) }}
                        </p>
                    </div>

                    <div class="space-y-3 border-t border-gray-100 pt-4">
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">User agent at sign-in</p>
                            <p class="mt-1 break-all font-mono text-[11px] text-gray-600">{{ $inspected->user_agent ?? 'Not recorded' }}</p>
                        </div>
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">User agent last seen</p>
                            <p class="mt-1 break-all font-mono text-[11px] text-gray-600">{{ $inspected->last_used_user_agent ?? 'Not recorded' }}</p>
                        </div>
                    </div>
                </div>

                <div class="flex flex-col gap-2 border-t border-gray-200 bg-gray-50 px-6 py-4 sm:flex-row sm:justify-end">
                    <button type="button"
                            wire:click="filterToUser({{ $inspected->tokenable_id }})"
                            class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-100">
                        Show this user's sessions
                    </button>
                    <button type="button"
                            wire:click="revokeAllForUser({{ $inspected->tokenable_id }})"
                            wire:confirm="Revoke every session for {{ $inspected->user->email ?? 'this user' }}?&#10;&#10;They will be signed out on every device and must log in again. This cannot be undone."
                            class="inline-flex items-center justify-center rounded-lg border border-red-300 bg-white px-4 py-2 text-sm font-semibold text-red-700 transition hover:bg-red-600 hover:text-white">
                        Revoke all sessions for this user
                    </button>
                    <button type="button"
                            wire:click="revoke({{ $inspected->id }})"
                            wire:confirm="Revoke this one session?&#10;&#10;The user will be signed out of {{ $inspected->clientLabel() }} and must log in again."
                            class="inline-flex items-center justify-center rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-red-700">
                        Revoke this session
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
