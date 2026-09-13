<?php

declare(strict_types=1);

use App\Services\DialplanContext;

it('generates internal context from tenant UUID', function () {
    $context = new DialplanContext;
    $uuid = '550e8400-e29b-41d4-a716-446655440000';

    $result = $context->internal($uuid);

    expect($result)->toBe('tenant_550e8400-e29b-41d4-a716-446655440000_internal');
});

it('generates public context from tenant UUID', function () {
    $context = new DialplanContext;
    $uuid = '550e8400-e29b-41d4-a716-446655440000';

    $result = $context->public($uuid);

    expect($result)->toBe('tenant_550e8400-e29b-41d4-a716-446655440000_public');
});

it('parses tenant UUID from internal context', function () {
    $context = new DialplanContext;

    $uuid = $context->parseTenantId('tenant_550e8400-e29b-41d4-a716-446655440000_internal');

    expect($uuid)->toBe('550e8400-e29b-41d4-a716-446655440000');
});

it('parses tenant UUID from public context', function () {
    $context = new DialplanContext;

    $uuid = $context->parseTenantId('tenant_550e8400-e29b-41d4-a716-446655440000_public');

    expect($uuid)->toBe('550e8400-e29b-41d4-a716-446655440000');
});

it('returns null for non-tenant context', function () {
    $context = new DialplanContext;

    $uuid = $context->parseTenantId('public');

    expect($uuid)->toBeNull();
});

it('returns null for malformed tenant context', function () {
    $context = new DialplanContext;

    // Missing suffix
    $result = $context->parseTenantId('tenant_550e8400-e29b-41d4-a716-446655440000');

    expect($result)->toBeNull();
});

it('returns null for empty context', function () {
    $context = new DialplanContext;

    expect($context->parseTenantId(''))->toBeNull();
});

it('isTenantContext returns true for valid internal context', function () {
    $context = new DialplanContext;

    expect($context->isTenantContext('tenant_uuid-123_internal'))->toBeTrue();
});

it('isTenantContext returns true for valid public context', function () {
    $context = new DialplanContext;

    expect($context->isTenantContext('tenant_uuid-123_public'))->toBeTrue();
});

it('isTenantContext returns false for non-tenant context', function () {
    $context = new DialplanContext;

    expect($context->isTenantContext('default'))->toBeFalse()
        ->and($context->isTenantContext('public'))->toBeFalse()
        ->and($context->isTenantContext(''))->toBeFalse();
});
