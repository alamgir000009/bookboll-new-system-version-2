<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Bookboll') }} Admin</title>
    <link rel="dns-prefetch" href="//fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700,800" rel="stylesheet">
    @vite(['resources/sass/app.scss', 'resources/js/app.js'])
    <style>
        body { font-family: 'Plus Jakarta Sans', 'Nunito', sans-serif; }
    </style>
</head>
<body class="bb-admin-body">
    <header class="bb-admin-nav">
        <div class="bb-admin-nav-inner">
            <a href="{{ url('/home') }}" class="bb-admin-brand">
                <span class="bb-admin-brand-mark">B</span>
                <span class="bb-admin-brand-text">{{ config('app.name', 'Bookboll') }}</span>
            </a>

            @auth
                <div class="bb-admin-user">
                    <div class="text-end">
                        <div class="bb-admin-user-name">{{ Auth::user()->name }}</div>
                        <div class="bb-admin-user-role">{{ Auth::user()->getRoleNames()->first() ?? 'Admin' }}</div>
                    </div>
                    <div class="bb-admin-avatar">
                        {{ strtoupper(substr(Auth::user()->name, 0, 1)) }}
                    </div>
                </div>
            @endauth
        </div>
    </header>

    <main class="bb-admin-main">
        @yield('content')
    </main>

    <footer class="bb-admin-footer">
        &copy; {{ date('Y') }} {{ config('app.name', 'Bookboll') }}. Admin portal.
    </footer>
</body>
</html>
