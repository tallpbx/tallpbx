<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Contract for PBX feature modules that contribute dialplan XML
 * to FreeSWITCH call routing.
 *
 * Modules implementing this interface register themselves as tagged
 * services ('dialplan.xml' tag). The DialplanXmlCollector gathers
 * all contributors and includes their XML in the dialplan section
 * served to FreeSWITCH via mod_xml_curl.
 *
 * Each contributor receives the resolved tenant ID, the target
 * dialplan context (internal or public), and the destination
 * number. It returns an XML fragment string or null if it has
 * no rules for the given parameters.
 *
 * Contributors are sorted by priority (lower = earlier in the
 * dialplan). The priority determines where the contributor's XML
 * appears relative to other contributors. Built-in priorities
 * should use the constants defined on DialplanXmlCollector.
 *
 * Example return value:
 *
 *   <extension name="my_module_rule">
 *     <condition field="destination_number" expression="^100$">
 *       <action application="bridge" data="user/100"/>
 *     </condition>
 *   </extension>
 */
interface DialplanXmlContributor
{
    /**
     * The priority of this contributor in the dialplan.
     *
     * Lower values appear earlier in the generated dialplan XML.
     * Emergency and blocking rules should use low priorities (e.g., 10-20),
     * mid-tier feature rules around 50-70, and catch-all routing at 90+.
     *
     * @return int The sort priority (lower = earlier in the dialplan)
     */
    public function getDialplanPriority(): int;

    /**
     * Generate dialplan XML for the given tenant and context.
     *
     * @param  int  $tenantId  The resolved tenant ID
     * @param  string  $context  The FreeSWITCH context (e.g., tenant_{uuid}_public)
     * @param  string  $destination  The called number
     * @return string|null XML fragment to include in the dialplan, or null if
     *                     this contributor has no matching rules
     */
    public function generateDialplanXml(int $tenantId, string $context, string $destination): ?string;
}
