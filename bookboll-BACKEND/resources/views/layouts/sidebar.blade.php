@php
    $initials = collect(explode(' ', Auth::user()->name ?? 'A'))
        ->map(fn ($part) => strtoupper(substr($part, 0, 1)))
        ->take(2)
        ->join('');

    $telescopeUrl = url('/'.ltrim(config('telescope.path'), '/'));
    $roleLabel = Auth::user()->hasRole('super_admin') ? 'Super Admin' : 'Admin';

    $mainMenu = [
        [
            'label' => 'Dashboard',
            'href' => route('dashboard'),
            'active' => request()->routeIs('dashboard'),
            'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />',
        ],
        [
            'label' => 'Profile',
            'href' => route('profile.edit'),
            'active' => request()->routeIs('profile.*'),
            'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />',
        ],
        [
            'label' => 'Activities',
            'href' => route('activities.index'),
            'active' => request()->routeIs('activities.*'),
            'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />',
        ],
    ];

    $systemTools = [
        [
            'label' => 'Backups',
            'href' => route('backups.index'),
            'active' => request()->routeIs('backups.*'),
            'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />',
        ],
        [
            'label' => 'Log Viewer',
            'href' => route('log-viewer.index'),
            'active' => request()->routeIs('log-viewer.*'),
            'navigate' => false,
            'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />',
        ],
        [
            'label' => 'Telescope',
            'href' => $telescopeUrl,
            'active' => request()->is(trim(config('telescope.path'), '/').'*'),
            'navigate' => false,
            'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />',
        ],
    ];
@endphp

<div x-show="sidebarOpen"
     x-transition:enter="transition-opacity ease-linear duration-200"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition-opacity ease-linear duration-200"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     @click="sidebarOpen = false"
     class="fixed inset-0 z-40 bg-black/50 lg:hidden"
     style="display: none;"></div>

<aside :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
       class="sidebar-panel fixed inset-y-0 left-0 z-50 flex w-64 flex-col transition-transform duration-200 ease-in-out lg:translate-x-0">

    <div class="flex items-center gap-3 border-b border-white/10 px-5 py-5">
        <x-brand-logo class="h-10 w-10 shrink-0 rounded-lg ring-1 ring-white/20" />
        <div class="min-w-0 leading-tight">
            <span class="block text-base font-bold text-white tracking-tight">{{ config('app.name', 'Bookboll') }}</span>
            <span class="block text-xs font-medium text-white/70">Admin</span>
        </div>
    </div>

    <nav class="flex-1 overflow-y-auto py-2">
        <p class="sidebar-section">Main Menu</p>
        <ul class="space-y-0.5">
            @foreach ($mainMenu as $item)
                <li>
                    <a href="{{ $item['href'] }}"
                       @if ($item['navigate'] ?? true) wire:navigate @endif
                       wire:current.exact="sidebar-link-active"
                       @class(['sidebar-link', 'sidebar-link-active' => $item['active']])>
                        <svg class="h-5 w-5 shrink-0 opacity-90" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">{!! $item['icon'] !!}</svg>
                        <span>{{ $item['label'] }}</span>
                    </a>
                </li>
            @endforeach
        </ul>

        <div class="mx-5 my-4 border-t border-white/15"></div>

        <p class="sidebar-section">System Tools</p>
        <ul class="space-y-0.5">
            @foreach ($systemTools as $item)
                <li>
                    <a href="{{ $item['href'] }}"
                       @if ($item['navigate'] ?? true) wire:navigate @endif
                       wire:current.exact="sidebar-link-active"
                       @class(['sidebar-link', 'sidebar-link-active' => $item['active']])>
                        <svg class="h-5 w-5 shrink-0 opacity-90" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">{!! $item['icon'] !!}</svg>
                        <span>{{ $item['label'] }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </nav>

    <div class="border-t border-white/10 p-4">
        <div class="flex items-center gap-3 rounded-lg bg-white/10 px-3 py-3">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-white/20 text-xs font-bold text-white">
                {{ $initials }}
            </span>
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-semibold text-white">{{ Auth::user()->name }}</p>
                <p class="text-xs text-white/60">{{ $roleLabel }}</p>
            </div>
        </div>

        <form method="POST" action="{{ route('logout') }}" class="mt-2">
            @csrf
            <button type="submit" class="sidebar-link w-full text-left">
                <svg class="h-5 w-5 shrink-0 opacity-90" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9" />
                </svg>
                <span>Log Out</span>
            </button>
        </form>
    </div>
</aside>
