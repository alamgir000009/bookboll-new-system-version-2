@props(['class' => 'h-9 w-9'])

<img src="{{ asset('bookboll-logo.jpeg') }}"
     alt="{{ config('app.name', 'Bookboll') }}"
     {{ $attributes->merge(['class' => "shrink-0 rounded-lg object-contain {$class}"]) }} />
