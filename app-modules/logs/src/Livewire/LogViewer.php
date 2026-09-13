<?php

declare(strict_types=1);

namespace Modules\Logs\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Livewire component for viewing, tailing, and filtering application log files.
 *
 * Features:
 * - Select log file from a dropdown of available .log files in storage/logs/
 * - Configure number of tail lines (50–1000)
 * - Filter by log level (DEBUG, INFO, WARNING, ERROR, CRITICAL)
 * - Search/filter log content by text
 * - Auto-refresh via wire:poll.5s
 *
 * Security:
 * - Path traversal protection: only allows paths within storage/logs/ with .log extension
 * - Read-only: no file write or delete operations
 */
class LogViewer extends Component
{
    /**
     * The currently selected log file name (relative to storage/logs/).
     */
    #[Url]
    public string $selectedFile = '';

    /**
     * Number of tail lines to display.
     */
    public int $lines = 100;

    /**
     * Filter log entries by level (empty string = all levels).
     */
    #[Url]
    public string $filterLevel = '';

    /**
     * Search text to filter log content.
     */
    #[Url]
    public string $search = '';

    /**
     * Whether to auto-refresh the log content via wire:poll.
     */
    public bool $autoRefresh = false;

    /**
     * The parsed log content to display.
     */
    public string $logContent = '';

    /**
     * Available log files in storage/logs/.
     *
     * @var array<int, array{name: string, path: string, size: int, modified: string}>
     */
    public array $logFiles = [];

    /**
     * Validation rules for selectedFile.
     *
     * @var array<string, list<string>>
     */
    protected array $fileRules = [
        'selectedFile' => ['nullable', 'string', 'regex:/^[a-zA-Z0-9_\-.]+\.log$/'],
    ];

    /**
     * Initialize the component with available log files.
     */
    public function mount(): void
    {
        $this->logFiles = $this->getAvailableLogFiles();
    }

    /**
     * Get the validation rules for the selected file.
     *
     * @return array<string, list<string>>
     */
    protected function rules(): array
    {
        return $this->fileRules;
    }

    /**
     * Reload log content when the selected file changes.
     */
    public function updatedSelectedFile(): void
    {
        $this->loadLog();
    }

    /**
     * Reload log content when the line count changes.
     */
    public function updatedLines(): void
    {
        $this->loadLog();
    }

    /**
     * Reload log content when the filter level changes.
     */
    public function updatedFilterLevel(): void
    {
        $this->loadLog();
    }

    /**
     * Reload log content when the search text changes.
     */
    public function updatedSearch(): void
    {
        $this->loadLog();
    }

    /**
     * Reload log content (triggered by wire:poll when auto-refresh is on).
     */
    public function pollRefresh(): void
    {
        if ($this->autoRefresh && $this->selectedFile !== '') {
            $this->loadLog();
        }
    }

    /**
     * Load the selected log file content with filtering applied.
     */
    public function loadLog(): void
    {
        if ($this->selectedFile === '') {
            $this->logContent = '';

            return;
        }

        // Validate the file name
        $validator = Validator::make(
            ['selectedFile' => $this->selectedFile],
            $this->fileRules,
        );

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $filePath = storage_path('logs/'.$this->selectedFile);

        // Security: ensure the resolved path is within storage/logs/
        $logDir = realpath(storage_path('logs'));
        $resolvedPath = realpath($filePath);

        if ($resolvedPath === false || ! Str::startsWith($resolvedPath, $logDir)) {
            $validator->errors()->add('selectedFile', 'Invalid file path.');

            throw new ValidationException($validator);
        }

        if (! file_exists($filePath) || ! is_readable($filePath)) {
            $this->logContent = '';

            return;
        }

        // Read the file
        $content = file_get_contents($filePath);

        if ($content === false) {
            $this->logContent = '';

            return;
        }

        // Split into lines
        $lines = explode("\n", $content);

        // Apply level filter
        if ($this->filterLevel !== '') {
            $levelPattern = '/\.'.preg_quote($this->filterLevel, '/').':/i';
            $lines = array_values(array_filter($lines, fn (string $line) => preg_match($levelPattern, $line)));
        }

        // Apply search filter
        if ($this->search !== '') {
            $lines = array_values(array_filter($lines, fn (string $line) => str_contains(
                mb_strtolower($line),
                mb_strtolower($this->search),
            )));
        }

        // Take only the last N lines
        $lines = array_slice($lines, -$this->lines);

        $this->logContent = implode("\n", $lines);
    }

    /**
     * Get the list of available .log files in storage/logs/.
     *
     * @return array<int, array{name: string, path: string, size: int, modified: string}>
     */
    public function getAvailableLogFiles(): array
    {
        $logDir = storage_path('logs');
        $files = [];

        if (! is_dir($logDir)) {
            return $files;
        }

        $iterator = new \DirectoryIterator($logDir);

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'log') {
                $files[] = [
                    'name' => $file->getFilename(),
                    'path' => $file->getPathname(),
                    'size' => $file->getSize(),
                    'modified' => date('Y-m-d H:i:s', $file->getMTime()),
                ];
            }
        }

        // Sort by modification time, newest first
        usort($files, fn (array $a, array $b) => $b['modified'] <=> $a['modified']);

        return $files;
    }

    /**
     * Render the log viewer component.
     */
    #[Layout('layouts.app')]
    #[Title('Log Viewer')]
    public function render(): View
    {
        return view('logs::log-viewer');
    }
}
