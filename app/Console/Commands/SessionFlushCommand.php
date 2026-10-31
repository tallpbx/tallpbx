<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Session\CacheBasedSessionHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Flush all active HTTP sessions from the session store.
 *
 * Driver-aware: empties Redis DB (connection 'session'), deletes files in
 * storage/framework/sessions, or truncates the database sessions table.
 */
class SessionFlushCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'session:flush
        {--force : Invalidate all sessions without prompting for confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Flush all active HTTP sessions from the session store';

    /**
     * The command aliases.
     *
     * @var array<int, string>
     */
    protected $aliases = ['session:clear'];

    /**
     * Execute the console command.
     */
    public function handle(Filesystem $filesystem): int
    {
        $driver = (string) config('session.driver', 'redis');

        if ($driver === 'redis') {
            $connectionName = (string) (config('session.connection') ?? 'session');
            $collidingConnection = $this->detectRedisDbCollision($connectionName);

            if ($collidingConnection !== null) {
                $config = config("database.redis.{$connectionName}");
                $config = is_array($config) ? $config : [];
                $endpoint = $this->resolveRedisEndpoint($config);
                $dbIndex = $this->resolveRedisDatabase($config, $connectionName);

                if (! $this->option('force')) {
                    $this->components->error("Refusing to run FLUSHDB: Redis session connection [{$connectionName}] shares database [{$dbIndex}] on [{$endpoint}] with Redis [{$collidingConnection}] connection. Isolate REDIS_SESSION_DB (e.g. DB 2) or pass --force to override.");

                    return self::FAILURE;
                }

                $this->components->warn("Redis session connection [{$connectionName}] shares database [{$dbIndex}] on [{$endpoint}] with Redis [{$collidingConnection}] connection (--force override applied).");
            }
        }

        if (! $this->option('force')) {
            $target = $this->describeTarget($driver);

            if (! $this->confirm("This will invalidate all active HTTP sessions across all tenants and administrators ({$target}). Do you wish to continue?")) {
                $this->components->info('Session flush cancelled.');

                return self::SUCCESS;
            }
        }

        return match ($driver) {
            'redis' => $this->flushRedis(),
            'file' => $this->flushFile($filesystem),
            'database' => $this->flushDatabase(),
            'cookie' => $this->flushCookie(),
            'array' => $this->flushArray(),
            default => $this->flushDefault($driver),
        };
    }

    /**
     * Describe the target session store for confirmation disclosure.
     */
    private function describeTarget(string $driver): string
    {
        return match ($driver) {
            'redis' => $this->describeRedisTarget(),
            'database' => $this->describeDatabaseTarget(),
            'file' => $this->describeFileTarget(),
            'cookie' => 'driver: cookie (client-side only; no server store will be modified)',
            'array' => 'driver: array (in-memory)',
            default => $this->describeDefaultTarget($driver),
        };
    }

    /**
     * Describe Redis session target parameters.
     */
    private function describeRedisTarget(): string
    {
        $connectionName = (string) (config('session.connection') ?? 'session');
        $config = config("database.redis.{$connectionName}");
        $config = is_array($config) ? $config : [];
        $endpoint = $this->resolveRedisEndpoint($config);
        $dbIndex = $this->resolveRedisDatabase($config, $connectionName);

        return "driver: redis, connection: [{$connectionName}], endpoint: [{$endpoint}], database: [{$dbIndex}]";
    }

    /**
     * Describe database session target parameters.
     */
    private function describeDatabaseTarget(): string
    {
        $connection = config('session.connection');
        $connectionLabel = (string) ($connection ?? config('database.default'));
        $table = (string) (config('session.table') ?? 'sessions');

        return "driver: database, connection: [{$connectionLabel}], table: [{$table}]";
    }

    /**
     * Describe file session target parameters.
     */
    private function describeFileTarget(): string
    {
        $path = (string) (config('session.files') ?? storage_path('framework/sessions'));

        return "driver: file, path: [{$path}]";
    }

    /**
     * Describe fallback / custom driver target parameters.
     */
    private function describeDefaultTarget(string $driver): string
    {
        try {
            $handler = app('session')->driver()->getHandler();
            if ($handler instanceof CacheBasedSessionHandler) {
                return "driver: {$driver} (WARNING: cache-backed handler flushes the entire backing cache store)";
            }
        } catch (\Throwable) {
            // Ignore resolution failure and fall back to driver name.
        }

        return "driver: {$driver}";
    }

    /**
     * Detect if the session Redis connection shares endpoint and database with default or cache.
     */
    private function detectRedisDbCollision(string $connectionName): ?string
    {
        if ($connectionName === 'default') {
            return 'default';
        }

        if ($connectionName === 'cache') {
            return 'cache';
        }

        $currentConfig = config("database.redis.{$connectionName}");
        if (! is_array($currentConfig)) {
            return null;
        }

        $currentEndpoint = $this->resolveRedisEndpoint($currentConfig);
        $currentDb = $this->resolveRedisDatabase($currentConfig, $connectionName);

        foreach (['default', 'cache'] as $candidate) {
            $candidateConfig = config("database.redis.{$candidate}");
            if (! is_array($candidateConfig)) {
                continue;
            }

            $candidateEndpoint = $this->resolveRedisEndpoint($candidateConfig);
            $candidateDb = $this->resolveRedisDatabase($candidateConfig, $candidate);

            if ($currentEndpoint === $candidateEndpoint && $currentDb === $candidateDb) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Resolve the normalized endpoint (host:port or url) for a Redis connection.
     *
     * @param  array<string, mixed>  $config
     */
    private function resolveRedisEndpoint(array $config): string
    {
        if (! empty($config['url'])) {
            $parsed = parse_url((string) $config['url']);
            if (is_array($parsed) && isset($parsed['host'])) {
                $host = $parsed['host'];
                $port = isset($parsed['port']) ? (string) $parsed['port'] : '6379';

                return "{$host}:{$port}";
            }

            return (string) $config['url'];
        }

        $host = (string) ($config['host'] ?? '127.0.0.1');
        $port = (string) ($config['port'] ?? '6379');

        return "{$host}:{$port}";
    }

    /**
     * Resolve the database index for a Redis connection.
     *
     * @param  array<string, mixed>  $config
     */
    private function resolveRedisDatabase(array $config, string $connectionName): string
    {
        if (isset($config['database']) && $config['database'] !== null && $config['database'] !== '') {
            return (string) $config['database'];
        }

        if (! empty($config['url'])) {
            $parsed = parse_url((string) $config['url']);
            if (is_array($parsed) && isset($parsed['path'])) {
                $db = ltrim($parsed['path'], '/');
                if ($db !== '') {
                    return $db;
                }
            }
        }

        return match ($connectionName) {
            'cache' => '1',
            'session' => '2',
            default => '0',
        };
    }

    /**
     * Flush sessions stored in Redis.
     */
    private function flushRedis(): int
    {
        $connectionName = (string) (config('session.connection') ?? 'session');
        $config = config("database.redis.{$connectionName}");
        $config = is_array($config) ? $config : [];
        $dbIndex = $this->resolveRedisDatabase($config, $connectionName);

        try {
            Redis::connection($connectionName)->flushdb();
            $this->components->info("Flushed Redis session store (connection: [{$connectionName}], database: [{$dbIndex}]).");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->components->error("Failed to flush Redis session store: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    /**
     * Flush sessions stored on the local filesystem.
     */
    private function flushFile(Filesystem $filesystem): int
    {
        $path = (string) (config('session.files') ?? storage_path('framework/sessions'));

        if (! $filesystem->isDirectory($path)) {
            $this->components->warn("Session directory [{$path}] does not exist.");

            return self::SUCCESS;
        }

        $files = $filesystem->files($path);
        $count = 0;

        foreach ($files as $file) {
            if ($file->getFilename() === '.gitignore') {
                continue;
            }

            $filesystem->delete($file->getPathname());
            $count++;
        }

        $this->components->info("Cleared {$count} session file(s) from [{$path}].");

        return self::SUCCESS;
    }

    /**
     * Flush sessions stored in a database table.
     */
    private function flushDatabase(): int
    {
        $connection = config('session.connection');
        $table = (string) (config('session.table') ?? 'sessions');

        try {
            DB::connection($connection)->table($table)->truncate();
            $this->components->info("Truncated sessions table [{$table}] on connection [{$connection}].");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->components->error("Failed to truncate database sessions table: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    /**
     * Handle cookie-based session driver.
     */
    private function flushCookie(): int
    {
        $this->components->warn('Cookie session driver stores session data client-side in browser cookies; server-side session flush is not applicable.');

        return self::SUCCESS;
    }

    /**
     * Handle array (in-memory) session driver.
     */
    private function flushArray(): int
    {
        $this->components->info('Flushed in-memory array session store.');

        return self::SUCCESS;
    }

    /**
     * Handle fallback / custom cache-based drivers.
     */
    private function flushDefault(string $driver): int
    {
        try {
            $handler = app('session')->driver()->getHandler();
            if ($handler instanceof CacheBasedSessionHandler) {
                $handler->getCache()->getStore()->flush();
                $this->components->info("Flushed cache-based session store for driver [{$driver}].");

                return self::SUCCESS;
            }
        } catch (\Throwable $e) {
            $this->components->error("Failed to flush session store for driver [{$driver}]: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->components->warn("Session driver [{$driver}] does not support server-side flushing.");

        return self::SUCCESS;
    }
}
