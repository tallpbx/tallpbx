<?php

declare(strict_types=1);

namespace Modules\EventRateLimits\Livewire;

use App\Services\SettingServiceInterface;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Admin form for configuring FreeSWITCH event rate limiting.
 *
 * Uses the SettingService to persist event rate limit thresholds.
 */
#[Layout('layouts.app')]
class EventRateLimitsSettings extends Component
{
    public int $maxEventsPerMinute = 300;

    public int $burstLimit = 50;

    public int $blockDuration = 60;

    private SettingServiceInterface $settings;

    /**
     * Inject the settings service used by this component.
     */
    public function boot(SettingServiceInterface $settings): void
    {
        $this->settings = $settings;
    }

    /**
     * Load the saved rate limit thresholds when the page opens.
     */
    public function mount(): void
    {
        $this->maxEventsPerMinute = (int) ($this->settings->get('event_rate_limits.max_events_per_minute') ?? 300);
        $this->burstLimit = (int) ($this->settings->get('event_rate_limits.burst_limit') ?? 50);
        $this->blockDuration = (int) ($this->settings->get('event_rate_limits.block_duration') ?? 60);
    }

    /**
     * Validate the form and persist the rate limit thresholds.
     */
    public function save(): void
    {
        $this->validate();

        $this->settings->set('event_rate_limits.max_events_per_minute', $this->maxEventsPerMinute, 'integer');
        $this->settings->set('event_rate_limits.burst_limit', $this->burstLimit, 'integer');
        $this->settings->set('event_rate_limits.block_duration', $this->blockDuration, 'integer');

        $this->dispatch('notify', message: __('admin.settings_saved'));
    }

    /**
     * Validation rules for the event rate limits form.
     */
    public function rules(): array
    {
        return [
            'maxEventsPerMinute' => 'required|integer|min:1',
            'burstLimit' => 'required|integer|min:1',
            'blockDuration' => 'required|integer|min:1',
        ];
    }

    /**
     * Render the event rate limits form.
     */
    public function render(): View
    {
        return view('event-rate-limits::event-rate-limits-settings');
    }
}
