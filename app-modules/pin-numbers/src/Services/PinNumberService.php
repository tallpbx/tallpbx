<?php

declare(strict_types=1);

namespace Modules\PinNumbers\Services;

use App\Contracts\DialplanXmlContributor;
use App\Support\CrudService;
use App\Support\RoutingCacheVersion;
use Illuminate\Database\Eloquent\Model;
use Modules\PinNumbers\Models\PinNumber;

/**
 * CRUD service for the PinNumber model.
 *
 * Also contributes the interactive PIN routing dialplan flow: dialing the
 * configured trigger answers, collects a PIN, validates it against the
 * tenant's enabled PINs, then collects a destination and transfers into
 * normal routing. Every write bumps the tenant's routing revision so the
 * cached dialplan (including the PIN union regex) rebuilds immediately.
 */
class PinNumberService extends CrudService implements DialplanXmlContributor
{
    public function __construct()
    {
        $this->modelClass = PinNumber::class;
    }

    /**
     * Create a PIN and invalidate the cached dialplan.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): PinNumber
    {
        $pin = parent::create($data);

        RoutingCacheVersion::bump((int) $data['tenant_id']);

        return $pin;
    }

    /**
     * Update a PIN and invalidate the cached dialplan.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Model $pin, array $data): Model
    {
        $pin = parent::update($pin, $data);

        RoutingCacheVersion::bump((int) $pin->tenant_id);

        return $pin;
    }

    /**
     * Delete a PIN and invalidate the cached dialplan.
     */
    public function delete(Model $pin): void
    {
        parent::delete($pin);

        RoutingCacheVersion::bump((int) $pin->tenant_id);
    }

    /**
     * The PIN flow runs above feature codes (50) so the trigger is
     * matched before outbound or inbound routing.
     */
    public function getDialplanPriority(): int
    {
        return 40;
    }

    /**
     * Generate the interactive PIN access flow.
     *
     * Extension 1 (pin_access) matches the configured trigger, answers,
     * collects the PIN into pin_attempt, and transfers into the dialplan.
     * Extension 2 (pin_destination) gates on the collected PIN against
     * the pre-computed union of enabled tenant PINs, collects the
     * destination, and transfers back into routing. Any unmatched PIN
     * falls through to NO_ROUTE_DESTINATION (fail closed).
     *
     * @return string|null XML fragment, or null when no PINs are enabled
     */
    public function generateDialplanXml(int $tenantId, string $context, string $destination): ?string
    {
        $pins = PinNumber::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('enabled', true)
            // An empty pin would match an empty pin_attempt (validation bypass).
            ->where('pin_number', '!=', '')
            ->orderBy('pin_number')
            ->pluck('pin_number');

        if ($pins->isEmpty()) {
            return null;
        }

        $escape = fn (string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $trigger = (string) config('freeswitch.xml_handler.pin_trigger', '*97');
        // preg_quote for the regex, then XML-escape: the pattern lands in an
        // XML attribute, so both layers must be safe.
        $triggerPattern = $escape(preg_quote($trigger, '/'));
        $pinUnion = '^('.implode('|', $pins->map(fn (string $pin): string => preg_quote($pin, '/'))->all()).')$';
        // The context comes from the mod_xml_curl request and is embedded in
        // transfer data attributes — escape it like every sibling code path.
        $escapedContext = $escape($context);

        $xml = "      <extension name=\"pin_access\">\n";
        $xml .= "        <condition field=\"destination_number\" expression=\"^{$triggerPattern}$\">\n";
        $xml .= "          <action application=\"answer\"/>\n";
        $xml .= '          <action application="play_and_get_digits" data="2 20 3 5000 # phrase:pin_number_enter phrase:pin_number_enter pin_attempt \d+"/>'."\n";
        $xml .= "          <action application=\"transfer\" data=\"pin_destination XML {$escapedContext}\"/>\n";
        $xml .= "        </condition>\n";
        $xml .= "      </extension>\n";
        $xml .= "      <extension name=\"pin_destination\">\n";
        $xml .= "        <condition field=\"destination_number\" expression=\"^pin_destination$\">\n";
        $xml .= '          <condition field="${pin_attempt}" expression="'.$escape($pinUnion).'">'."\n";
        $xml .= '            <action application="play_and_get_digits" data="1 15 2 5000 # phrase:pin_number_destination phrase:pin_number_destination pin_destination \d+"/>'."\n";
        $xml .= '            <action application="set" data="destination_number=${pin_destination}"/>'."\n";
        $xml .= '            <action application="transfer" data="${pin_destination} XML '.$escapedContext.'"/>'."\n";
        $xml .= "          </condition>\n";
        $xml .= "        </condition>\n";
        $xml .= "      </extension>\n";

        return $xml;
    }
}
