<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Bookboll') }} — Admin Login</title>

        <link rel="icon" type="image/jpeg" href="{{ asset('bookboll-logo.jpeg') }}">

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-gray-900 antialiased">
        <div class="min-h-screen lg:grid lg:grid-cols-2">
            <div class="sidebar-panel relative hidden lg:flex flex-col justify-between p-10 text-white">
                <div class="flex items-center gap-4">
                    <x-brand-logo class="h-14 w-14 rounded-xl shadow-lg ring-2 ring-white/20" />
                    <div>
                        <span class="block text-xl font-bold tracking-tight">{{ config('app.name', 'Bookboll') }}</span>
                        <span class="block text-sm text-white/70">Admin Portal</span>
                    </div>
                </div>

                <div class="space-y-4 max-w-sm">
                    <p class="text-xs font-semibold uppercase tracking-widest text-white/50">Admin Access</p>
                    <h1 class="text-3xl font-bold leading-snug">
                        Sign in to manage your platform
                    </h1>
                    <p class="text-white/75 leading-relaxed">
                        Access logs, debugging tools, and system monitoring from one secure dashboard.
                    </p>
                </div>

                <p class="text-sm text-white/50">
                    &copy; {{ date('Y') }} {{ config('app.name', 'Bookboll') }}
                </p>
            </div>

            <div class="flex min-h-screen flex-col justify-center bg-surface px-6 py-12 sm:px-12">
                <div class="mx-auto w-full max-w-md">
                    <div class="mb-8 flex flex-col items-center lg:items-start">
                        <div class="mb-5 flex items-center gap-3 lg:hidden">
                            <x-brand-logo class="h-11 w-11 rounded-lg" />
                            <span class="text-lg font-bold text-gray-800">{{ config('app.name', 'Bookboll') }}</span>
                        </div>
                        <h2 class="text-2xl font-bold text-gray-900">Welcome back</h2>
                        <p class="mt-1 text-sm text-gray-500">Enter your credentials to continue</p>
                    </div>

                    <div class="content-card !p-8">
                        {{ $slot }}
                    </div>
                </div>
            </div>
        </div>
    </body>
</html>
