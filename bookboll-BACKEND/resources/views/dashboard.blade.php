<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-bold text-gray-800">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    <div class="space-y-6">
        @if (session('status'))
            <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                {{ session('status') }}
            </div>
        @endif

        <div class="content-card">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <p class="text-sm font-medium text-sidebar">Welcome back,</p>
                    <h3 class="mt-1 text-2xl font-bold text-gray-900">{{ Auth::user()->name }}</h3>
                    <p class="mt-1 text-sm text-gray-500">{{ Auth::user()->getRoleNames()->first() ?? 'Admin' }} &middot; {{ Auth::user()->email }}</p>
                </div>
                <x-brand-logo />
            </div>
        </div>

        <div>
            <h3 class="mb-3 text-xs font-semibold uppercase tracking-wider text-gray-400">Quick Access</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <a href="{{ route('activities.index') }}" class="content-card group flex items-start gap-4 hover:border-sidebar/40 transition-colors">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-sidebar/10 text-sidebar">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </span>
                    <div class="min-w-0">
                        <h4 class="font-semibold text-gray-900 group-hover:text-sidebar transition-colors">Activities</h4>
                        <p class="mt-0.5 text-sm text-gray-500">View system activity log &amp; changes</p>
                    </div>
                </a>

                <a href="{{ route('logs.viewer') }}" class="content-card group flex items-start gap-4 hover:border-sidebar/40 transition-colors">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-sidebar/10 text-sidebar">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                    </span>
                    <div class="min-w-0">
                        <h4 class="font-semibold text-gray-900 group-hover:text-sidebar transition-colors">Log Viewer</h4>
                        <p class="mt-0.5 text-sm text-gray-500">Browse application logs and errors</p>
                    </div>
                </a>

                <a href="{{ url('/'.ltrim(config('telescope.path'), '/')) }}" class="content-card group flex items-start gap-4 hover:border-sidebar/40 transition-colors">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-sidebar/10 text-sidebar">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                    </span>
                    <div class="min-w-0">
                        <h4 class="font-semibold text-gray-900 group-hover:text-sidebar transition-colors">Telescope</h4>
                        <p class="mt-0.5 text-sm text-gray-500">Debug requests, queries, jobs &amp; mail</p>
                    </div>
                </a>

                <a href="{{ route('backups.index') }}" class="content-card group flex items-start gap-4 hover:border-sidebar/40 transition-colors">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-sidebar/10 text-sidebar">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                        </svg>
                    </span>
                    <div class="min-w-0">
                        <h4 class="font-semibold text-gray-900 group-hover:text-sidebar transition-colors">Backups</h4>
                        <p class="mt-0.5 text-sm text-gray-500">View, download &amp; delete database backups</p>
                    </div>
                </a>
            </div>
        </div>
    </div>
</x-app-layout>
