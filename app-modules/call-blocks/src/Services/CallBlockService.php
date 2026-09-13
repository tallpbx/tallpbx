<?php

declare(strict_types=1);

namespace Modules\CallBlocks\Services;

use App\Contracts\ContextWideDialplanXmlContributor;
use Illuminate\Database\Eloquent\Collection;
use Modules\CallBlocks\Models\CallBlock;

/**
 * Service implementing call block CRUD operations with
 * FreeSWITCH dialplan XML generation.
 */
class CallBlockService implements CallBlockServiceInterface, ContextWideDialplanXmlContributor
{
    /**
     * Call blocks must run before any routing that connects calls.
     */
    public function getDialplanPriority(): int
    {
        return 20;
    }

    public function create(array $data): CallBlock
    {
        return CallBlock::create($data);
    }

    public function update(CallBlock $block, array $data): CallBlock
    {
        $block->update($data);

        return $block->fresh();
    }

    public function delete(CallBlock $block): void
    {
        $block->delete();
    }

    public function getByTenant(int $tenantId): Collection
    {
        return CallBlock::withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->orderBy('name')->get();
    }

    /**
     * Generate dialplan XML for call block rules.
     *
     * Each enabled call block rule matches the caller ID against a
     * pattern and rejects the call with a hangup. Uses the exported
     * orig_caller_id_number channel variable which captures the
     * pre-auth caller_id_number before authentication overwrites it.
     * This variable is exported at the start of every context dialplan
     * by XmlHandlerController::buildDialplanXml().
     *
     * Blocks are evaluated before any other routing rules to prevent
     * blocked callers from reaching destinations.
     */
    public function generateDialplanXml(int $tenantId, string $context, string $destination): ?string
    {
        $blocks = CallBlock::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('enabled', true)
            ->orderBy('name')
            ->get();

        if ($blocks->isEmpty()) {
            return null;
        }

        $xml = '';

        foreach ($blocks as $block) {
            $safeName = htmlspecialchars($block->name, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $safeCid = htmlspecialchars($block->caller_id_number, ENT_XML1 | ENT_QUOTES, 'UTF-8');

            $xml .= "      <extension name=\"block_{$safeName}\">\n";
            $xml .= "        <condition field=\"orig_caller_id_number\" expression=\"{$safeCid}\">\n";
            $xml .= "          <action application=\"log\" data=\"WARNING Call blocked: \${orig_caller_id_number} matches {$safeName}\"/>\n";
            $xml .= "          <action application=\"hangup\" data=\"CALL_REJECTED\"/>\n";
            $xml .= "        </condition>\n";
            $xml .= "      </extension>\n";
        }

        return $xml;
    }
}
