<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

it('attaches a unique request id to every web request', function (): void {
    Route::get('/_reqid', fn (Request $request) => (string) $request->attributes->get('request_id'))
        ->middleware('web');

    $first = $this->get('/_reqid')->getContent();
    $second = $this->get('/_reqid')->getContent();

    expect($first)->toMatch('/^req_[0-9a-f]{16}$/')
        ->and($second)->toMatch('/^req_[0-9a-f]{16}$/')
        ->and($first)->not->toBe($second);
});

it('adds the request id to the log context', function (): void {
    Log::spy();
    Route::get('/_reqid', fn (Request $request) => 'ok')->middleware('web');

    $this->get('/_reqid')->assertOk();

    Log::shouldHaveReceived('withContext')
        ->once()
        ->withArgs(fn (array $context): bool => isset($context['request_id'])
            && preg_match('/^req_[0-9a-f]{16}$/', $context['request_id']) === 1);
});
