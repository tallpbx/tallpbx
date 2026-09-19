<?php

declare(strict_types=1);

namespace App\Support\Concerns;

/** Provides short-lived, safe page-level feedback for Livewire operational actions. */
trait HasOperationalFeedback
{
    private const SessionFeedbackKey = 'operational-feedback';

    public ?string $operationalMessage = null;

    public ?string $operationalMessageType = null;

    /** Show a safe success message after an action completes. */
    protected function showSuccess(string $message): void
    {
        $this->showOperationalMessage('success', $message);
    }

    /** Show a safe warning message when attention is required. */
    protected function showWarning(string $message): void
    {
        $this->showOperationalMessage('warning', $message);
    }

    /** Show a safe operational error without exposing unexpected exceptions. */
    protected function showError(string $message): void
    {
        $this->showOperationalMessage('error', $message);
    }

    /** Show useful neutral information about an action or state. */
    protected function showInfo(string $message): void
    {
        $this->showOperationalMessage('info', $message);
    }

    /** Store a safe success message for the panel page shown after a redirect. */
    protected function flashSuccess(string $message): void
    {
        session()->flash(self::SessionFeedbackKey, ['type' => 'success', 'message' => $message]);
    }

    /** Restore and clear safe feedback passed from the preceding panel request. */
    protected function consumeOperationalFeedback(): void
    {
        $feedback = session()->pull(self::SessionFeedbackKey);

        if (! is_array($feedback)
            || ! isset($feedback['type'], $feedback['message'])
            || ! is_string($feedback['type'])
            || ! is_string($feedback['message'])
            || ! in_array($feedback['type'], ['success', 'warning', 'error', 'info'], true)) {
            return;
        }

        $this->showOperationalMessage($feedback['type'], $feedback['message']);
    }

    /**
     * Dismiss the current feedback toast and forget flashed status or error
     * messages so the message does not reappear after the next re-render.
     *
     * Invoked by the shared <x-operational-toast> close button.
     */
    public function dismissFeedback(): void
    {
        $this->clearOperationalMessage();
        session()->forget(['status', 'error']);
    }

    /** Clear the current page-level feedback message. */
    protected function clearOperationalMessage(): void
    {
        $this->operationalMessage = null;
        $this->operationalMessageType = null;
    }

    /** Store one semantic feedback message for the next component render. */
    private function showOperationalMessage(string $type, string $message): void
    {
        $this->operationalMessageType = $type;
        $this->operationalMessage = $message;
    }
}
