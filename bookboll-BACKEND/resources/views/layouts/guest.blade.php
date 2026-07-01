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
        <div class="flex min-h-dvh flex-col lg:grid lg:h-dvh lg:grid-cols-2 lg:overflow-hidden">
            {{-- Brand panel --}}
            <div class="login-brand-panel relative hidden lg:flex lg:h-full lg:min-h-0 flex-col justify-between p-10 xl:p-12 text-white">
                <div class="login-brand-glow -left-20 -top-20 h-72 w-72"></div>
                <div class="login-brand-glow bottom-10 right-0 h-56 w-56 bg-blue-300/20"></div>

                <div class="relative z-10 flex items-center gap-4">
                    <div class="rounded-2xl bg-white p-2 shadow-xl ring-1 ring-white/20">
                        <x-brand-logo class="h-12 w-12 rounded-xl" />
                    </div>
                    <div>
                        <span class="block text-2xl font-bold tracking-tight">{{ config('app.name', 'Bookboll') }}</span>
                        <span class="block text-sm font-medium text-white/70">Admin Portal</span>
                    </div>
                </div>

                <div class="relative z-10 max-w-md space-y-8">
                    <div class="space-y-4">
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-white/50">Secure access</p>
                        <h1 class="text-4xl font-bold leading-tight tracking-tight">
                            Manage your platform with confidence
                        </h1>
                        <p class="text-base leading-relaxed text-white/75">
                            Sign in to access system tools, logs, debugging, and backups from one secure dashboard.
                        </p>
                    </div>

                </div>

                <p class="relative z-10 text-sm text-white/45">
                    &copy; {{ date('Y') }} {{ config('app.name', 'Bookboll') }}. All rights reserved.
                </p>
            </div>

            {{-- Login form --}}
            <div class="relative flex min-h-0 flex-1 flex-col justify-center bg-surface px-6 py-8 sm:px-12 lg:h-full lg:overflow-y-auto lg:py-6">
                <div class="pointer-events-none absolute inset-0 overflow-hidden">
                    <div class="absolute -right-24 -top-24 h-64 w-64 rounded-full bg-sidebar/5 blur-3xl"></div>
                    <div class="absolute -bottom-24 -left-24 h-64 w-64 rounded-full bg-sidebar/5 blur-3xl"></div>
                </div>

                <div class="relative mx-auto w-full max-w-[420px]">
                    {{ $slot }}
                </div>
            </div>
        </div>

        @stack('scripts')
    </body>
</html>
