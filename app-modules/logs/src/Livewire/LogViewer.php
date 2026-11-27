<?php

declare(strict_types=1);

namespace Modules\Logs\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
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
 * - Auto-refresh via wire:poll.5s (poll is only wired while the toggle is on)
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

        // Security: ensure the resolved path is within storage/logs/. The
        // trailing separator matters: without it, a sibling directory that
        // shares the prefix (for example storage/logs-backup/) would also
        // pass the check.
        $logDir = realpath(storage_path('logs')).DIRECTORY_SEPARATOR;
        $resolvedPath = realpath($filePath);

        if ($resolvedPath === false || ! Str::startsWith($resolvedPath, $logDir)) {
            $validator->errors()->add('selectedFile', 'Invalid file path.');

            throw new ValidationException($validator);
        }

        if (! file_exists($filePath) || ! is_readable($filePath)) {
            $this->logContent = '';

            return;
        }

        // Skip the scan entirely when the file and view parameters are
        // unchanged since the last render (for example an auto-refresh tick on
        // a quiet log). The cache key embeds size+mtime so any change to the
        // file or the filters always produces a fresh result.
        clearstatcache(true, $filePath);
        $signature = [
            $this->selectedFile,
            $this->filterLevel,
            $this->search,
            $this->lines,
            (int) filesize($filePath),
            (int) filemtime($filePath),
        ];

        $this->logContent = Cache::remember(
            'log-viewer:'.md5(serialize($signature)),
            now()->addMinutes(5),
            fn (): string => $this->tailFilteredLines($filePath),
        );
    }

    /**
     * Read the newest matching lines from the tail of a log file using a
     * bounded, backward chunked scan.
     *
     * Memory use is proportional to the number of retained lines, not the file
     * size, so large logs that previously exhausted memory_limit (via a full
     * file_get_contents()/explode() read) now load safely. The level and search
     * filters are applied while scanning so only matching lines are retained.
     */
    private function tailFilteredLines(string $filePath): string
    {
        $handle = fopen($filePath, 'rb');

        if ($handle === false) {
            return '';
        }

        try {
            $fileSize = (int) filesize($filePath);

            if ($fileSize === 0) {
                return '';
            }

            // A trailing newline terminates the final line; it does not start a
            // phantom empty line. Trim it so line counting matches `tail -n`.
            fseek($handle, $fileSize - 1);
            $lastByte = fread($handle, 1);
            $end = ($lastByte === "\n") ? $fileSize - 1 : $fileSize;

            $levelPattern = $this->filterLevel !== ''
                ? '/\.'.preg_quote($this->filterLevel, '/').':/i'
                : null;
            $searchLower = $this->search !== '' ? mb_strtolower($this->search) : null;

            $chunkSize = 65536;
            $carry = '';       // incomplete line fragment at the left scan edge
            $collected = [];   // matching lines, newest first
            $position = $end;

            while ($position > 0 && count($collected) < $this->lines) {
                $readSize = min($chunkSize, $position);
                $position -= $readSize;
                fseek($handle, $position);
                $chunk = fread($handle, $readSize);

                if ($chunk === false || $chunk === '') {
                    break;
                }

                $text = $chunk.$carry;
                $parts = explode("\n", $text);

                if ($position > 0) {
                    // The leftmost fragment may continue further left; retain it.
                    $carry = array_shift($parts) ?? '';
                } else {
                    $carry = '';
                }

                foreach (array_reverse($parts) as $line) {
                    if ($this->lineMatches($line, $levelPattern, $searchLower)) {
                        $collected[] = $line;

                        if (count($collected) >= $this->lines) {
                            break;
                        }
                    }
                }
            }

            return implode("\n", array_reverse($collected));
        } finally {
            fclose($handle);
        }
    }

    /**
     * Determine whether a single log line passes the active level and search filters.
     */
    private function lineMatches(string $line, ?string $levelPattern, ?string $searchLower): bool
    {
        if ($levelPattern !== null && ! preg_match($levelPattern, $line)) {
            return false;
        }

        if ($searchLower !== null && ! str_contains(mb_strtolower($line), $searchLower)) {
            return false;
        }

        return true;
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
