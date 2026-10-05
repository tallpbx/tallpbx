<?php

declare(strict_types=1);

namespace Modules\Certificates\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Modules\Certificates\Models\Certificate;

/**
 * Alerts administrators when a TLS certificate is nearing its expiration date.
 */
class CertificateExpiringNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     *
     * @param Certificate $certificate The expiring certificate
     * @param int $daysRemaining Number of days before the certificate expires
     */
    public function __construct(
        public readonly Certificate $certificate,
        public readonly int $daysRemaining,
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
        $statusText = $this->daysRemaining <= 0
            ? 'has expired'
            : "expires in {$this->daysRemaining} days";

        return [
            'title' => 'TLS Certificate Expiration Warning',
            'message' => "Certificate '{$this->certificate->name}' ({$this->certificate->common_name}) {$statusText}.",
            'certificate_id' => $this->certificate->id,
            'common_name' => $this->certificate->common_name,
            'days_remaining' => $this->daysRemaining,
            'type' => $this->certificate->type,
            'is_default_web' => $this->certificate->is_default_web,
            'is_default_telephony' => $this->certificate->is_default_telephony,
        ];
    }
}
