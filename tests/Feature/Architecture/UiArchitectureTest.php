<?php

declare(strict_types=1);
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

/**
 * Architecture tests enforcing user interface conventions, design token discipline,
 * typography hierarchy, localization, and component standards across all Blade views.
 */
it('forbids arbitrary one-off font size classes across all blade views', function (): void {
    $projectRoot = base_path();

    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($projectRoot.'/app-modules')) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = $file->getPathname();
        }
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($projectRoot.'/resources/views')) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = $file->getPathname();
        }
    }

    expect(count($files))->toBeGreaterThan(100);

    $violations = [];
    $pattern = '/\btext-\[(?:\d+(?:\.\d+)?(?:px|rem|em|pt))\]/';

    foreach ($files as $file) {
        $content = (string) file_get_contents($file);
        $relativePath = str_replace($projectRoot.'/', '', $file);

        $lines = explode("\n", $content);
        foreach ($lines as $index => $line) {
            if (preg_match_all($pattern, $line, $matches)) {
                $lineNum = $index + 1;
                foreach ($matches[0] as $match) {
                    $violations[] = "{$relativePath}:{$lineNum} -> {$match} found in '{$line}'";
                }
            }
        }
    }

    expect($violations)->toBeEmpty(
        'Arbitrary font size classes violate design token discipline in AGENTS.md. Normalize to standard text-xs with semantic opacity.'
    );
});

it('enforces standardized x-form-submit-button across all edit and create views', function (): void {
    $projectRoot = base_path();

    $files = array_merge(
        glob($projectRoot.'/app-modules/*/resources/views/*edit*.blade.php') ?: [],
        glob($projectRoot.'/app-modules/*/resources/views/*create*.blade.php') ?: [],
        [
            $projectRoot.'/app-modules/event-rate-limits/resources/views/event-rate-limits-settings.blade.php',
            $projectRoot.'/app-modules/dialplan-tools/resources/views/dialplan-tester.blade.php',
            $projectRoot.'/app-modules/fax/resources/views/fax-send.blade.php',
        ]
    );

    expect(count($files))->toBeGreaterThan(45);

    $violations = [];

    foreach ($files as $file) {
        $content = (string) file_get_contents($file);
        $relativePath = str_replace($projectRoot.'/', '', $file);

        if (preg_match('/<button[^>]*type=[\"\']submit[\"\'][^>]*>/i', $content, $match)) {
            $violations[] = "{$relativePath} contains raw submit button: {$match[0]}";
        }

        if (str_contains($content, '<form') && ! str_contains($content, '<x-form-submit-button')) {
            $violations[] = "{$relativePath} has a <form> but does not use <x-form-submit-button>";
        }

        if (preg_match_all('/<a[^>]*class=[\"\'][^\"\']*btn-ghost[^\"\']*[\"\'][^>]*>/i', $content, $matches)) {
            foreach ($matches[0] as $cancelTag) {
                if (! str_contains($cancelTag, 'btn-sm') && ! str_contains($cancelTag, 'btn-xs')) {
                    $violations[] = "{$relativePath} contains un-sized cancel link: {$cancelTag}";
                }
                if (! str_contains($cancelTag, 'wire:navigate')) {
                    $violations[] = "{$relativePath} cancel link lacks wire:navigate: {$cancelTag}";
                }
            }
        }
    }

    expect($violations)->toBeEmpty();
});

