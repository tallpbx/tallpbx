<?php

declare(strict_types=1);

use App\Support\Concerns\HasOperationalFeedback;

it('stores each semantic operational feedback type and clears it', function (): void {
    $feedback = new class
    {
        use HasOperationalFeedback;

        public function success(): void
        {
            $this->showSuccess('Saved.');
        }

        public function warning(): void
        {
            $this->showWarning('Check this.');
        }

        public function error(): void
        {
            $this->showError('Could not continue.');
        }

        public function info(): void
        {
            $this->showInfo('Useful information.');
        }

        public function clear(): void
        {
            $this->clearOperationalMessage();
        }
    };

    $feedback->success();
    expect($feedback->operationalMessageType)->toBe('success')->and($feedback->operationalMessage)->toBe('Saved.');
    $feedback->warning();
    expect($feedback->operationalMessageType)->toBe('warning');
    $feedback->error();
    expect($feedback->operationalMessageType)->toBe('error');
    $feedback->info();
    expect($feedback->operationalMessageType)->toBe('info');
    $feedback->clear();
    expect($feedback->operationalMessage)->toBeNull()->and($feedback->operationalMessageType)->toBeNull();
});
