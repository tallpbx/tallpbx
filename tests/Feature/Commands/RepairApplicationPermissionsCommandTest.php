<?php

declare(strict_types=1);

// Confirm the public command accepts the lightweight generated-file scope.
it('runs the generated permission repair scope', function (): void {
    $this->artisan('permissions:repair', ['--scope' => 'generated'])
        ->expectsOutputToContain('generated permission repair completed.')
        ->assertSuccessful();
});

// Reject typos so operators cannot accidentally request an undefined scope.
it('rejects an unknown permission repair scope', function (): void {
    $this->artisan('permissions:repair', ['--scope' => 'unknown'])
        ->expectsOutputToContain('The --scope option must be generated, runtime, or full.')
        ->assertFailed();
});
