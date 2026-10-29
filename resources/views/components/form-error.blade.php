@props(['name'])
@php
    $errors = $errors ?? view()->shared('errors', new \Illuminate\Support\ViewErrorBag);
@endphp
@error($name)
    <p {{ $attributes->merge(['class' => 'mt-1 text-xs text-error']) }} role="alert">{{ $message }}</p>
@enderror
