<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Group;
use App\Models\Permission;
use App\Services\PermissionService;
use Livewire\Livewire;
use Modules\Logs\Livewire\LogViewer;

beforeEach(function () {
    // Create a test admin with the logs.view permission
    $this->admin = Admin::factory()->create(['enabled' => true]);
    $permService = app(PermissionService::class);
    $permService->register('logs', ['logs.view']);
    $permService->syncToDatabase();
    $group = Group::factory()->system()->create();
    $perm = Permission::where('name', 'logs.view')->first();
    $group->permissions()->attach($perm);
    $this->admin->groups()->syncWithoutDetaching([$group->id]);

    // Create a test log file in storage/logs/
    $this->testLogPath = storage_path('logs/test-logs.log');
    $this->testLogContent = <<<'LOG'
[2026-07-06 10:00:00] local.DEBUG: Debug message
[2026-07-06 10:01:00] local.INFO: Info message
[2026-07-06 10:02:00] local.WARNING: Warning message
[2026-07-06 10:03:00] local.ERROR: Error message
[2026-07-06 10:04:00] local.CRITICAL: Critical message
LOG;
    file_put_contents($this->testLogPath, $this->testLogContent);
});

afterEach(function () {
    if (file_exists($this->testLogPath)) {
        unlink($this->testLogPath);
    }
});

// ─── Page Load ──────────────────────────────────────────────────

it('renders the log viewer page', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(LogViewer::class)
        ->assertOk()
        ->assertSee('Log Viewer');
});

it('loads via the admin route', function () {
    $this->withoutExceptionHandling();

    $response = $this->actingAs($this->admin, 'admin')
        ->get(route('panel.logs.index'));

    $response->assertOk();
});

it('requires authentication', function () {
    $response = $this->get(route('panel.logs.index'));
    $response->assertRedirect(route('panel.login'));
});

it('requires admin guard', function () {
    // An Admin authenticated via the web guard has no tenant context,
    // so panel routes return 403 instead of the logs view.
    $response = $this->actingAs($this->admin, 'web')
        ->get(route('panel.logs.index'));

    $response->assertForbidden();
});

// ─── File Listing ───────────────────────────────────────────────

it('lists available log files from storage/logs', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(LogViewer::class)
        ->assertViewHas('logFiles', function (array $files) {
            return collect($files)->contains(fn ($file) => str_ends_with($file['path'], 'test-logs.log'));
        });
});

it('only lists .log files', function () {
    // Create a non-.log file
    file_put_contents(storage_path('logs/secret.txt'), 'not a log');
    file_put_contents(storage_path('logs/access.log'), 'also a log');

    Livewire::actingAs($this->admin, 'admin')
        ->test(LogViewer::class)
        ->assertViewHas('logFiles', function (array $files) {
            $names = array_column($files, 'name');
            expect($names)->toContain('test-logs.log', 'access.log');
            expect($names)->not->toContain('secret.txt');

            return true;
        });

    // Cleanup
    unlink(storage_path('logs/secret.txt'));
    unlink(storage_path('logs/access.log'));
});

// ─── File Selection ─────────────────────────────────────────────

it('loads log content when a file is selected', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(LogViewer::class)
        ->set('selectedFile', 'test-logs.log')
        ->assertViewHas('logContent', function ($content) {
            return str_contains($content, 'Debug message')
                && str_contains($content, 'Error message');
        });
});

it('limits the number of lines displayed', function () {
    // Create a file with more than 3 lines
    $lines = [];
    for ($i = 0; $i < 10; $i++) {
        $lines[] = "[2026-07-06 10:0{$i}:00] local.INFO: Line {$i}";
    }
    file_put_contents($this->testLogPath, implode("\n", $lines)."\n");

    Livewire::actingAs($this->admin, 'admin')
        ->test(LogViewer::class)
        ->set('selectedFile', 'test-logs.log')
        ->set('lines', 3)
        ->assertViewHas('logContent', function ($content) {
            return substr_count($content, "\n") <= 3;
        });
});

// ─── Level Filtering ────────────────────────────────────────────

it('filters log content by level', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(LogViewer::class)
        ->set('selectedFile', 'test-logs.log')
        ->set('filterLevel', 'ERROR')
        ->assertViewHas('logContent', function ($content) {
            return str_contains($content, 'Error message')
                && ! str_contains($content, 'Debug message')
                && ! str_contains($content, 'Info message');
        });
});

it('filters log content by critical level', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(LogViewer::class)
        ->set('selectedFile', 'test-logs.log')
        ->set('filterLevel', 'CRITICAL')
        ->assertViewHas('logContent', function ($content) {
            return str_contains($content, 'Critical message')
                && ! str_contains($content, 'Debug message');
        });
});

// ─── Search Filtering ───────────────────────────────────────────

