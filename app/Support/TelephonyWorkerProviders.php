<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The module providers the telephony worker registers.
 *
 * The list itself lives in configuration (`freeswitch.xml_module_providers`)
 * so the shared kernel never imports a module: `app/` must not depend on
 * module internals, which the architecture tests enforce. Both the persistent
 * worker and the fast XML bootstrap read the same list, because both render
 * the same documents.
 */
final class TelephonyWorkerProviders
{
    /**
     * Module providers required to render FreeSWITCH XML.
     *
     * Kept as class-name strings on purpose: this class belongs to the shared
     * kernel, and naming a module here with a `use` statement would make the
     * kernel depend on that module.
     *
     * @return list<class-string>
     */
    public static function modules(): array
    {
        /** @var list<class-string> $providers */
        $providers = array_values((array) config('freeswitch.xml_module_providers', []));

        return $providers;
    }
}