it('enforces standardized page-title typography and x-page-header usage across all module and panel views', function (): void {
    $projectRoot = base_path();

    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($projectRoot.'/app-modules')) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = $file->getPathname();
        }
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($projectRoot.'/resources/views')) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = $file->getPathname();
        }
    }

    expect(count($files))->toBeGreaterThan(100);

    $rawHeadingAllowlist = [
        'resources/views/components/page-header.blade.php',
        'app-modules/admin/resources/views/auth/admin-login.blade.php',
        'app-modules/admin/resources/views/auth/initial-admin-setup.blade.php',
        'app-modules/tenant/resources/views/auth/tenant-login.blade.php',
        'app-modules/auth/resources/views/register.blade.php',
        'app-modules/auth/resources/views/forgot-password.blade.php',
        'app-modules/auth/resources/views/reset-password.blade.php',
        'resources/views/errors/500.blade.php',
        'resources/views/errors/503.blade.php',
    ];

    $violations = [];
    $xPageHeaders = 0;

    foreach ($files as $file) {
        $content = (string) file_get_contents($file);
        $relativePath = str_replace($projectRoot.'/', '', $file);

        if (preg_match_all('/<x-page-header\b/i', $content, $pageHeaderMatches)) {
            $xPageHeaders += count($pageHeaderMatches[0]);
        }

        if (preg_match_all('/<h[12]\b[^>]*class=[\"\']([^\"\']*)[\"\'][^>]*>/i', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $fullTag = $match[0];
                $classes = $match[1];

                if (str_contains($classes, 'text-2xl')) {
                    if (! in_array($relativePath, $rawHeadingAllowlist, true)) {
                        $violations[] = "{$relativePath}: raw text-2xl page heading must use <x-page-header> -> {$fullTag}";
                    }

                    if (! str_contains($classes, 'font-bold')) {
                        $violations[] = "{$relativePath}: heading missing 'font-bold' -> {$fullTag}";
                    }
                    if (! str_contains($classes, 'tracking-tight')) {
                        $violations[] = "{$relativePath}: heading missing 'tracking-tight' -> {$fullTag}";
                    }
                    if (! str_contains($classes, 'text-base-content')) {
                        $violations[] = "{$relativePath}: heading missing 'text-base-content' -> {$fullTag}";
                    }
                    if (str_contains($classes, 'font-semibold')) {
                        $violations[] = "{$relativePath}: heading contains non-standard 'font-semibold' -> {$fullTag}";
                    }
                }
            }
        }
    }

    expect($violations)->toBeEmpty('Page header violations: '.implode("\n", $violations))
        ->and($xPageHeaders)->toBeGreaterThanOrEqual(119);
});

it('renders x-page-header with optional back link and rich subtitle slot', function (): void {
    $rendered = Blade::render(
        '<x-page-header title="Audit Logs" back-href="/panel/users" back-label="Back to Users">'
        .'<x-slot:subtitle>Manage <a href="/panel/groups" class="link link-primary">Groups</a> here.</x-slot:subtitle>'
        .'</x-page-header>'
    );

    expect($rendered)
        ->toContain('Audit Logs')
        ->toContain('href="/panel/users"')
        ->toContain('aria-label="Back to Users"')
        ->toContain('wire:navigate')
        ->toContain('<a href="/panel/groups" class="link link-primary">Groups</a>');
});

it('enforces that all table header cells across app-modules and resources/views are localized', function (): void {
    $projectRoot = base_path();

    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($projectRoot.'/app-modules')) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = $file->getPathname();
        }
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($projectRoot.'/resources/views')) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = $file->getPathname();
        }
    }

    expect(count($files))->toBeGreaterThan(100);

    $thRegex = '/<th\b[^>]*>(.*?)<\/th>/is';
    $violations = [];
    $scannedHeaders = 0;

    foreach ($files as $file) {
        $content = (string) file_get_contents($file);
        $relativePath = str_replace($projectRoot.'/', '', $file);

        if (preg_match_all($thRegex, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $fullTag = trim($match[0]);
                $inner = $match[1];

                $innerClean = preg_replace('/\{\{--.*?--\}\}/s', '', $inner);
                $plainText = trim(strip_tags((string) $innerClean));

                if (preg_match('/[a-zA-Z]/', $plainText)) {
                    $scannedHeaders++;

                    $isLocalized = (bool) preg_match('/__\(|@lang|trans\(|\{\{\s*\$/', (string) $innerClean);

                    if (! $isLocalized) {
                        $violations[] = "{$relativePath}: unlocalized table header '{$plainText}' in {$fullTag}";
                    }
                }
            }
        }
    }

    expect($scannedHeaders)->toBeGreaterThan(200)
        ->and($violations)->toBeEmpty('Unlocalized table headers found: '.implode("\n", $violations));
});

