<x-guest-layout>
    <div class="mb-5 text-center lg:mb-4 lg:text-left">
        <div class="flex flex-col items-center lg:items-start">
            <div class="mb-3 flex items-center gap-3 lg:hidden">
                <div class="rounded-xl bg-white p-1.5 shadow-md ring-1 ring-gray-200/80">
                    <x-brand-logo class="h-10 w-10 rounded-lg" />
                </div>
                <span class="text-lg font-bold text-gray-900">{{ config('app.name', 'Bookboll') }}</span>
            </div>
            <h2 class="text-2xl font-bold tracking-tight text-gray-900">Welcome back</h2>
            <p class="mt-2 text-sm text-gray-500">Sign in to your admin account to continue</p>
        </div>
    </div>

    <x-auth-session-status class="mb-5 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800" :status="session('status')" />

    @if ($errors->has('email') && ! $errors->has('password') && ! $errors->has('cf-turnstile-response'))
        <div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            {{ $errors->first('email') }}
        </div>
    @endif

    <form method="POST" action="{{ route('login') }}" class="login-form-card space-y-5">
        @csrf

        <div>
            <label for="email" class="block text-sm font-semibold text-gray-700">Email address</label>
            <div class="login-input-wrap">
                <span class="login-input-icon">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />
                    </svg>
                </span>
                <input id="email"
                       type="email"
                       name="email"
                       value="{{ old('email') }}"
                       required
                       autofocus
                       autocomplete="username"
                       placeholder="admin@bookboll.com"
                       class="login-input" />
            </div>
            @if ($errors->has('email') && ($errors->has('password') || $errors->has('cf-turnstile-response')))
                <p class="mt-2 text-sm text-red-600">{{ $errors->first('email') }}</p>
            @endif
        </div>

        <div>
            <label for="password" class="block text-sm font-semibold text-gray-700">Password</label>
            <div class="login-input-wrap" x-data="{ show: false }">
                <span class="login-input-icon">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                    </svg>
                </span>
                <input id="password"
                       :type="show ? 'text' : 'password'"
                       name="password"
                       required
                       autocomplete="current-password"
                       placeholder="Enter your password"
                       class="login-input pr-11" />
                <button type="button"
                        @click="show = !show"
                        class="absolute inset-y-0 right-0 flex items-center pr-4 text-gray-400 transition hover:text-sidebar"
                        tabindex="-1"
                        aria-label="Toggle password visibility">
                    <svg x-show="!show" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                    <svg x-show="show" x-cloak class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" />
                    </svg>
                </button>
            </div>
            @if ($errors->has('password'))
                <p class="mt-2 text-sm text-red-600">{{ $errors->first('password') }}</p>
            @endif
        </div>

        <div class="flex items-center justify-between pt-1">
            <label for="remember_me" class="inline-flex cursor-pointer items-center">
                <input id="remember_me"
                       type="checkbox"
                       name="remember"
                       checked
                       class="h-4 w-4 rounded border-gray-300 text-sidebar shadow-sm focus:ring-sidebar/30">
                <span class="ms-2.5 text-sm text-gray-600">Remember me</span>
            </label>
        </div>

        @if (config('services.turnstile.site_key'))
            <div class="rounded-xl border border-gray-100 bg-gray-50/80 p-3">
                <div class="cf-turnstile flex justify-center" data-sitekey="{{ config('services.turnstile.site_key') }}" data-theme="light"></div>
                @if ($errors->has('cf-turnstile-response'))
                    <p class="mt-2 text-center text-sm text-red-600">{{ $errors->first('cf-turnstile-response') }}</p>
                @endif
            </div>
        @endif

        <button type="submit" class="login-submit-btn">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9" />
            </svg>
            Sign in to dashboard
        </button>
    </form>

    <p class="mt-5 text-center text-xs text-gray-400 lg:mt-4 lg:text-left">
        Protected area. Authorized administrators only.
    </p>

    @if (config('services.turnstile.site_key'))
        @push('scripts')
            <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
        @endpush
    @endif
</x-guest-layout>
