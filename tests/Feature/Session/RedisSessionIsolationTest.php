<?php

declare(strict_types=1);

use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Session\CacheBasedSessionHandler;
use Illuminate\Support\Facades\Cache;

it('defaults redis.session.database to 2 when REDIS_SESSION_DB is unset', function (): void {
    $previous = getenv('REDIS_SESSION_DB');
    $serverPrev = $_SERVER['REDIS_SESSION_DB'] ?? null;
    $envPrev = $_ENV['REDIS_SESSION_DB'] ?? null;

    putenv('REDIS_SESSION_DB');
    unset($_SERVER['REDIS_SESSION_DB'], $_ENV['REDIS_SESSION_DB']);

    try {
        $config = require base_path('config/database.php');
        expect((string) $config['redis']['session']['database'])->toBe('2')
            ->and((string) $config['redis']['session']['database'])
            ->not->toBe((string) $config['redis']['cache']['database']);
    } finally {
        if ($previous !== false) {
            putenv("REDIS_SESSION_DB={$previous}");
        }
        if ($serverPrev !== null) {
            $_SERVER['REDIS_SESSION_DB'] = $serverPrev;
        }
        if ($envPrev !== null) {
            $_ENV['REDIS_SESSION_DB'] = $envPrev;
        }
    }
});

it('preserves Redis session keys in DB 13 when the Redis cache store in DB 14 is flushed', function (): void {
    $sessionStore = new RedisStore(app('redis'), 'session_test_', 'session');
    $handler = new CacheBasedSessionHandler(
        new Repository($sessionStore),
        120,
    );

    $sessionId = 'isolation-check-'.bin2hex(random_bytes(6));
    $handler->write($sessionId, 'serialized-admin-session');
    Cache::store('redis')->put('volatile-xml-fragment', '<document/>', 60);

    try {
        expect($handler->read($sessionId))->toBe('serialized-admin-session')
            ->and(Cache::store('redis')->get('volatile-xml-fragment'))->toBe('<document/>');

        Cache::store('redis')->flush();

        expect(Cache::store('redis')->get('volatile-xml-fragment'))->toBeNull()
            ->and($handler->read($sessionId))->toBe('serialized-admin-session');
    } finally {
        $handler->destroy($sessionId);
    }
});
