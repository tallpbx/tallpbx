<?php

declare(strict_types=1);

/**
 * Verifies the AGENTS.md code style directive: every class and every
 * method in the project carries a PHPDoc comment explaining what it does.
 *
 * The check tokenizes each PHP file, so text inside strings never counts
 * as a docblock. Anonymous classes and closures are exempt, matching the
 * rollout that completed the documentation pass.
 */

/**
 * List every PHP file the documentation rule applies to.
 *
 * Generated framework caches under bootstrap/cache are excluded because
 * Artisan regenerates them without docblocks.
 *
 * @return array<int, string> Absolute file paths, sorted for stable output
 */
function documentationScanTargets(): array
{
    $directories = ['app', 'app-modules', 'bootstrap', 'config', 'database', 'routes', 'tests'];
    $files = [];

    foreach ($directories as $directory) {
        $base = base_path($directory);

        if (! is_dir($base)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            if (str_contains($file->getPathname(), '/bootstrap/cache/')) {
                continue;
            }

            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

/**
 * Find classes and named functions in one file that lack a PHPDoc comment.
 *
 * @param  string  $path  Absolute path of the PHP file to inspect
 * @return array<int, string> One human-readable violation per missing docblock
 */
function documentationViolations(string $path): array
{
    $tokens = token_get_all((string) file_get_contents($path));
    $violations = [];

    // Modifiers may sit between the docblock and the declaration; they do
    // not break the docblock's adjacency.
    $modifiers = [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL, T_READONLY, T_VAR];

    $pendingDoc = false;
    $inAttribute = false;
    $attributeDepth = 0;
    $count = count($tokens);
    $shortPath = str_replace(base_path().'/', '', $path);

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        if (is_array($token)) {
            [$id, $text, $line] = $token;

            // Inside an attribute such as #[Layout('layouts.app')] only the
            // bracket depth matters; the attribute may precede a class.
            if ($inAttribute) {
                if ($text === '[' || $text === '(' || $text === '{') {
                    $attributeDepth++;
                } elseif ($text === ']' || $text === ')' || $text === '}') {
                    $attributeDepth--;

                    if ($attributeDepth <= 0) {
                        $inAttribute = false;
                    }
                }

                continue;
            }

            if ($id === T_ATTRIBUTE) {
                $inAttribute = true;
                $attributeDepth = 1;

                continue;
            }

            if ($id === T_WHITESPACE || $id === T_COMMENT) {
                continue;
            }

            if ($id === T_DOC_COMMENT) {
                $pendingDoc = true;

                continue;
            }

            if (in_array($id, $modifiers, true)) {
                continue;
            }

            if ($id === T_CLASS || $id === T_INTERFACE || $id === T_TRAIT || $id === T_ENUM) {
                // Anonymous classes (new class ...) and ::class constants do
                // not declare a documentable type.
                $previous = null;

                for ($j = $i - 1; $j >= 0; $j--) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                        continue;
                    }

                    $previous = $tokens[$j];
                    break;
                }

                $isAnonymous = is_array($previous) && $previous[0] === T_NEW;
                $isClassConstant = is_array($previous) && $previous[0] === T_DOUBLE_COLON;

                if (! $isAnonymous && ! $isClassConstant && ! $pendingDoc) {
                    $violations[] = $shortPath.':'.$line.' declares a type without a PHPDoc comment';
                }

                $pendingDoc = false;

                continue;
            }

            if ($id === T_FUNCTION) {
                // Closures and arrow functions have no name and are exempt.
                $name = null;

                for ($j = $i + 1; $j < $count; $j++) {
                    $next = $tokens[$j];

                    if ($next === '&' || (is_array($next) && $next[0] === T_WHITESPACE)) {
                        continue;
                    }

                    if (is_array($next) && $next[0] === T_STRING) {
                        $name = $next[1];
                    }

                    break;
                }

                if ($name !== null && ! $pendingDoc) {
                    $violations[] = $shortPath.':'.$line.' declares '.$name.'() without a PHPDoc comment';
                }

                $pendingDoc = false;

                continue;
            }

            // Any other significant token breaks docblock adjacency.
            $pendingDoc = false;

            continue;
        }

        // Plain string tokens (brackets, operators).
        if ($inAttribute) {
            if ($token === '[' || $token === '(' || $token === '{') {
                $attributeDepth++;
            } elseif ($token === ']' || $token === ')' || $token === '}') {
                $attributeDepth--;

                if ($attributeDepth <= 0) {
                    $inAttribute = false;
                }
            }

            continue;
        }

        $pendingDoc = false;
    }

    return $violations;
}

it('documents every class, method, and function with a PHPDoc comment', function (): void {
    $violations = [];

    foreach (documentationScanTargets() as $path) {
        $violations = array_merge($violations, documentationViolations($path));
    }

    expect($violations)->toBe([]);
});
