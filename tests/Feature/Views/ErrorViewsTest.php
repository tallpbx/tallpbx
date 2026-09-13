<?php

declare(strict_types=1);

it('renders the 500 view with a request id', function (): void {
    $html = view('errors.500', ['requestId' => 'req_aabbccddeeff0011'])->render();

    expect($html)
        ->toContain('Something went wrong.')
        ->toContain('req_aabbccddeeff0011');
});

it('renders the 500 view without a request id', function (): void {
    $html = view('errors.500', ['requestId' => null])->render();

    expect($html)->toContain('Something went wrong.');
});

it('renders the 503 view', function (): void {
    $html = view('errors.503')->render();

    expect($html)->toContain('temporarily unavailable');
});
