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
    /**
     * Tell the shared CRUD base class which model it manages.
     */
    public function __construct()
    {
        $this->modelClass = EmailQueueItem::class;
    }
}