it('enforces standardized sizing and loading locks on all toggle switches', function (): void {
    $projectRoot = base_path();

    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($projectRoot.'/app-modules')) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = $file->getPathname();
        }
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($projectRoot.'/resources/views')) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = $file->getPathname();
        }
    }

    expect(count($files))->toBeGreaterThan(100);

    $violations = [];
    $scannedToggles = 0;

    foreach ($files as $file) {
        $content = (string) file_get_contents($file);
        $relativePath = str_replace($projectRoot.'/', '', $file);

        if (preg_match_all('/<input\b.*?(?<!-)\/?>/is', $content, $matches)) {
            foreach ($matches[0] as $tag) {
                if (! preg_match('/class=[\"\']([^\"\']*)[\"\']/is', $tag, $classMatch)) {
                    continue;
                }

                $classes = $classMatch[1];

                if (! preg_match('/(?<![a-zA-Z0-9_-])toggle(?=[\s\"\']|$)/', $classes)) {
                    continue;
                }

                $scannedToggles++;

                if (! preg_match('/\btoggle-(?:xs|sm|md|lg)\b/', $classes)) {
                    $violations[] = "{$relativePath}: toggle switch missing explicit size (expected 'toggle-sm') -> {$tag}";
                }

                if (! str_contains($tag, 'wire:loading.attr="disabled"') && ! str_contains($tag, "wire:loading.attr='disabled'")) {
                    $violations[] = "{$relativePath}: toggle switch missing 'wire:loading.attr=\"disabled\"' lock -> {$tag}";
                }
            }
        }
    }

    expect($scannedToggles)->toBeGreaterThanOrEqual(15)
        ->and($violations)->toBeEmpty(
            'All toggle switches must specify an explicit size token (e.g. toggle-sm) and wire:loading.attr="disabled".'
        );
});

it('renders form errors using the shared x-form-error component', function (): void {
    View::share('errors', new ViewErrorBag);
    $view = (string) $this->blade('<x-form-error name="email" />', [
        'errors' => new ViewErrorBag,
    ]);
    expect(trim($view))->toBe('');

    $errorBag = new MessageBag(['email' => ['The email field is required.']]);
    $viewErrorBag = new ViewErrorBag;
    $viewErrorBag->put('default', $errorBag);
    View::share('errors', $viewErrorBag);

    $rendered = (string) $this->blade('<x-form-error name="email" />', [
        'errors' => $viewErrorBag,
    ]);

    expect($rendered)
        ->toContain('class="mt-1 text-xs text-error"')
        ->toContain('role="alert"')
        ->toContain('The email field is required.');
});

it('enforces that all module views use x-form-error instead of raw @error directives', function (): void {
    $projectRoot = base_path();
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($projectRoot.'/app-modules')) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = $file->getPathname();
        }
    }

    $violations = [];

    foreach ($files as $file) {
        $content = (string) file_get_contents($file);
        $relativePath = str_replace($projectRoot.'/', '', $file);

        if (preg_match_all('/@error\s*\(/i', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $lineNumber = substr_count(substr($content, 0, $match[1]), "\n") + 1;
                $violations[] = "{$relativePath}:{$lineNumber} contains raw @error directive";
            }
        }
    }

    expect($violations)->toBeEmpty(
        'Module views must not contain raw @error directives. Use <x-form-error name="..." /> instead: '.implode("\n", $violations)
    );
});

it('enforces settings-edit follows standard page header, button, table, and empty state conventions', function (): void {
    $file = base_path('app-modules/admin/resources/views/settings-edit.blade.php');
    $content = (string) file_get_contents($file);

    expect($content)->toContain('<x-page-header')
        ->and($content)->toContain('btn-primary btn-sm')
        ->and($content)->toContain('table-zebra text-xs')
        ->and($content)->toContain('__(\'admin.settings_empty\')')
        ->and($content)->toContain('<x-icon-button');
});

it('enforces layouts/app contains zero raw tooltip divs and uses localized close_sidebar', function (): void {
    $file = base_path('resources/views/layouts/app.blade.php');
    $content = (string) file_get_contents($file);

    expect($content)->not->toMatch('/class=[\"\']tooltip\b/i')
        ->and($content)->toContain('aria-label="{{ __(\'admin.close_sidebar\') }}"');
});

