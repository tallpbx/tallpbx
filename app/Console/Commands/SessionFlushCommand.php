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
        if (! $this->option('force') && ! $this->confirm('This will invalidate all active HTTP sessions across all tenants and administrators. Do you wish to continue?')) {
            $this->components->info('Session flush cancelled.');

            return self::SUCCESS;
        }

        $driver = (string) config('session.driver', 'redis');

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
     * Flush sessions stored in Redis.
     */
    private function flushRedis(): int
    {
        $connectionName = (string) (config('session.connection') ?? 'session');
        $dbIndex = config("database.redis.{$connectionName}.database", '2');

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
