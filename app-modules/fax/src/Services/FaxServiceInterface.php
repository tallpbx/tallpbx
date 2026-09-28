<?php

declare(strict_types=1);

namespace Modules\Fax\Services;

use Modules\Fax\Models\FaxInbox;
use Modules\Fax\Models\FaxOutgoing;

/**
 * Service interface for the fax lifecycle: sending, receiving,
 * completion, and inbox deletion.
 */
interface FaxServiceInterface
{
    /**
     * Queue an uploaded document as an outbound fax.
     */
    public function send(array $data): FaxOutgoing;

    /**
     * Record an inbound fax received from FreeSWITCH.
     */
    public function receive(array $data): FaxInbox;

    /**
     * Mark an outbound fax as completed with the final delivery status.
     */
    public function completeOutgoing(FaxOutgoing $fax, string $status): void;

    /**
     * Delete a received fax and schedule its stored document for removal.
     */
    public function deleteInbox(FaxInbox $fax): void;
}
