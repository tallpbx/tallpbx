<?php

declare(strict_types=1);

namespace Modules\Certificates\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Modules\Certificates\Models\Certificate;

/**
 * Alerts administrators when an automated ACME certificate renewal fails.
 */
class CertificateRenewalFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     *
     * @param Certificate $certificate The certificate that failed renewal
     * @param string $errorMessage The failure reason or error output
     */
    public function __construct(
        public readonly Certificate $certificate,
        public readonly string $errorMessage,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @param object $notifiable The entity receiving the notification
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the array representation of the notification.
     *
     * @param object $notifiable The entity receiving the notification
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Certificate Renewal Failed',
            'message' => "Automated renewal failed for '{$this->certificate->name}' ({$this->certificate->common_name}): {$this->errorMessage}",
            'certificate_id' => $this->certificate->id,
            'common_name' => $this->certificate->common_name,
            'error' => $this->errorMessage,
            'is_default_web' => $this->certificate->is_default_web,
            'is_default_telephony' => $this->certificate->is_default_telephony,
        ];
    }
}
