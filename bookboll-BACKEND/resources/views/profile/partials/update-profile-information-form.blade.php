<section class="flex h-full flex-col">
    <form method="post" action="{{ route('profile.update') }}" class="flex flex-1 flex-col space-y-5">
        @csrf
        @method('patch')

        <div class="space-y-4">
            <div>
                <x-input-label for="name" :value="__('Name')" class="text-gray-700 font-medium" />
                <x-text-input id="name" name="name" type="text" class="input-brand mt-1.5 block w-full" :value="old('name', $user->name)" required autofocus autocomplete="name" />
                <x-input-error class="mt-2" :messages="$errors->get('name')" />
            </div>

            <div>
                <x-input-label for="email" :value="__('Email')" class="text-gray-700 font-medium" />
                <x-text-input id="email" name="email" type="email" class="input-brand mt-1.5 block w-full" :value="old('email', $user->email)" required autocomplete="username" />
                <x-input-error class="mt-2" :messages="$errors->get('email')" />
            </div>
        </div>

        <div class="mt-auto flex items-center gap-3 border-t border-gray-100 pt-5">
            <button type="submit" class="btn-brand !w-auto px-6 py-2.5">
                {{ __('Save changes') }}
            </button>

            @if (session('status') === 'profile-updated')
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
                    {{ __('Saved') }}
                </p>
            @endif
        </div>
    </form>
</section>
