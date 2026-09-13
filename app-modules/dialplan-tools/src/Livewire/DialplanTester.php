<?php

declare(strict_types=1);

namespace Modules\DialplanTools\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Admin tool for testing regular expression patterns against phone numbers.
 *
 * Provides a simple form where admins can input a regex pattern
 * and a test phone number, then see whether the pattern matches
 * and what capture groups are extracted. Useful for testing
 * dialplan translation rules before deploying them.
 */
#[Layout('layouts.app')]
class DialplanTester extends Component
{
    /** The regex pattern to test. */
    public string $testPattern = '';

    /** The phone number to test against. */
    public string $testNumber = '';

    /** Extracted capture groups from a successful match. */
    public array $matchGroups = [];

    /** Whether the last pattern test produced a match. */
    public bool $hasMatch = false;

    /** Whether a test has been performed yet. */
    public bool $tested = false;

    /**
     * Run the regex pattern against the test number.
     *
     * Uses preg_match to evaluate the pattern. Capture groups
     * are extracted and stored in $matchGroups for display.
     */
    public function testRegex(): void
    {
        $this->validate();
        $this->tested = true;
        $this->matchGroups = [];

        $matched = preg_match('/'.str_replace('/', '\/', $this->testPattern).'/', $this->testNumber, $matches);

        if ($matched) {
            $this->hasMatch = true;
            $this->matchGroups = array_slice($matches, 1);
        } else {
            $this->hasMatch = false;
        }
    }

    /**
     * Validation rules for the regex tester form.
     */
    public function rules(): array
    {
        return [
            'testPattern' => 'required|string|max:500',
            'testNumber' => 'required|string|max:255',
        ];
    }

    /**
     * Render the dialplan tester view.
     */
    public function render(): View
    {
        return view('dialplan-tools::dialplan-tester');
    }
}
