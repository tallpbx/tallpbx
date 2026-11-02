<?php

declare(strict_types=1);

namespace Modules\CallDetailRecords\Services;

use App\Support\CrudService;
use Modules\CallDetailRecords\Models\Cdr;

/**
 * CRUD service for the Cdr model.
 */
class CdrService extends CrudService
{
    /**
     * Tell the shared CRUD base class which model it manages.
     */
    public function __construct()
    {
        $this->modelClass = Cdr::class;
    }
}
