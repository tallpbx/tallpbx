<?php

declare(strict_types=1);

namespace Modules\Fax\Services;

use Modules\Fax\Models\FaxInbox;
use Modules\Fax\Models\FaxOutgoing;

interface FaxServiceInterface
{
    public function send(array $data): FaxOutgoing;

    public function receive(array $data): FaxInbox;

    public function completeOutgoing(FaxOutgoing $fax, string $status): void;

    public function deleteInbox(FaxInbox $fax): void;
}
