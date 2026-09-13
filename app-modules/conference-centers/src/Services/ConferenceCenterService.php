<?php

declare(strict_types=1);

namespace Modules\ConferenceCenters\Services;

use App\Contracts\ContextWideDialplanXmlContributor;
use App\Support\CrudService;
use Illuminate\Database\Eloquent\Model;
use Modules\ConferenceCenters\Models\ConferenceCenter;
use Modules\FileStores\Services\MediaStorageServiceInterface;

/**
 * CRUD service for the ConferenceCenter model with FreeSWITCH
 * dialplan XML generation.
 */
class ConferenceCenterService extends CrudService implements ContextWideDialplanXmlContributor
{
    /**
     * Feature-level routing — conference centers match after emergency/blocks/DID.
     */
    public function getDialplanPriority(): int
    {
        return 70;
    }

    public function __construct(private readonly MediaStorageServiceInterface $mediaStorage)
    {
        $this->modelClass = ConferenceCenter::class;
    }

    /** Delete the managed greeting before deleting the conference center. */
    public function delete(Model $record): void
    {
        /** @var ConferenceCenter $record */
        if ($record->mediaAsset !== null) {
            $this->mediaStorage->requestDeletion($record->mediaAsset->id);
        }

        parent::delete($record);
    }

    /**
     * Generate dialplan XML for conference centers.
     *
     * A conference center is a collection of conference rooms with
     * a shared number. Callers dial the center number and are
     * prompted to enter a room number via the conference center app.
     */
    public function generateDialplanXml(int $tenantId, string $context, string $destination): ?string
    {
        $centers = ConferenceCenter::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('enabled', true)
            ->orderBy('name')
            ->get();

        if ($centers->isEmpty()) {
            return null;
        }

        $xml = '';

        foreach ($centers as $center) {
            $safeName = htmlspecialchars($center->name, ENT_XML1 | ENT_QUOTES, 'UTF-8');

            $xml .= "      <extension name=\"conf_center_{$safeName}\">\n";
            $xml .= "        <condition field=\"destination_number\" expression=\"^{$safeName}$\">\n";
            $xml .= "          <action application=\"answer\"/>\n";
            $xml .= "          <action application=\"sleep\" data=\"1000\"/>\n";
            $xml .= "          <action application=\"conference_center\" data=\"{$safeName}@default\"/>\n";
            $xml .= "        </condition>\n";
            $xml .= "      </extension>\n";
        }

        return $xml;
    }
}
