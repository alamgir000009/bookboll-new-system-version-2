@extends('layouts.app')

@section('content')
    @php
        $hour = now()->hour;
        $greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
        $roles = Auth::user()->getRoleNames();
    @endphp

    @if (session('status'))
        <div class="dashboard-alert dashboard-alert--success" role="alert">
            {{ session('status') }}
        </div>
    @endif

    <section class="dashboard-hero">
        <p class="dashboard-hero-greeting">{{ $greeting }},</p>
        <h1 class="dashboard-hero-title">{{ Auth::user()->name }}</h1>
        <p class="dashboard-hero-subtitle">
            You're signed in to the {{ config('app.name', 'Bookboll') }} admin portal.
        </p>

        @if ($roles->isNotEmpty())
            <div class="dashboard-badges">
                @foreach ($roles as $role)
                    <span class="dashboard-badge">{{ str_replace('_', ' ', ucfirst($role)) }}</span>
                @endforeach
            </div>
        @endif
    </section>

    <div class="dashboard-grid">
        <a href="{{ route('logs-000009') }}" class="dashboard-card">
            <div class="dashboard-card-icon dashboard-card-icon--blue">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                    <line x1="16" y1="13" x2="8" y2="13"/>
                    <line x1="16" y1="17" x2="8" y2="17"/>
                    <polyline points="10 9 9 9 8 9"/>
                </svg>
            </div>
            <h2 class="dashboard-card-title">System Logs</h2>
            <p class="dashboard-card-desc">View application logs, errors, and debug output for troubleshooting.</p>
        </a>

        <div class="dashboard-card dashboard-card--static">
            <div class="dashboard-card-icon dashboard-card-icon--slate">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                    <circle cx="12" cy="7" r="4"/>
                </svg>
            </div>
            <h2 class="dashboard-card-title">Account</h2>
            <p class="dashboard-card-desc">
                {{ Auth::user()->email }}
                @if (Auth::user()->phone)
                    <br>{{ Auth::user()->phone }}
                @endif
            </p>
        </div>

        <a href="{{ route('logout-000009') }}" class="dashboard-card">
            <div class="dashboard-card-icon dashboard-card-icon--red">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                    <polyline points="16 17 21 12 16 7"/>
                    <line x1="21" y1="12" x2="9" y2="12"/>
                </svg>
            </div>
            <h2 class="dashboard-card-title">Sign out</h2>
            <p class="dashboard-card-desc">End your session and return to the login page.</p>
        </a>
    </div>
@endsection
