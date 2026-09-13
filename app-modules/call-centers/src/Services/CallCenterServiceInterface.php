<?php

declare(strict_types=1);

namespace Modules\CallCenters\Services;

use Modules\CallCenters\Models\Queue;

interface CallCenterServiceInterface
{
    public function createQueue(array $data): Queue;

    public function updateQueue(Queue $queue, array $data): Queue;

    public function deleteQueue(Queue $queue): void;
}
