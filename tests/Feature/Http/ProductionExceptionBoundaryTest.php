<?php

declare(strict_types=1);

use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

/**
 * Branded copy built dynamically: the stock debug page embeds the source of
 * the file where the exception was thrown (this test file), so a literal
 * phrase written here would appear even when the boundary did not fire.
 */
function brandedCopy(): string
{
    return implode(' ', ['The', 'team', 'has', 'been', 'notified']);
}

it('renders the branded 500 page with the request id in production', function (): void {
    Route::get('/_throw', fn () => throw new RuntimeException('boom'))->middleware('web');

    $this->app->detectEnvironment(fn () => 'production');

    $response = $this->get('/_throw');

    $response->assertStatus(500);
    expect($response->getContent())
        ->toContain(brandedCopy())
        ->toMatch('/req_[0-9a-f]{16}/');
});

it('keeps stock rendering for http exceptions in production', function (): void {
    Route::get('/_forbidden', fn () => abort(403))->middleware('web');

    $this->app->detectEnvironment(fn () => 'production');

    $this->get('/_forbidden')->assertForbidden();
    expect($this->get('/_forbidden')->getContent())->not->toContain(brandedCopy());
});

it('keeps stock rendering in non-production environments', function (): void {
    Route::get('/_throw', fn () => throw new RuntimeException('boom'))->middleware('web');

    $this->get('/_throw')->assertStatus(500);
    expect($this->get('/_throw')->getContent())->not->toContain(brandedCopy());
});

it('keeps json error responses for api requests in production', function (): void {
    Route::get('/api/_throw', fn () => throw new RuntimeException('boom'));

    $this->app->detectEnvironment(fn () => 'production');

    $response = $this->get('/api/_throw');

    $response->assertStatus(500);
    expect($response->headers->get('content-type'))->toContain('application/json');
    expect($response->getContent())->not->toContain(brandedCopy());
});

it('keeps stock rendering for csrf token mismatches in production', function (): void {
    Route::post('/_csrf', fn () => 'ok')->middleware('web');

    $this->app->detectEnvironment(fn () => 'production');

    $response = $this->post('/_csrf');

    $response->assertStatus(419);
    expect($response->getContent())->not->toContain(brandedCopy());
});

it('keeps stock rendering for validation failures in production', function (): void {
    // A failed login or form validation is a normal redirect-with-errors
    // flow, not a server fault; the branded 500 boundary must not swallow
    // ValidationException.
    Route::post('/_validate', fn () => throw ValidationException::withMessages([
        'email' => ['These credentials do not match our records.'],
    ]))->middleware(StartSession::class);

    $this->app->detectEnvironment(fn () => 'production');

    $response = $this->post('/_validate');

    $response->assertRedirect();
    expect($response->getContent())->not->toContain(brandedCopy());
});

it('serves the branded 503 page for maintenance-style http exceptions', function (): void {
    Route::get('/_down', fn () => abort(503))->middleware('web');

    $response = $this->get('/_down');

    $response->assertStatus(503);
    expect($response->getContent())->toContain('temporarily unavailable');
});
