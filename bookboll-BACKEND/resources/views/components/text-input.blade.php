@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'border-gray-300 focus:border-sidebar focus:ring-sidebar rounded-md shadow-sm']) }}>
