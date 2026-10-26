@php
    use Illuminate\Support\Str;

    $bytes = function (int $b): string {
        if ($b >= 1073741824) return number_format($b / 1073741824, 2).' GB';
        if ($b >= 1048576) return number_format($b / 1048576, 1).' MB';
        if ($b >= 1024) return number_format($b / 1024, 1).' KB';
        return $b.' B';
    };

    $money = fn (float $v) => number_format($v, 0);

    // Percentage change against yesterday. Null when yesterday was zero —
    // "up 100%" from nothing is noise, not information.
    $delta = function ($today, $yesterday): ?float {
        if (! $yesterday) return null;
        return (($today - $yesterday) / $yesterday) * 100;
    };

    $peak = max(1, collect($trend)->max('count'));

    $queueLabel = $attention['queued_jobs'] === 0 ? 'Empty' : number_format($attention['queued_jobs']).' waiting';
@endphp

<div>
    <header class="border-b border-gray-200 bg-white">
        <div class="px-6 py-5 sm:px-8">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-xl font-bold text-gray-800">Dashboard</h2>
                    <p class="mt-0.5 text-sm text-gray-500">
                        Welcome back, {{ Auth::user()->name }} &middot; {{ now()->format('l, j F Y') }}
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <span wire:loading class="text-xs text-sidebar">Refreshing...</span>
                    <button type="button"
                            wire:click="refreshSystem"
                            class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs font-medium text-gray-700 transition hover:bg-gray-50">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992V4.356M3.009 19.644v-4.992h4.992M4.031 9.348a8.25 8.25 0 0113.803-3.7l3.181 3.182m-17.015 6.81l3.182 3.182a8.25 8.25 0 0013.803-3.7" />
                        </svg>
                        Refresh system health
                    </button>
                </div>
            </div>
        </div>
    </header>

    <main class="px-4 py-6 sm:px-8 sm:py-8">
        <div class="space-y-6">
            @if ($statusMessage)
                <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">{{ $statusMessage }}</div>
            @endif

            {{-- Anything actually wrong goes first, before the numbers. --}}
            @if ($warnings !== [])
                <div class="space-y-3">
                    @foreach ($warnings as $warning)
                        <div @class([
                            'rounded-lg border px-4 py-3',
                            'border-red-200 bg-red-50' => $warning['level'] === 'danger',
                            'border-amber-200 bg-amber-50' => $warning['level'] !== 'danger',
                        ])>
                            <p @class([
                                'text-sm font-semibold',
                                'text-red-800' => $warning['level'] === 'danger',
                                'text-amber-900' => $warning['level'] !== 'danger',
                            ])>{{ $warning['title'] }}</p>
                            <p @class([
                                'mt-0.5 text-xs',
                                'text-red-700' => $warning['level'] === 'danger',
                                'text-amber-800' => $warning['level'] !== 'danger',
                            ])>{{ $warning['detail'] }}</p>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                    Nothing needs attention — drivers, backups, queue and Telescope all look healthy.
                </div>
            @endif

            {{-- Trade --}}
            <div>
                <h3 class="mb-3 text-xs font-semibold uppercase tracking-wider text-gray-400">Today</h3>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ([
                        ['label' => 'Bookings', 'value' => number_format($pulse['bookings_today']), 'change' => $delta($pulse['bookings_today'], $pulse['bookings_yesterday']), 'foot' => number_format($pulse['bookings_week']).' in the last 7 days'],
                        ['label' => 'Booking value', 'value' => $money($pulse['value_today']), 'change' => $delta($pulse['value_today'], $pulse['value_yesterday']), 'foot' => $money($pulse['value_week']).' in the last 7 days'],
                        ['label' => 'New sign-ups', 'value' => number_format($pulse['signups_today']), 'change' => $delta($pulse['signups_today'], $pulse['signups_yesterday']), 'foot' => number_format($pulse['signups_week']).' in the last 7 days'],
                        ['label' => 'Cancelled today', 'value' => number_format($pulse['cancelled_today']), 'change' => null, 'foot' => 'Excluded from booking value'],
                    ] as $tile)
                        <div class="content-card">
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500">{{ $tile['label'] }}</p>
                            <div class="mt-1 flex items-baseline gap-2">
                                <p class="text-3xl font-bold tabular-nums text-gray-900">{{ $tile['value'] }}</p>
                                @if ($tile['change'] !== null)
                                    <span @class([
                                        'text-xs font-semibold',
                                        'text-green-600' => $tile['change'] > 0,
                                        'text-red-600' => $tile['change'] < 0,
                                        'text-gray-400' => $tile['change'] == 0,
                                    ])>
                                        {{ $tile['change'] > 0 ? '▲' : ($tile['change'] < 0 ? '▼' : '—') }}
                                        {{ number_format(abs($tile['change']), 0) }}%
                                    </span>
                                @endif
                            </div>
                            <p class="mt-1 text-xs text-gray-500">{{ $tile['foot'] }}</p>
                            @if ($tile['change'] !== null)
                                <p class="text-[11px] text-gray-400">vs yesterday</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- 14-day trend, drawn with plain divs: no chart library, no CDN. --}}
            <div class="content-card">
                <div class="mb-4 flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900">Bookings, last {{ count($trend) }} days</h3>
                        <p class="mt-0.5 text-xs text-gray-500">Hover a bar for the day's count and value.</p>
                    </div>
                    <span class="text-xs text-gray-400">Peak {{ number_format($peak) }}/day</span>
                </div>

                <div class="flex h-40 items-end gap-1.5 sm:gap-2">
                    @foreach ($trend as $day)
                        @php $height = $day['count'] === 0 ? 2 : max(6, (int) round(($day['count'] / $peak) * 100)); @endphp
                        <div class="group flex flex-1 flex-col items-center justify-end gap-1"
                             title="{{ $day['label'] }} — {{ number_format($day['count']) }} booking(s), value {{ $money($day['value']) }}">
                            <span class="text-[10px] font-semibold tabular-nums text-gray-400 opacity-0 transition group-hover:opacity-100">
                                {{ $day['count'] }}
                            </span>
                            <div class="w-full rounded-t transition-colors {{ $day['count'] === 0 ? 'bg-gray-200' : 'bg-sidebar/70 group-hover:bg-sidebar' }}"
                                 style="height: {{ $height }}%"></div>
                            <span class="whitespace-nowrap text-[10px] text-gray-400">{{ $day['label'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Waiting on a human --}}
            <div>
                <h3 class="mb-3 text-xs font-semibold uppercase tracking-wider text-gray-400">Needs attention</h3>
                <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    @foreach ([
                        ['label' => 'Accounts pending approval', 'value' => $attention['pending_users'], 'href' => null],
                        ['label' => 'Listings awaiting approval', 'value' => $attention['pending_listings'], 'href' => null],
                        ['label' => 'Withdrawals pending', 'value' => $attention['pending_withdrawals'], 'href' => null],
                        ['label' => 'Slots held right now', 'value' => $attention['blocking_locks'], 'href' => route('slot-locks.index')],
                    ] as $item)
                        @if ($item['href'])
                            <a href="{{ $item['href'] }}" wire:navigate class="content-card transition hover:border-sidebar/40">
                        @else
                            <div class="content-card">
                        @endif
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500">{{ $item['label'] }}</p>
                            <p @class([
                                'mt-1 text-2xl font-bold tabular-nums',
                                'text-amber-600' => $item['value'] > 0,
                                'text-gray-900' => $item['value'] === 0,
                            ])>{{ number_format($item['value']) }}</p>
                        @if ($item['href'])
                            </a>
                        @else
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>

            {{-- The installation itself --}}
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <div class="content-card">
                    <h3 class="text-sm font-semibold text-gray-900">System health</h3>
                    <p class="mt-0.5 text-xs text-gray-500">
                        Cached for 10 minutes · last read {{ \Illuminate\Support\Carbon::parse($system['generated_at'])->diffForHumans() }}
                    </p>

                    <dl class="mt-4 space-y-3 text-sm">
                        @foreach ([
                            ['Cache driver', $system['cache_driver'], $system['cache_driver'] === 'database'],
                            ['Session driver', $system['session_driver'], $system['session_driver'] === 'database'],
                            ['Queue driver', $system['queue_driver'], false],
                            ['Queue depth', $queueLabel, $attention['queued_jobs'] > 50],
                            ['Failed jobs', number_format($attention['failed_jobs']), $attention['failed_jobs'] > 0],
                        ] as [$label, $value, $bad])
                            <div class="flex items-center justify-between gap-3">
                                <dt class="text-gray-600">{{ $label }}</dt>
                                <dd @class(['font-mono text-xs font-semibold', 'text-red-600' => $bad, 'text-gray-900' => ! $bad])>{{ $value }}</dd>
                            </div>
                        @endforeach

                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-gray-600">Telescope</dt>
                            <dd class="text-right">
                                @if ($system['telescope_rows'] === null)
                                    <span class="font-mono text-xs text-gray-400">not installed</span>
                                @else
                                    <span @class(['font-mono text-xs font-semibold', 'text-red-600' => $system['telescope_rows'] > 250000, 'text-gray-900' => $system['telescope_rows'] <= 250000])>
                                        {{ number_format($system['telescope_rows']) }} rows
                                    </span>
                                    <span class="block text-[11px] text-gray-400">{{ $system['telescope_enabled'] ? 'recording' : 'disabled' }}</span>
                                @endif
                            </dd>
                        </div>

                        <div class="flex items-center justify-between gap-3 border-t border-gray-100 pt-3">
                            <dt class="text-gray-600">Last backup</dt>
                            <dd class="text-right">
                                @if ($attention['backup'] === null)
                                    <span class="font-mono text-xs font-semibold text-red-600">none</span>
                                @else
                                    <span class="font-mono text-xs font-semibold text-gray-900">
                                        {{ $attention['backup']['age_hours'] < 1 ? 'just now' : (int) $attention['backup']['age_hours'].'h ago' }}
                                    </span>
                                    <span class="block text-[11px] text-gray-400">{{ $bytes($attention['backup']['bytes']) }}</span>
                                @endif
                            </dd>
                        </div>
                    </dl>
                </div>

                <div class="content-card">
                    <div class="flex items-baseline justify-between">
                        <h3 class="text-sm font-semibold text-gray-900">Largest tables</h3>
                        <span class="text-xs text-gray-500">{{ $bytes($system['total_bytes']) }} total</span>
                    </div>
                    <p class="mt-0.5 text-xs text-gray-500">
                        {{ $system['database'] }} · row counts are InnoDB estimates
                    </p>

                    <div class="mt-4 space-y-2.5">
                        @php $largest = max(1, collect($system['tables'])->max('bytes')); @endphp
                        @foreach ($system['tables'] as $table)
                            <div>
                                <div class="flex items-baseline justify-between gap-3 text-xs">
                                    <span class="truncate font-mono text-gray-700">{{ $table['name'] }}</span>
                                    <span class="shrink-0 tabular-nums text-gray-500">
                                        {{ $bytes($table['bytes']) }}
                                        @if ($table['rows'] > 0)
                                            · ~{{ number_format($table['rows']) }} rows
                                        @endif
                                    </span>
                                </div>
                                <div class="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-gray-100">
                                    <div class="h-full rounded-full {{ Str::startsWith($table['name'], 'telescope') ? 'bg-red-400' : 'bg-sidebar/60' }}"
                                         style="width: {{ max(2, (int) round(($table['bytes'] / $largest) * 100)) }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Quick access --}}
            <div>
                <h3 class="mb-3 text-xs font-semibold uppercase tracking-wider text-gray-400">Quick Access</h3>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    @php
                        $links = [
                            ['label' => 'Slot Locks', 'href' => route('slot-locks.index'), 'navigate' => true, 'desc' => 'See and release held slots'],
                            ['label' => 'Active Sessions', 'href' => route('sessions.index'), 'navigate' => true, 'desc' => 'Sign devices out of the apps'],
                            ['label' => 'Settings', 'href' => route('platform-settings.index'), 'navigate' => true, 'desc' => 'Branding, booking rules, policies'],
                            ['label' => 'Activities', 'href' => route('activities.index'), 'navigate' => true, 'desc' => 'Full system activity log'],
                            ['label' => 'Backups', 'href' => route('backups.index'), 'navigate' => true, 'desc' => 'Run, download & delete backups'],
                            ['label' => 'Log Viewer', 'href' => route('log-viewer.index'), 'navigate' => false, 'desc' => 'Application logs and errors'],
                        ];

                        if (config('telescope.enabled')) {
                            $links[] = ['label' => 'Telescope', 'href' => url('/'.ltrim(config('telescope.path'), '/')), 'navigate' => false, 'desc' => 'Requests, queries, jobs & mail'];
                        }
                    @endphp

                    @foreach ($links as $link)
                        <a href="{{ $link['href'] }}" @if ($link['navigate']) wire:navigate @endif
                           class="content-card group transition-colors hover:border-sidebar/40">
                            <h4 class="font-semibold text-gray-900 transition-colors group-hover:text-sidebar">{{ $link['label'] }}</h4>
                            <p class="mt-0.5 text-sm text-gray-500">{{ $link['desc'] }}</p>
                        </a>
                    @endforeach
                </div>
            </div>
        </div>
    </main>
</div>
