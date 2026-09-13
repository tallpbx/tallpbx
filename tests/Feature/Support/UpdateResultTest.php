<?php

declare(strict_types=1);

use App\Support\UpdateResult;

it('builds a successful update result with steps', function (): void {
    $result = UpdateResult::success('main', [
        ['label' => 'Pull', 'status' => 'ok', 'output' => 'Already up to date.'],
    ]);

    expect($result->success)->toBeTrue()
        ->and($result->target)->toBe('main')
        ->and($result->steps)->toHaveCount(1)
        ->and($result->rollbackReport)->toBeNull();
});

it('builds a failed update result with a rollback report', function (): void {
    $result = UpdateResult::failed('main', 'Migrate failed.', [], 'Rolled back to abc123.');

    expect($result->success)->toBeFalse()
        ->and($result->reason)->toBe('Migrate failed.')
        ->and($result->rollbackReport)->toBe('Rolled back to abc123.');
});