it('filters log content by search text', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(LogViewer::class)
        ->set('selectedFile', 'test-logs.log')
        ->set('search', 'Warning')
        ->assertViewHas('logContent', function ($content) {
            return str_contains($content, 'Warning message')
                && ! str_contains($content, 'Debug message');
        });
});

// ─── Auto-Refresh Toggle ────────────────────────────────────────

it('toggles auto-refresh', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(LogViewer::class)
        ->assertSet('autoRefresh', false)
        ->set('autoRefresh', true)
        ->assertSet('autoRefresh', true);
});

it('only wires the refresh poll when auto-refresh is enabled', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(LogViewer::class)
        ->assertDontSee('wire:poll.5s', false)
        ->set('autoRefresh', true)
        ->assertSee('wire:poll.5s', false)
        ->set('autoRefresh', false)
        ->assertDontSee('wire:poll.5s', false);
});

// ─── Bounded Tail Reading ───────────────────────────────────────

it('returns the newest lines in chronological order', function () {
    $lines = [];
    for ($i = 0; $i < 50; $i++) {
        $lines[] = "[2026-07-06 10:00:00] local.INFO: Line {$i}";
    }
    file_put_contents($this->testLogPath, implode("\n", $lines)."\n");

    $expected = implode("\n", [
        '[2026-07-06 10:00:00] local.INFO: Line 47',
        '[2026-07-06 10:00:00] local.INFO: Line 48',
        '[2026-07-06 10:00:00] local.INFO: Line 49',
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(LogViewer::class)
        ->set('selectedFile', 'test-logs.log')
        ->set('lines', 3)
        ->assertViewHas('logContent', function ($content) use ($expected) {
            return $content === $expected;
        });
});

it('reads large logs in bounded memory', function () {
    // Write a log far larger than the buffer budget, without allocating one
    // huge string while producing it.
    $line = "[2026-07-06 10:00:00] local.INFO: filler line for the memory test\n";
    $block = str_repeat($line, 10000); // ~0.5 MB
    $handle = fopen($this->testLogPath, 'wb');
    for ($i = 0; $i < 80; $i++) {
        fwrite($handle, $block); // ~40 MB total
    }
    fclose($handle);

    $component = Livewire::actingAs($this->admin, 'admin')->test(LogViewer::class);

    memory_reset_peak_usage();
    $baseline = memory_get_usage();

    $component->set('selectedFile', 'test-logs.log')->set('lines', 50);

    // The file is ~40 MB; a full-file read would blow far past this budget.
    expect(memory_get_peak_usage() - $baseline)->toBeLessThan(12 * 1024 * 1024);
});

it('handles lines that straddle the chunk boundary', function () {
    $pads = [10, 4000, 65000, 70000, 200];
    $lines = [];
    for ($i = 0; $i < 60; $i++) {
        $lines[] = "[2026-07-06 10:00:00] local.INFO: {$i} ".str_repeat('x', $pads[$i % 5]);
    }
    file_put_contents($this->testLogPath, implode("\n", $lines)."\n");

    $expected = implode("\n", array_slice($lines, -10));

    Livewire::actingAs($this->admin, 'admin')
        ->test(LogViewer::class)
        ->set('selectedFile', 'test-logs.log')
        ->set('lines', 10)
        ->assertViewHas('logContent', function ($content) use ($expected) {
            return $content === $expected;
        });
});

it('bounds memory and truncates a single enormous line', function () {
    $handle = fopen($this->testLogPath, 'wb');
    fwrite($handle, "[2026-07-06 10:00:00] local.INFO: before\n");
    $block = str_repeat('Z', 1024 * 1024);
    for ($i = 0; $i < 20; $i++) {
        fwrite($handle, $block); // one ~20 MB line with no newline
    }
    fwrite($handle, "\n[2026-07-06 10:00:01] local.INFO: after\n");
    fclose($handle);

    $component = Livewire::actingAs($this->admin, 'admin')->test(LogViewer::class);

    memory_reset_peak_usage();
    $baseline = memory_get_usage();

    $component->set('selectedFile', 'test-logs.log')->set('lines', 50)
        ->assertViewHas('logContent', function ($content) {
            return str_contains($content, 'before')
                && str_contains($content, 'after')
                && str_contains($content, '[truncated]');
        });

    // The line is ~20 MB; it must be truncated, not buffered whole.
    expect(memory_get_peak_usage() - $baseline)->toBeLessThan(12 * 1024 * 1024);
});

// ─── Path Traversal Protection ──────────────────────────────────

it('rejects path traversal attempts in selected file', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(LogViewer::class)
        ->set('selectedFile', '../../../etc/passwd')
        ->assertHasErrors(['selectedFile']);
});

it('rejects paths outside storage/logs directory', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(LogViewer::class)
        ->set('selectedFile', '../.env')
        ->assertHasErrors(['selectedFile']);
});
