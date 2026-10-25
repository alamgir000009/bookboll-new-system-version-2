@php
    $holderName = function ($lock) {
        if ($lock->user) {
            return trim($lock->user->name ?? '') !== '' ? $lock->user->name : $lock->user->email;
        }

        return 'Guest';
    };

    $slotLabel = function ($lock) {
        $times = array_filter([
            $lock->start_time ? substr((string) $lock->start_time, 0, 5) : null,
            $lock->end_time ? substr((string) $lock->end_time, 0, 5) : null,
        ]);

        return $times === [] ? 'Whole day' : implode(' – ', $times);
    };
@endphp

<div>
    <header class="border-b border-gray-200 bg-white">
        <div class="px-6 py-5 sm:px-8">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 class="text-xl font-bold text-gray-800">Slot Locks</h2>
                    <p class="mt-0.5 text-sm text-gray-500">
                        {{ number_format($stats['active']) }} slot{{ $stats['active'] === 1 ? '' : 's' }} currently held
                        @if ($stats['expired'] > 0)
                            &middot; {{ number_format($stats['expired']) }} lapsed, awaiting cleanup
                        @endif
                        @if ($filteredTotal !== $stats['total'])
                            &middot; {{ number_format($filteredTotal) }} matching your filters
                        @endif
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    @if ($stats['expired'] > 0)
                        <button type="button"
                                wire:click="releaseExpired"
                                wire:confirm="Clear {{ number_format($stats['expired']) }} expired hold(s)?&#10;&#10;These already block nothing — this is tidying up rows that slot-locks:clear would remove within five minutes."
                                class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50">
                            Clear {{ number_format($stats['expired']) }} expired
                        </button>
                    @endif

                    <button type="button"
                            wire:click="releaseAll"
                            wire:confirm="Release EVERY slot hold in the system?&#10;&#10;Anyone currently on a checkout page loses their slot and may find it taken when they try to pay. This cannot be undone."
                            class="inline-flex items-center gap-2 rounded-lg border border-red-300 bg-white px-4 py-2 text-sm font-semibold text-red-700 transition hover:bg-red-600 hover:text-white">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 10.5V6.75a4.5 4.5 0 119 0v3.75M3.75 21.75h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H3.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                        </svg>
                        Release every hold
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

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                @foreach ([
                    ['key' => '', 'label' => 'All holds', 'value' => $stats['total'], 'tone' => 'text-gray-900'],
                    ['key' => 'active', 'label' => 'Active (blocking)', 'value' => $stats['active'], 'tone' => 'text-amber-600'],
                    ['key' => 'expired', 'label' => 'Expired', 'value' => $stats['expired'], 'tone' => 'text-gray-500'],
                ] as $tile)
                    <button type="button"
                            wire:click="$set('status', '{{ $tile['key'] }}')"
                            @class([
                                'rounded-lg border bg-white p-4 text-left shadow-sm transition hover:border-sidebar',
                                'border-sidebar ring-1 ring-sidebar' => $status === $tile['key'],
                                'border-gray-200' => $status !== $tile['key'],
                            ])>
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500">{{ $tile['label'] }}</p>
                        <p class="mt-1 text-2xl font-bold tabular-nums {{ $tile['tone'] }}">{{ number_format($tile['value']) }}</p>
                    </button>
                @endforeach

                <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500">Listings affected</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-gray-900">{{ number_format($stats['listings']) }}</p>
                </div>

                <button type="button"
                        wire:click="$set('holder', 'guest')"
                        @class([
                            'rounded-lg border bg-white p-4 text-left shadow-sm transition hover:border-sidebar',
                            'border-sidebar ring-1 ring-sidebar' => $holder === 'guest',
                            'border-gray-200' => $holder !== 'guest',
                        ])>
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500">Held by guests</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-gray-900">{{ number_format($stats['guests']) }}</p>
                </button>
            </div>

            <div class="content-card">
                <div class="space-y-4">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div class="lg:col-span-2">
                            <label for="q" class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">Search</label>
                            <input type="text" id="q" wire:model.live.debounce.400ms="q"
                                   placeholder="Customer, listing, venue, IP, session..." class="input-brand" />
                        </div>
                        <div>
                            <label for="venue_id" class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">Venue</label>
                            <select id="venue_id" wire:model.live="venue_id" class="input-brand">
                                <option value="">All venues</option>
                                @foreach ($venues as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="listing_id" class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">Listing</label>
                            <select id="listing_id" wire:model.live="listing_id" class="input-brand">
                                <option value="">All listings</option>
                                @foreach ($listings as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
                        <div>
                            <label for="provider_id" class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">Provider</label>
                            <select id="provider_id" wire:model.live="provider_id" class="input-brand">
                                <option value="">All providers</option>
                                @foreach ($providers as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="customer_id" class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">Customer</label>
                            <select id="customer_id" wire:model.live="customer_id" class="input-brand">
                                <option value="">All customers</option>
                                @foreach ($customers as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="holder" class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">Held by</label>
                            <select id="holder" wire:model.live="holder" class="input-brand">
                                <option value="">Anyone</option>
                                <option value="customer">Signed-in customer</option>
                                <option value="guest">Guest</option>
                            </select>
                        </div>
                        <div>
                            <label for="date" class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">Slot date</label>
                            <input type="date" id="date" wire:model.live="date" class="input-brand" />
                        </div>
                        <div>
                            <label for="records_per_page" class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">Per Page</label>
                            <select id="records_per_page" wire:model.live="records_per_page" class="input-brand">
                                @foreach ([10, 25, 50, 100] as $size)
                                    <option value="{{ $size }}">{{ $size }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    @if ($activeFilters !== [])
                        <div class="flex flex-wrap items-center gap-2 border-t border-gray-100 pt-4">
                            <button type="button" wire:click="clearFilters"
                                    class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50">
                                Clear filters
                            </button>
                            <button type="button"
                                    wire:click="releaseMatchingFilters"
                                    wire:confirm="Release all {{ number_format($filteredTotal) }} hold(s) matching the active filters?&#10;&#10;Any that are still active free their slot immediately. This cannot be undone."
                                    class="inline-flex items-center rounded-lg border border-red-300 bg-white px-4 py-2 text-sm font-semibold text-red-700 transition hover:bg-red-600 hover:text-white">
                                Release all {{ number_format($filteredTotal) }} matching
                            </button>
                            <span wire:loading class="text-xs text-sidebar">Updating...</span>
                        </div>
                    @endif
                </div>
            </div>

            <div class="sessions-table-wrap">
                <div class="flex flex-col gap-3 border-b border-gray-100 bg-white px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900">Held slots</h3>
                        <p class="mt-0.5 text-xs text-gray-500">
                            A hold is taken when checkout opens and lapses on its own. Only active holds block a booking.
                        </p>
                    </div>

                    <button type="button"
                            wire:click="releaseSelected"
                            wire:confirm="Release the {{ count($selected) }} selected hold(s)?"
                            @disabled(count($selected) === 0)
                            class="inline-flex shrink-0 items-center gap-2 rounded-lg border border-red-300 bg-white px-4 py-2 text-sm font-semibold text-red-700 transition hover:bg-red-600 hover:text-white disabled:cursor-not-allowed disabled:border-gray-200 disabled:text-gray-400 disabled:hover:bg-white">
                        Release selected
                        @if (count($selected) > 0)
                            <span class="inline-flex min-w-[1.25rem] items-center justify-center rounded-full bg-red-100 px-1.5 py-0.5 text-[10px] font-bold text-red-700">{{ count($selected) }}</span>
                        @endif
                    </button>
                </div>

                <div class="overflow-x-auto">
                    <table class="sessions-table">
                        <thead>
                            <tr>
                                <th class="w-10"><span class="sr-only">Select</span></th>
                                <th class="min-w-[150px]">
                                    <button type="button" wire:click="sortBy('date')" class="inline-flex items-center gap-1 hover:text-sidebar">
                                        Slot
                                        @if ($sort === 'date')<span class="text-[10px]">{{ $direction === 'asc' ? '▲' : '▼' }}</span>@endif
                                    </button>
                                </th>
                                <th class="min-w-[190px]">Listing / venue</th>
                                <th class="min-w-[140px]">Provider</th>
                                <th class="min-w-[180px]">Held by</th>
                                <th class="min-w-[150px]">
                                    <button type="button" wire:click="sortBy('locked_until')" class="inline-flex items-center gap-1 hover:text-sidebar">
                                        Expires
                                        @if ($sort === 'locked_until')<span class="text-[10px]">{{ $direction === 'asc' ? '▲' : '▼' }}</span>@endif
                                    </button>
                                </th>
                                <th>Status</th>
                                <th class="w-40 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($locks as $lock)
                                @php $expired = $lock->isExpired(); @endphp
                                <tr wire:key="lock-{{ $lock->id }}">
                                    <td>
                                        <input type="checkbox" wire:model.live="selected" value="{{ $lock->id }}"
                                               aria-label="Select hold {{ $lock->id }}"
                                               class="h-4 w-4 rounded border-gray-300 text-sidebar focus:ring-sidebar" />
                                    </td>

                                    <td>
                                        <p class="text-sm font-semibold text-gray-900">{{ $slotLabel($lock) }}</p>
                                        <p class="mt-0.5 text-[11px] text-gray-500">{{ $lock->date?->format('D, M j, Y') ?? '—' }}</p>
                                    </td>

                                    <td>
                                        <p class="truncate text-sm text-gray-800" title="{{ $lock->listing?->name }}">
                                            {{ $lock->listing?->name ?? 'Listing #'.$lock->listing_id }}
                                        </p>
                                        <p class="mt-0.5 truncate text-[11px] text-gray-500">
                                            {{ $lock->listing?->venue?->name ?? '—' }}
                                        </p>
                                    </td>

                                    <td>
                                        <p class="truncate text-sm text-gray-700">
                                            {{ $lock->listing?->venue?->owner?->name ?: ($lock->listing?->venue?->owner?->email ?? '—') }}
                                        </p>
                                    </td>

                                    <td>
                                        <div class="flex min-w-0 items-center gap-2.5">
                                            <span class="sessions-avatar">
                                                {{ $lock->isGuest() ? 'G' : strtoupper(substr($holderName($lock), 0, 2)) }}
                                            </span>
                                            <div class="min-w-0">
                                                <p class="truncate text-sm font-medium text-gray-900">{{ $holderName($lock) }}</p>
                                                <p class="truncate text-[11px] text-gray-500">
                                                    {{ $lock->user?->email ?? $lock->ip_address }}
                                                </p>
                                            </div>
                                        </div>
                                    </td>

                                    <td>
                                        @if ($lock->locked_until)
                                            <div class="text-sm font-medium {{ $expired ? 'text-gray-500' : 'text-gray-800' }}">
                                                {{ $lock->locked_until->diffForHumans() }}
                                            </div>
                                            <div class="mt-0.5 text-[11px] text-gray-400">{{ $lock->locked_until->format('M j · g:i:s A') }}</div>
                                        @else
                                            <span class="text-xs text-gray-400">—</span>
                                        @endif
                                    </td>

                                    <td>
                                        <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-[11px] font-semibold {{ $expired ? 'border-gray-200 bg-gray-50 text-gray-600' : 'border-amber-200 bg-amber-50 text-amber-700' }}">
                                            {{ $expired ? 'Expired' : 'Blocking' }}
                                        </span>
                                    </td>

                                    <td class="text-right">
                                        <div class="flex items-center justify-end gap-1.5">
                                            <button type="button" wire:click="inspect({{ $lock->id }})" class="sessions-view-btn">Details</button>
                                            <button type="button"
                                                    wire:click="release({{ $lock->id }})"
                                                    wire:confirm="Release this hold?&#10;&#10;{{ $expired ? 'It has already lapsed, so this only tidies the row.' : 'The slot goes back on sale immediately, and whoever is on the checkout page may lose it.' }}"
                                                    class="inline-flex items-center rounded-lg border border-red-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-red-600 transition hover:border-red-500 hover:bg-red-600 hover:text-white">
                                                Release
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr wire:key="locks-empty">
                                    <td colspan="8">
                                        <div class="sessions-empty">
                                            <span class="mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-sidebar/10 text-sidebar">
                                                <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 10.5V6.75a4.5 4.5 0 119 0v3.75M3.75 21.75h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H3.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                                                </svg>
                                            </span>
                                            <p class="text-sm font-semibold text-gray-900">
                                                {{ $activeFilters !== [] ? 'No holds match your filters' : 'No slots are held right now' }}
                                            </p>
                                            <p class="mt-1 max-w-sm text-sm text-gray-500">
                                                {{ $activeFilters !== []
                                                    ? 'Try a different venue, listing or date — or clear the filters.'
                                                    : 'Holds appear here the moment someone opens a checkout, and clear themselves when it completes or lapses.' }}
                                            </p>
                                            @if ($activeFilters !== [])
                                                <button type="button" wire:click="clearFilters"
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

                @if ($locks->total() > 0)
                    <div class="flex flex-col gap-3 border-t border-gray-100 bg-gray-50/80 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-sm text-gray-600">
                            Showing <span class="font-semibold text-gray-900">{{ $locks->firstItem() ?? 0 }}–{{ $locks->lastItem() ?? 0 }}</span>
                            of <span class="font-semibold text-gray-900">{{ number_format($locks->total()) }}</span>
                            · {{ $recordsPerPage }} per page
                        </p>
                        @if ($locks->hasPages())
                            <div class="sessions-pagination">{{ $locks->links() }}</div>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </main>

    @if ($inspected)
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/50 p-4 sm:p-8"
             wire:click.self="closeInspector" wire:key="inspector-{{ $inspected->id }}">
            <div class="w-full max-w-2xl rounded-xl bg-white shadow-xl">
                <div class="flex items-start justify-between border-b border-gray-200 px-6 py-4">
                    <div>
                        <h3 class="text-base font-bold text-gray-900">Hold details</h3>
                        <p class="mt-0.5 text-xs text-gray-500">Lock #{{ $inspected->id }}</p>
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
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">Slot</p>
                            <p class="mt-1 text-sm font-semibold text-gray-900">{{ $slotLabel($inspected) }}</p>
                            <p class="text-xs text-gray-500">{{ $inspected->date?->format('l, M j, Y') ?? '—' }}</p>
                            <p class="mt-2 text-sm text-gray-800">{{ $inspected->listing?->name ?? 'Listing #'.$inspected->listing_id }}</p>
                            <p class="text-xs text-gray-500">{{ $inspected->listing?->venue?->name ?? '—' }}</p>
                            <p class="mt-1 text-xs text-gray-400">
                                Provider: {{ $inspected->listing?->venue?->owner?->name ?: ($inspected->listing?->venue?->owner?->email ?? '—') }}
                            </p>
                        </div>

                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">Held by</p>
                            @if ($inspected->user)
                                <p class="mt-1 text-sm font-semibold text-gray-900">{{ $inspected->user->name }}</p>
                                <p class="text-xs text-gray-500">{{ $inspected->user->email }}</p>
                                @if ($inspected->user->phone)
                                    <p class="text-xs text-gray-500">{{ $inspected->user->phone }}</p>
                                @endif
                            @else
                                <p class="mt-1 text-sm font-semibold text-gray-900">Guest</p>
                                <p class="text-xs text-gray-500">Not signed in — identified only by device.</p>
                            @endif
                            <p class="mt-2 text-xs text-gray-500">IP: <span class="font-mono">{{ $inspected->ip_address ?? '—' }}</span></p>
                            <p class="break-all text-[11px] text-gray-400">Session: <span class="font-mono">{{ $inspected->session_id }}</span></p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-4 border-t border-gray-100 pt-4 sm:grid-cols-2">
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">Taken</p>
                            <p class="mt-1 text-sm text-gray-800">{{ $inspected->created_at?->format('M j, Y · g:i:s A') ?? '—' }}</p>
                            <p class="text-xs text-gray-500">{{ $inspected->created_at?->diffForHumans() }}</p>
                        </div>
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">Expires</p>
                            <p class="mt-1 text-sm text-gray-800">{{ $inspected->locked_until?->format('M j, Y · g:i:s A') ?? '—' }}</p>
                            <p class="text-xs {{ $inspected->isExpired() ? 'text-gray-500' : 'text-amber-600 font-medium' }}">
                                {{ $inspected->isExpired() ? 'Already lapsed — blocking nothing.' : 'Still blocking this slot.' }}
                            </p>
                        </div>
                    </div>
                </div>

                <div class="flex flex-col gap-2 border-t border-gray-200 bg-gray-50 px-6 py-4 sm:flex-row sm:justify-end">
                    <button type="button"
                            wire:click="releaseAllForListing({{ $inspected->listing_id }})"
                            wire:confirm="Release every hold on this listing?&#10;&#10;Use this when a court looks stuck. Anyone mid-checkout on it loses their slot."
                            class="inline-flex items-center justify-center rounded-lg border border-red-300 bg-white px-4 py-2 text-sm font-semibold text-red-700 transition hover:bg-red-600 hover:text-white">
                        Release all holds on this listing
                    </button>
                    <button type="button"
                            wire:click="release({{ $inspected->id }})"
                            wire:confirm="Release this hold?"
                            class="inline-flex items-center justify-center rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-red-700">
                        Release this hold
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
