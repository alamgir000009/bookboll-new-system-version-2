<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $title ?? config('app.name', 'Bookboll') }} — Admin</title>

        <link rel="icon" type="image/jpeg" href="{{ asset('bookboll-logo.jpeg') }}">

        {{-- Runs before the first paint so a collapsed sidebar never flashes open.
             The attribute is what the CSS keys off; Alpine only flips it later. --}}
        <script>
            try {
                if (localStorage.getItem('bookboll.sidebar') === 'collapsed') {
                    document.documentElement.setAttribute('data-sidebar', 'collapsed');
                }
            } catch (e) {}
        </script>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css'])
        @livewireStyles
    </head>
    <body class="font-sans antialiased">
        <div class="min-h-screen bg-surface"
             x-data="{
                 sidebarOpen: false,
                 collapsed: false,
                 init() {
                     // The pre-paint script in <head> is the source of truth for
                     // layout; this only mirrors it so the button's label and
                     // aria-expanded start out correct.
                     this.collapsed = document.documentElement.getAttribute('data-sidebar') === 'collapsed';
                 },
                 toggleSidebar() {
                     this.collapsed = ! this.collapsed;
                     document.documentElement.setAttribute('data-sidebar', this.collapsed ? 'collapsed' : 'expanded');
                     try {
                         localStorage.setItem('bookboll.sidebar', this.collapsed ? 'collapsed' : 'expanded');
                     } catch (e) {}
                 },
             }">
            @include('layouts.sidebar')

            <div class="admin-content lg:pl-64">
                <div class="sticky top-0 z-30 flex items-center gap-4 border-b border-gray-200 bg-white px-4 py-3 lg:hidden">
                    <button @click="sidebarOpen = true" class="rounded-lg p-2 text-gray-600 hover:bg-gray-100">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                    </button>
                    <div class="flex items-center gap-2.5">
                        <x-brand-icon class="h-9 w-9" />
                        <span class="text-sm font-bold text-gray-800">{{ config('app.name', 'Bookboll') }}</span>
                    </div>
                </div>

                {{ $slot }}
            </div>
        </div>

        @livewireScripts
    </body>
</html>
