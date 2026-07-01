<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center px-4 py-2 bg-sidebar border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-sidebar-dark focus:bg-sidebar-dark active:bg-sidebar-dark focus:outline-none focus:ring-2 focus:ring-sidebar focus:ring-offset-2 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
