<?php

declare(strict_types=1);

/**
 * Set an environment variable in $_ENV, $_SERVER, and getenv().
 */
function setTestEnv(string $key, ?string $value): void
{
    if ($value === null) {
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);
    } else {
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv("{$key}={$value}");
    }
}

$savedEnv = [
    'XML_CACHE_TTL' => $_ENV['XML_CACHE_TTL'] ?? null,
    'XML_CACHE_DIALPLAN_TTL' => $_ENV['XML_CACHE_DIALPLAN_TTL'] ?? null,
    'XML_CACHE_CONTRIBUTOR_TTL' => $_ENV['XML_CACHE_CONTRIBUTOR_TTL'] ?? null,
    'XML_CACHE_DIRECTORY_TTL' => $_ENV['XML_CACHE_DIRECTORY_TTL'] ?? null,
    'XML_CACHE_ACL_TTL' => $_ENV['XML_CACHE_ACL_TTL'] ?? null,
    'XML_CACHE_STORE' => $_ENV['XML_CACHE_STORE'] ?? null,
    'XML_CACHE_DIALPLAN_STORE' => $_ENV['XML_CACHE_DIALPLAN_STORE'] ?? null,
    'XML_CACHE_DIRECTORY_STORE' => $_ENV['XML_CACHE_DIRECTORY_STORE'] ?? null,
    'XML_CACHE_ACL_STORE' => $_ENV['XML_CACHE_ACL_STORE'] ?? null,
];

$clearAllCacheEnv = function (): void {
    setTestEnv('XML_CACHE_TTL', null);
    setTestEnv('XML_CACHE_DIALPLAN_TTL', null);
    setTestEnv('XML_CACHE_CONTRIBUTOR_TTL', null);
    setTestEnv('XML_CACHE_DIRECTORY_TTL', null);
    setTestEnv('XML_CACHE_ACL_TTL', null);

    setTestEnv('XML_CACHE_STORE', null);
    setTestEnv('XML_CACHE_DIALPLAN_STORE', null);
    setTestEnv('XML_CACHE_DIRECTORY_STORE', null);
    setTestEnv('XML_CACHE_ACL_STORE', null);
};

beforeEach($clearAllCacheEnv);
afterEach($clearAllCacheEnv);
afterAll(function () use ($savedEnv): void {
    foreach ($savedEnv as $key => $value) {
        setTestEnv($key, $value);
    }
});

it('inherits master XML_CACHE_TTL for all XML handler caches when no granular overrides are set', function (): void {
    setTestEnv('XML_CACHE_TTL', '45');

    $config = require config_path('freeswitch.php');
    $xmlHandler = $config['xml_handler'];

    expect($xmlHandler['cache_ttl'])->toBe(45)
        ->and($xmlHandler['dialplan_cache_ttl'])->toBe(45)
        ->and($xmlHandler['dialplan_contributor_cache_ttl'])->toBe(45)
        ->and($xmlHandler['directory_cache_ttl'])->toBe(45)
        ->and($xmlHandler['acl_cache_ttl'])->toBe(45);
});

it('allows granular XML_CACHE overrides to take precedence over the master XML_CACHE_TTL', function (): void {
    setTestEnv('XML_CACHE_TTL', '30');
    setTestEnv('XML_CACHE_DIRECTORY_TTL', '10');

    $config = require config_path('freeswitch.php');
    $xmlHandler = $config['xml_handler'];

    expect($xmlHandler['cache_ttl'])->toBe(30)
        ->and($xmlHandler['dialplan_cache_ttl'])->toBe(30)
        ->and($xmlHandler['dialplan_contributor_cache_ttl'])->toBe(30)
        ->and($xmlHandler['directory_cache_ttl'])->toBe(10)
        ->and($xmlHandler['acl_cache_ttl'])->toBe(30);
});

it('inherits master XML_CACHE_STORE for all XML handler caches', function (): void {
    setTestEnv('XML_CACHE_STORE', 'redis');

    $config = require config_path('freeswitch.php');
    $xmlHandler = $config['xml_handler'];

    expect($xmlHandler['cache_store'])->toBe('redis')
        ->and($xmlHandler['dialplan_cache_store'])->toBe('redis')
        ->and($xmlHandler['directory_cache_store'])->toBe('redis')
        ->and($xmlHandler['acl_cache_store'])->toBe('redis');
});

it('allows granular XML_CACHE store overrides to take precedence', function (): void {
    setTestEnv('XML_CACHE_STORE', 'redis');
    setTestEnv('XML_CACHE_DIRECTORY_STORE', 'array');

    $config = require config_path('freeswitch.php');
    $xmlHandler = $config['xml_handler'];

    expect($xmlHandler['cache_store'])->toBe('redis')
        ->and($xmlHandler['dialplan_cache_store'])->toBe('redis')
        ->and($xmlHandler['directory_cache_store'])->toBe('array')
        ->and($xmlHandler['acl_cache_store'])->toBe('redis');
});

it('defaults all XML handler cache TTLs to 5 when neither master nor granular settings are defined', function (): void {
    $config = require config_path('freeswitch.php');
    $xmlHandler = $config['xml_handler'];

    expect($xmlHandler['cache_ttl'])->toBe(5)
        ->and($xmlHandler['dialplan_cache_ttl'])->toBe(5)
        ->and($xmlHandler['dialplan_contributor_cache_ttl'])->toBe(5)
        ->and($xmlHandler['directory_cache_ttl'])->toBe(5)
        ->and($xmlHandler['acl_cache_ttl'])->toBe(5);
});
