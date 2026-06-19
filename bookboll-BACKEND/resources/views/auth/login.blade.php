@extends('layouts.auth')

@section('content')
<div class="auth-page">
    <div class="auth-panel auth-panel--brand">
        <div class="auth-brand-content">
            <div class="auth-logo">
                <div class="auth-logo-mark">B</div>
                <span class="auth-logo-text">{{ config('app.name', 'Bookboll') }}</span>
            </div>

            <h1 class="auth-brand-title">Sports facilities,<br>managed with ease.</h1>
            <p class="auth-brand-subtitle">
                Sign in to the admin portal to manage bookings, venues, customers, and system operations across Qatar.
            </p>

            <ul class="auth-features">
                <li>
                    <span class="auth-feature-icon">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    </span>
                    Booking & calendar management
                </li>
                <li>
                    <span class="auth-feature-icon">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                    </span>
                    Venues & listings control
                </li>
                <li>
                    <span class="auth-feature-icon">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    </span>
                    Secure role-based access
                </li>
            </ul>
        </div>
    </div>

    <div class="auth-panel auth-panel--form">
        <div class="auth-card">
            <h2 class="auth-card-title">Welcome back</h2>
            <p class="auth-card-subtitle">Enter your credentials to access the dashboard.</p>

            <form method="POST" action="{{ route('login') }}">
                @csrf

                <div class="bb-form-group">
                    <label for="email" class="bb-form-label">{{ __('Email Address') }}</label>
                    <input
                        id="email"
                        type="email"
                        class="bb-form-input @error('email') is-invalid @enderror"
                        name="email"
                        value="{{ old('email') }}"
                        placeholder="you@example.com"
                        required
                        autocomplete="email"
                        autofocus
                    >
                    @error('email')
                        <span class="bb-form-error">{{ $message }}</span>
                    @enderror
                </div>

                <div class="bb-form-group">
                    <label for="password" class="bb-form-label">{{ __('Password') }}</label>
                    <input
                        id="password"
                        type="password"
                        class="bb-form-input @error('password') is-invalid @enderror"
                        name="password"
                        placeholder="••••••••"
                        required
                        autocomplete="current-password"
                    >
                    @error('password')
                        <span class="bb-form-error">{{ $message }}</span>
                    @enderror
                </div>

                <div class="bb-form-check">
                    <input type="checkbox" name="remember" id="remember" checked>
                    <label for="remember">{{ __('Remember Me') }}</label>
                </div>

                <button type="submit" class="bb-btn-primary">
                    {{ __('Sign in') }}
                </button>
            </form>
        </div>
    </div>
</div>
@endsection
