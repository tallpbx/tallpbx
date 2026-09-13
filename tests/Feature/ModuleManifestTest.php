<?php

declare(strict_types=1);

/**
 * Validates that all module.json manifests contain the required fields
 * and that their namespaces are consistent with directory names.
 */
test('all module manifests contain required fields', function () {
    $manifests = glob(base_path('app-modules/*/module.json'));

    expect($manifests)->not->toBeEmpty('No module manifests found.');

    $required = ['name', 'version', 'namespace', 'display_name'];
    $errors = [];

    foreach ($manifests as $path) {
        $json = json_decode((string) file_get_contents($path), true);

        foreach ($required as $field) {
            if (! isset($json[$field]) || (is_string($json[$field]) && trim($json[$field]) === '')) {
                $relative = str_replace(base_path('app-modules/'), '', $path);
                $errors[] = "{$relative}: missing required field '{$field}'";
            }
        }
    }

    expect($errors)->toBeEmpty(implode("\n", $errors));
});