it('enforces dashboard stats overview heading uses standard text-lg font-semibold', function (): void {
    $file = base_path('resources/views/components/dashboard/stats.blade.php');
    $content = (string) file_get_contents($file);

    expect($content)->toContain('text-lg font-semibold')
        ->and($content)->not->toContain('text-xl font-semibold');
});

it('restricts toggle-warning exclusively to the firewall observe-mode switch', function (): void {
    $projectRoot = base_path();
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($projectRoot.'/app-modules')) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = $file->getPathname();
        }
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($projectRoot.'/resources/views')) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = $file->getPathname();
        }
    }

    $violations = [];

    foreach ($files as $file) {
        $content = (string) file_get_contents($file);
        $relativePath = str_replace($projectRoot.'/', '', $file);

        if ($relativePath === 'app-modules/security/resources/views/partials/firewall-switches.blade.php') {
            continue;
        }

        if (str_contains($content, 'toggle-warning')) {
            $violations[] = "{$relativePath} contains forbidden 'toggle-warning' (only allowed in firewall-switches.blade.php)";
        }
    }

    expect($violations)->toBeEmpty(
        'toggle-warning is reserved exclusively for the observe-mode switch in firewall-switches.blade.php: '.implode("\n", $violations)
    );
});

it('restricts checkbox classes exclusively to the multi-select allowlist', function (): void {
    $projectRoot = base_path();
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($projectRoot.'/app-modules')) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = $file->getPathname();
        }
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($projectRoot.'/resources/views')) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = $file->getPathname();
        }
    }

    $allowlist = [
        'app-modules/admin/resources/views/groups-edit.blade.php',
        'app-modules/admin/resources/views/users-edit.blade.php',
        'app-modules/admin/resources/views/admins-edit.blade.php',
        'app-modules/certificates/resources/views/certificate-manager.blade.php',
        'app-modules/certificates/resources/views/partials/tab-selfsigned.blade.php',
        'app-modules/certificates/resources/views/partials/tab-import.blade.php',
        'app-modules/certificates/resources/views/partials/tab-letsencrypt.blade.php',
        'app-modules/certificates/resources/views/partials/certificate-modals.blade.php',
        'app-modules/extensions/resources/views/extensions-bulk-create.blade.php',
        'app-modules/admin/resources/views/auth/admin-login.blade.php',
        'app-modules/tenant/resources/views/auth/tenant-login.blade.php',
        'resources/views/layouts/app.blade.php',
        'app-modules/security/resources/views/tabs/geo-blocking.blade.php',
        'app-modules/backups/resources/views/backups-edit.blade.php',
    ];

    $violations = [];

    foreach ($files as $file) {
        $content = (string) file_get_contents($file);
        $relativePath = str_replace($projectRoot.'/', '', $file);

        if (in_array($relativePath, $allowlist, true)) {
            continue;
        }

        if (preg_match_all('/<input\b[^>]*class=["\'][^"\']*\bcheckbox\b[^"\']*["\'][^>]*>/i', $content, $matches)) {
            foreach ($matches[0] as $match) {
                $violations[] = "{$relativePath} contains unauthorized checkbox: {$match}";
            }
        }
    }

    expect($violations)->toBeEmpty(
        'Binary switches must use toggle toggle-sm toggle-success. Checkboxes are restricted to multi-select tables/matrices: '.implode("\n", $violations)
    );
});

