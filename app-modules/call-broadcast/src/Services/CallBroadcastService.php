<?php

declare(strict_types=1);

namespace Modules\CallBroadcast\Services;

use App\Support\CrudService;
use Illuminate\Support\Facades\DB;
use Modules\CallBroadcast\Models\CallBroadcast;

/**
 * CRUD service for the CallBroadcast model.
 */
class CallBroadcastService extends CrudService
{
    public function __construct()
    {
        $this->modelClass = CallBroadcast::class;
    }

    /**
     * Create a draft broadcast together with its parsed recipients.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $phoneNumbers  Validated, deduplicated numbers
     */
    public function createWithRecipients(array $data, array $phoneNumbers): CallBroadcast
    {
        return DB::transaction(function () use ($data, $phoneNumbers): CallBroadcast {
            $broadcast = CallBroadcast::create($data);

            foreach ($phoneNumbers as $number) {
                $broadcast->recipients()->create([
                    'phone_number' => $number,
                    'call_status' => 'pending',
                ]);
            }

            return $broadcast->load('recipients');
        });
    }
}
