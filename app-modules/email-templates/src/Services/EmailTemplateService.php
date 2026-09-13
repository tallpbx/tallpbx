<?php

declare(strict_types=1);

namespace Modules\EmailTemplates\Services;

use App\Support\CrudService;
use Modules\EmailTemplates\Models\EmailTemplate;

/**
 * CRUD service for the EmailTemplate model.
 */
class EmailTemplateService extends CrudService
{
    public function __construct()
    {
        $this->modelClass = EmailTemplate::class;
    }
}
