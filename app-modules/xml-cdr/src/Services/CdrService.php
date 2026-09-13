<?php

declare(strict_types=1);

namespace Modules\XmlCdr\Services;

use App\Support\CrudService;
use Modules\XmlCdr\Models\Cdr;

/**
 * CRUD service for the Cdr model.
 */
class CdrService extends CrudService
{
    public function __construct()
    {
        $this->modelClass = Cdr::class;
    }
}