it('enforces two-tier loading dimming standards across all blade views', function (): void {
    $projectRoot = base_path();
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($projectRoot.'/app-modules')) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = $file->getPathname();
        }
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($projectRoot.'/resources/views')) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = $file->getPathname();
        }
    }

    $violations = [];

    foreach ($files as $file) {
        $content = (string) file_get_contents($file);
        $relativePath = str_replace($projectRoot.'/', '', $file);

        if (str_contains($content, 'opacity-60 pointer-events-none')) {
            $violations[] = "{$relativePath} contains forbidden 'opacity-60 pointer-events-none' (expected 'opacity-70 pointer-events-none')";
        }

        if (preg_match_all('/wire:loading\.class=[\x22\x27]opacity-40\s+pointer-events-none[\x22\x27]/i', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $pos = $match[1];
                $before = substr($content, 0, $pos);
                $lastTagOpen = strrpos($before, '<');
                if ($lastTagOpen !== false) {
                    $tagSnippet = substr($before, $lastTagOpen);
                    if (! preg_match('/^<tr\b/i', $tagSnippet)) {
                        $violations[] = "{$relativePath}: 'wire:loading.class=\"opacity-40 pointer-events-none\"' must only be used on <tr> elements";
                    }
                }
            }
        }
    }

    expect($violations)->toBeEmpty(
        'Two-tier loading dimming strictly requires opacity-40 pointer-events-none for <tr> table rows and opacity-70 pointer-events-none for interactive controls: '.implode("\n", $violations)
    );
});

it('enforces standardized backdrop scrims and forbids redundant badge dimension overrides', function (): void {
    $projectRoot = base_path();
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($projectRoot.'/app-modules')) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = $file->getPathname();
        }
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($projectRoot.'/resources/views')) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = $file->getPathname();
        }
    }

    $violations = [];

    foreach ($files as $file) {
        $content = (string) file_get_contents($file);
        $relativePath = str_replace($projectRoot.'/', '', $file);

        if (str_contains($content, 'bg-black/40')) {
            $violations[] = "{$relativePath} contains non-standard backdrop 'bg-black/40' (expected 'bg-black/50')";
        }

        if (str_contains($content, 'h-auto py-0.5 leading-tight')) {
            $violations[] = "{$relativePath} contains redundant badge dimensions 'h-auto py-0.5 leading-tight'";
        }

        if (preg_match_all('/bg-black\/(\d+)/', $content, $matches)) {
            foreach ($matches[1] as $opacity) {
                if ($opacity === '15' && $relativePath === 'resources/views/components/operational-toast.blade.php') {
                    continue;
                }
                if ($opacity !== '50') {
                    $violations[] = "{$relativePath} contains unexpected bg-black/{$opacity}";
                }
            }
        }
    }

    expect($violations)->toBeEmpty(implode("\n", $violations));
});

it('enforces wire:navigate on all internal panel navigation links', function (): void {
    $projectRoot = base_path();
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($projectRoot.'/app-modules')) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = $file->getPathname();
        }
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($projectRoot.'/resources/views')) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = $file->getPathname();
        }
    }

    $violations = [];

    foreach ($files as $file) {
        $content = (string) file_get_contents($file);
        $relativePath = str_replace($projectRoot.'/', '', $file);

        if (preg_match_all('/<a\b(?:[^\x22\x27>]|[\x22\x27][^\x22\x27]*[\x22\x27])*>/is', $content, $matches)) {
            foreach ($matches[0] as $tag) {
                if (! str_contains($tag, "route('panel.") && ! str_contains($tag, 'route("panel.')) {
                    continue;
                }

                if (str_contains($tag, 'target=') || str_contains($tag, 'download')) {
                    continue;
                }

                if (! str_contains($tag, 'wire:navigate')) {
                    $violations[] = "{$relativePath}: link missing wire:navigate -> ".preg_replace('/\s+/', ' ', $tag);
                }
            }
        }
    }

    expect($violations)->toBeEmpty(
        'All internal panel routes must use wire:navigate for SPA continuity: '.implode("\n", $violations)
    );
});

