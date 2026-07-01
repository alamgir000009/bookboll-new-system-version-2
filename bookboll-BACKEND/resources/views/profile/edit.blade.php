<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="text-xl font-bold text-gray-800">{{ __('Profile') }}</h2>
            <p class="mt-0.5 text-sm text-gray-500">Manage your account details and security settings</p>
        </div>
    </x-slot>

    <div class="mx-auto max-w-6xl">
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2 lg:items-start">
            <div class="content-card flex h-full flex-col !p-0 overflow-hidden">
                <div class="flex items-start gap-3 border-b border-gray-100 bg-gradient-to-r from-sidebar/[0.07] to-transparent px-6 py-5">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-sidebar/10 text-sidebar">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                        </svg>
                    </span>
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">Profile Information</h3>
                        <p class="mt-0.5 text-sm text-gray-500">Update your name and email address</p>
                    </div>
                </div>
                <div class="flex flex-1 flex-col px-6 py-6">
                    @include('profile.partials.update-profile-information-form')
                </div>
            </div>

            <div class="content-card flex h-full flex-col !p-0 overflow-hidden">
                <div class="flex items-start gap-3 border-b border-gray-100 bg-gradient-to-r from-sidebar/[0.07] to-transparent px-6 py-5">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-sidebar/10 text-sidebar">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                        </svg>
                    </span>
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">Update Password</h3>
                        <p class="mt-0.5 text-sm text-gray-500">Keep your account secure with a strong password</p>
                    </div>
                </div>
                <div class="flex flex-1 flex-col px-6 py-6">
                    @include('profile.partials.update-password-form')
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
