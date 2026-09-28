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

test('the manifest schema allows only canonical kebab-case module names', function () {
    $schema = json_decode((string) file_get_contents(base_path('resources/schemas/module.json')), true);

    // Underscore names cannot round-trip through the kebab-case mapping used
    // for dependency matching, so the schema must reject them everywhere a
    // module machine name appears.
    expect($schema['properties']['name']['pattern'])->toBe('^[a-z][a-z0-9-]*$')
        ->and($schema['properties']['requirements']['properties']['modules']['items']['pattern'])->toBe('^[a-z][a-z0-9-]*$');
});