it('enforces accessible labels on all btn-square buttons', function (): void {
    $projectRoot = base_path();
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($projectRoot.'/app-modules')) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = $file->getPathname();
        }
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($projectRoot.'/resources/views')) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = $file->getPathname();
        }
    }

    $violations = [];

    foreach ($files as $file) {
        $content = (string) file_get_contents($file);
        $relativePath = str_replace($projectRoot.'/', '', $file);

        if (preg_match_all('/<button\b(?:[^\x22\x27>]|[\x22\x27][^\x22\x27]*[\x22\x27])*>/is', $content, $matches)) {
            foreach ($matches[0] as $tag) {
                if (! preg_match('/class=[\x22\x27][^\x22\x27]*\bbtn-square\b[^\x22\x27]*[\x22\x27]/i', $tag)) {
                    continue;
                }

                if (! str_contains($tag, 'aria-label=') && ! str_contains($tag, 'aria-labelledby=')) {
                    $violations[] = "{$relativePath}: btn-square button missing aria-label -> ".preg_replace('/\s+/', ' ', $tag);
                }
            }
        }
    }

    expect($violations)->toBeEmpty(
        'All btn-square icon buttons must include an aria-label or use <x-icon-button>: '.implode("\n", $violations)
    );
});

it('enforces secondary helper text scale and restricts text-xs opacity modifiers', function (): void {
    $projectRoot = base_path();
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($projectRoot.'/app-modules')) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = $file->getPathname();
        }
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($projectRoot.'/resources/views')) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = $file->getPathname();
        }
    }

    $allowedExceptions = [
        'app-modules/security/resources/views/tabs/firewall-rules.blade.php',
        'app-modules/security/resources/views/tabs/partials/firewall-prefilters.blade.php',
        'app-modules/security/resources/views/tabs/partials/firewall-services.blade.php',
        'app-modules/security/resources/views/tabs/partials/firewall-custom-rules.blade.php',
        'app-modules/security/resources/views/tabs/block-allow.blade.php',
        'app-modules/security/resources/views/tabs/partials/whitelist-section.blade.php',
        'app-modules/security/resources/views/tabs/partials/blacklist-section.blade.php',
        'app-modules/security/resources/views/tabs/attackers.blade.php',
        'app-modules/security/resources/views/tabs/partials/banned-attackers-section.blade.php',
        'app-modules/security/resources/views/tabs/partials/sip-scanner-section.blade.php',
        'app-modules/security/resources/views/tabs/geo-blocking.blade.php',
        'app-modules/security/resources/views/drawers/observe-activity.blade.php',
        'app-modules/certificates/resources/views/certificate-manager.blade.php',
        'app-modules/certificates/resources/views/partials/tab-inventory.blade.php',
        'app-modules/certificates/resources/views/partials/tab-logs.blade.php',
        'app-modules/admin/resources/views/permissions-list.blade.php',
        'app-modules/admin/resources/views/admins-list.blade.php',
        'app-modules/admin/resources/views/groups-edit.blade.php',
        'app-modules/admin/resources/views/impersonation-logs-list.blade.php',
        'app-modules/admin/resources/views/notifications-list.blade.php',
        'app-modules/admin/resources/views/modules-list.blade.php',
        'app-modules/admin/resources/views/git-update.blade.php',
        'app-modules/admin/resources/views/partials/git-channels.blade.php',
        'app-modules/feature-codes/resources/views/feature-codes-list.blade.php',
        'app-modules/email-connector/resources/views/email-connector-edit.blade.php',
        'app-modules/email-connector/resources/views/partials/oauth-section.blade.php',
        'resources/views/pages/home.blade.php',
        'resources/views/errors/500.blade.php',
        'resources/views/layouts/app.blade.php',
        'resources/views/components/sidebar-menu-item.blade.php',
        'resources/views/components/horizontal-menu-item.blade.php',
    ];

    $violations = [];

    foreach ($files as $file) {
        $content = (string) file_get_contents($file);
        $relativePath = str_replace($projectRoot.'/', '', $file);

        if (str_contains($content, 'text-sm text-base-content/60') || str_contains($content, 'text-base-content/60 text-sm')) {
            $violations[] = "{$relativePath} contains deprecated 'text-sm text-base-content/60' (expected 'text-xs text-base-content/60')";
        }

        if (preg_match_all('/class=[\x22\x27]([^\x22\x27]*)[\x22\x27]/', $content, $matches)) {
            foreach ($matches[1] as $classes) {
                if (preg_match('/\btext-xs\b/', $classes) && preg_match('/\btext-base-content\/(\d+)\b/', $classes, $opMatch)) {
                    $op = (int) $opMatch[1];
                    if (! in_array($op, [60, 70], true) && ! in_array($relativePath, $allowedExceptions, true)) {
                        $violations[] = "{$relativePath}: text-xs has non-standard opacity /{$op} in '{$classes}'";
                    }
                }
            }
        }
    }

    expect($violations)->toBeEmpty(
        'Secondary helper copy must use text-xs text-base-content/60 or text-xs text-base-content/70: '.implode("\n", $violations)
    );
});

