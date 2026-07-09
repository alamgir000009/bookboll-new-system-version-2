<div>
    <header class="border-b border-gray-200 bg-white">
        <div class="px-6 py-5 sm:px-8">
            <div>
                <h2 class="text-xl font-bold text-gray-800">{{ __('Profile') }}</h2>
                <p class="mt-0.5 text-sm text-gray-500">Manage your account details and security settings</p>
            </div>
        </div>
    </header>

    <main class="px-4 py-6 sm:px-8 sm:py-8">
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
                        <form wire:submit="updateProfile" class="flex flex-1 flex-col space-y-5">
                            <div class="space-y-4">
                                <div>
                                    <label for="name" class="block text-sm text-gray-700 font-medium">{{ __('Name') }}</label>
                                    <input id="name" type="text" wire:model="name" required autocomplete="name"
                                           class="input-brand mt-1.5 block w-full" />
                                    @error('name') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="email" class="block text-sm text-gray-700 font-medium">{{ __('Email') }}</label>
                                    <input id="email" type="email" wire:model="email" required autocomplete="username"
                                           class="input-brand mt-1.5 block w-full" />
                                    @error('email') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                                </div>
                            </div>
                            <div class="mt-auto flex items-center gap-3 border-t border-gray-100 pt-5">
                                <button type="submit" class="btn-brand !w-auto px-6 py-2.5" wire:loading.attr="disabled" wire:target="updateProfile">
                                    <span wire:loading.remove wire:target="updateProfile">{{ __('Save changes') }}</span>
                                    <span wire:loading wire:target="updateProfile">Saving...</span>
                                </button>
                                @if ($profileSaved)
                                    <p class="inline-flex items-center gap-1.5 text-sm font-medium text-green-600">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                        </svg>
                                        {{ $profileSaved }}
                                    </p>
                                @endif
                            </div>
                        </form>
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
                        <form wire:submit="updatePassword" class="flex flex-1 flex-col space-y-5">
                            <div class="space-y-4">
                                <div>
                                    <label for="current_password" class="block text-sm text-gray-700 font-medium">{{ __('Current Password') }}</label>
                                    <input id="current_password" type="password" wire:model="current_password" autocomplete="current-password"
                                           class="input-brand mt-1.5 block w-full" />
                                    @error('current_password') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="password" class="block text-sm text-gray-700 font-medium">{{ __('New Password') }}</label>
                                    <input id="password" type="password" wire:model="password" autocomplete="new-password"
                                           class="input-brand mt-1.5 block w-full" />
                                    @error('password') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="password_confirmation" class="block text-sm text-gray-700 font-medium">{{ __('Confirm Password') }}</label>
                                    <input id="password_confirmation" type="password" wire:model="password_confirmation" autocomplete="new-password"
                                           class="input-brand mt-1.5 block w-full" />
                                </div>
                            </div>
                            <p class="rounded-lg bg-gray-50 px-3 py-2.5 text-xs leading-relaxed text-gray-500">
                                Use at least 8 characters with a mix of letters, numbers, and symbols for a stronger password.
                            </p>
                            <div class="mt-auto flex items-center gap-3 border-t border-gray-100 pt-5">
                                <button type="submit" class="btn-brand !w-auto px-6 py-2.5" wire:loading.attr="disabled" wire:target="updatePassword">
                                    <span wire:loading.remove wire:target="updatePassword">{{ __('Update password') }}</span>
                                    <span wire:loading wire:target="updatePassword">Updating...</span>
                                </button>
                                @if ($passwordSaved)
                                    <p class="inline-flex items-center gap-1.5 text-sm font-medium text-green-600">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                        </svg>
                                        {{ $passwordSaved }}
                                    </p>
                                @endif
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>
