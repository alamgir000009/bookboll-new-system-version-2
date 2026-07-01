<section class="flex h-full flex-col">
    <form method="post" action="{{ route('password.update') }}" class="flex flex-1 flex-col space-y-5">
        @csrf
        @method('put')

        <div class="space-y-4">
            <div>
                <x-input-label for="update_password_current_password" :value="__('Current Password')" class="text-gray-700 font-medium" />
                <x-text-input id="update_password_current_password" name="current_password" type="password" class="input-brand mt-1.5 block w-full" autocomplete="current-password" />
                <x-input-error :messages="$errors->updatePassword->get('current_password')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="update_password_password" :value="__('New Password')" class="text-gray-700 font-medium" />
                <x-text-input id="update_password_password" name="password" type="password" class="input-brand mt-1.5 block w-full" autocomplete="new-password" />
                <x-input-error :messages="$errors->updatePassword->get('password')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="update_password_password_confirmation" :value="__('Confirm Password')" class="text-gray-700 font-medium" />
                <x-text-input id="update_password_password_confirmation" name="password_confirmation" type="password" class="input-brand mt-1.5 block w-full" autocomplete="new-password" />
                <x-input-error :messages="$errors->updatePassword->get('password_confirmation')" class="mt-2" />
            </div>
        </div>

        <p class="rounded-lg bg-gray-50 px-3 py-2.5 text-xs leading-relaxed text-gray-500">
            Use at least 8 characters with a mix of letters, numbers, and symbols for a stronger password.
        </p>

        <div class="mt-auto flex items-center gap-3 border-t border-gray-100 pt-5">
            <button type="submit" class="btn-brand !w-auto px-6 py-2.5">
                {{ __('Update password') }}
            </button>

            @if (session('status') === 'password-updated')
                <p
                    x-data="{ show: true }"
                    x-show="show"
                    x-transition
                    x-init="setTimeout(() => show = false, 2500)"
                    class="inline-flex items-center gap-1.5 text-sm font-medium text-green-600"
                >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                    </svg>
                    {{ __('Updated') }}
                </p>
            @endif
        </div>
    </form>
</section>