it('forbids w-2 h-2 and w-8 h-8 sizes and confines w-3 h-3 and w-6 h-6 to explicit allowlists', function (): void {
    $projectRoot = base_path();
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($projectRoot.'/app-modules')) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = $file->getPathname();
        }
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($projectRoot.'/resources/views')) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = $file->getPathname();
        }
    }

    $w3Allowlist = [
        'app-modules/security/resources/views/partials/status-overview-cards.blade.php',
        'app-modules/security/resources/views/tabs/firewall-rules.blade.php',
        'app-modules/security/resources/views/tabs/partials/firewall-services.blade.php',
        'app-modules/security/resources/views/tabs/attackers.blade.php',
        'app-modules/security/resources/views/tabs/partials/sip-scanner-section.blade.php',
        'app-modules/security/resources/views/tabs/geo-blocking.blade.php',
        'app-modules/active-calls/resources/views/active-calls-list.blade.php',
        'app-modules/admin/resources/views/settings-edit.blade.php',
        'app-modules/admin/resources/views/git-update.blade.php',
        'app-modules/admin/resources/views/partials/git-channels.blade.php',
        'app-modules/admin/resources/views/partials/git-terminal.blade.php',
        'app-modules/operator-panel/resources/views/operator-panel-index.blade.php',
        'resources/views/layouts/app.blade.php',
        'resources/views/components/common/language-switcher.blade.php',
    ];

    $w6Allowlist = [
        'app-modules/security/resources/views/modals/confirm-disable-prefilter.blade.php',
        'app-modules/security/resources/views/partials/cockpit-banners.blade.php',
        'app-modules/security/resources/views/tabs/attackers.blade.php',
        'app-modules/security/resources/views/tabs/partials/banned-attackers-section.blade.php',
        'app-modules/security/resources/views/drawers/protection-settings.blade.php',
        'app-modules/admin/resources/views/monitoring.blade.php',
        'resources/views/pages/home.blade.php',
    ];

    $violations = [];

    foreach ($files as $file) {
        $content = (string) file_get_contents($file);
        $relativePath = str_replace($projectRoot.'/', '', $file);

        if (preg_match('/\b(?:w-2\s+h-2|h-2\s+w-2)\b/', $content)) {
            $violations[] = "{$relativePath} contains forbidden 'w-2 h-2'";
        }

        if (preg_match('/\b(?:w-8\s+h-8|h-8\s+w-8)\b/', $content)) {
            $violations[] = "{$relativePath} contains forbidden 'w-8 h-8'";
        }

        if (preg_match('/\b(?:w-3\s+h-3|h-3\s+w-3)\b/', $content) && ! in_array($relativePath, $w3Allowlist, true)) {
            $violations[] = "{$relativePath} contains un-allowlisted 'w-3 h-3'";
        }

        if (preg_match('/\b(?:w-6\s+h-6|h-6\s+w-6)\b/', $content) && ! in_array($relativePath, $w6Allowlist, true)) {
            $violations[] = "{$relativePath} contains un-allowlisted 'w-6 h-6'";
        }
    }

    expect($violations)->toBeEmpty(
        'Icon sizing must follow standard scales (w-4 h-4, w-5 h-5), forbidding w-2 h-2 / w-8 h-8 and allowlisting w-3 h-3 / w-6 h-6: '.implode("\n", $violations)
    );
});

