<?php

declare(strict_types=1);

namespace Modules\EmailQueue\Services;

use App\Support\CrudService;
use Modules\EmailQueue\Models\EmailQueueItem;

/**
 * CRUD service for the EmailQueueItem model.
 */
class EmailQueueService extends CrudService
{
    public function __construct()
    {
        $this->modelClass = EmailQueueItem::class;
    }
}
