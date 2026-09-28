<?php

declare(strict_types=1);

namespace Modules\CallCenters\Services;

use Modules\CallCenters\Models\Queue;

/**
 * Service interface for call center queue CRUD operations.
 */
interface CallCenterServiceInterface
{
    /**
     * Create a new call center queue.
     */
    public function createQueue(array $data): Queue;

    /**
     * Update an existing queue and return the fresh copy.
     */
    public function updateQueue(Queue $queue, array $data): Queue;

    /**
     * Delete a queue.
     */
    public function deleteQueue(Queue $queue): void;
}