it('enforces accessible names on all toggle and checkbox inputs', function (): void {
    $projectRoot = base_path();
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($projectRoot.'/app-modules')) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = $file->getPathname();
        }
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($projectRoot.'/resources/views')) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = $file->getPathname();
        }
    }

    $violations = [];

    foreach ($files as $file) {
        $content = (string) file_get_contents($file);
        $relativePath = str_replace($projectRoot.'/', '', $file);

        if ($relativePath === 'resources/views/layouts/app.blade.php') {
            continue;
        }

        $offset = 0;
        $len = strlen($content);
        while (($pos = stripos($content, '<input', $offset)) !== false) {
            $end = false;
            for ($i = $pos + 6; $i < $len; $i++) {
                $c = $content[$i];
                if ($c === '>') {
                    if ($i > 0 && ($content[$i - 1] === '-' || $content[$i - 1] === '=')) {
                        continue;
                    }
                    $end = $i;
                    break;
                }
            }

            if ($end === false) {
                break;
            }

            $tag = substr($content, $pos, $end - $pos + 1);
            $offset = $end + 1;

            if (! preg_match('/class=[\x22\x27][^\x22\x27]*\b(toggle|checkbox)\b[^\x22\x27]*[\x22\x27]/i', $tag)) {
                continue;
            }

            if (preg_match('/\baria-label(ledby)?=/i', $tag)) {
                continue;
            }

            if (preg_match('/\bid=[\x22\x27]([^\x22\x27]+)[\x22\x27]/i', $tag, $idMatch)) {
                $id = preg_quote($idMatch[1], '/');
                if (preg_match('/<label\b[^>]*\bfor=[\x22\x27]'.$id.'[\x22\x27][^>]*>(.*?)<\/label>/is', $content, $labelForMatch)) {
                    $labelClean = trim(strip_tags((string) $labelForMatch[1]));
                    if (! empty($labelClean)) {
                        continue;
                    }
                }
            }

            $before = substr($content, 0, $pos);
            $after = substr($content, $pos + strlen($tag));
            $lastLabelOpen = strrpos($before, '<label');
            $lastLabelClose = strrpos($before, '</label>');
            $nextLabelClose = strpos($after, '</label>');
            $nextLabelOpen = strpos($after, '<label');

            if ($lastLabelOpen !== false && ($lastLabelClose === false || $lastLabelOpen > $lastLabelClose)) {
                if ($nextLabelClose !== false && ($nextLabelOpen === false || $nextLabelClose < $nextLabelOpen)) {
                    $labelBlock = substr($before, $lastLabelOpen).$tag.substr($after, 0, $nextLabelClose);
                    $labelClean = trim(strip_tags((string) preg_replace('/<[^>]+>/', '', $labelBlock)));
                    if (! empty($labelClean)) {
                        continue;
                    }
                }
            }

            $violations[] = "{$relativePath}: input missing accessible label -> ".preg_replace('/\s+/', ' ', $tag);
        }
    }

    expect($violations)->toBeEmpty(
        'Every toggle/checkbox input must carry an aria-label, aria-labelledby, or be associated with non-empty label text: '.implode("\n", $violations)
    );
});

it('renders accessible x-tabs component with tablist and aria-selected', function (): void {
    $rendered = Blade::render('<x-tabs :tabs="[\'general\' => \'General\']" active="general" wire-method="setTab" />');

    expect($rendered)
        ->toContain('role="tablist"')
        ->toContain('role="tab"')
        ->toContain('aria-selected="true"');
});

it('enforces all top-level blade views and tabs are 300 lines or fewer', function (): void {
    $projectRoot = base_path();

    $files = array_merge(
        glob($projectRoot.'/app-modules/*/resources/views/*.blade.php') ?: [],
        glob($projectRoot.'/app-modules/*/resources/views/tabs/*.blade.php') ?: [],
    );

    expect(count($files))->toBeGreaterThan(50);

    $oversized = [];

    foreach ($files as $file) {
        $lines = count(file($file));
        if ($lines > 300) {
            $relativePath = str_replace($projectRoot.'/', '', $file);
            $oversized[] = "{$relativePath} has {$lines} lines (max 300)";
        }
    }

    expect($oversized)->toBeEmpty(
        "Blade views must not exceed 300 lines. Extract cohesive sub-partials into partials/: \n".implode("\n", $oversized)
    );
});
