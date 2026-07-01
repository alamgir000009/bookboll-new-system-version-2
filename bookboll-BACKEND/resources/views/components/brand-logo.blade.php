@props(['class' => 'h-10 w-auto max-w-[180px]'])

<img src="{{ asset('bookboll-logo.jpeg') }}"
     alt="{{ config('app.name', 'Bookboll') }}"
     {{ $attributes->merge(['class' => "object-contain {$class}"]) }} />
