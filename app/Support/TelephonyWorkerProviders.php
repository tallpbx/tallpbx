<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The module providers the telephony renderers register, and the one gate
 * that decides whether a boot is a slim telephony boot.
 *
 * The list itself lives in configuration (`freeswitch.xml_module_providers`)
 * so the shared kernel never imports a module: `app/` must not depend on
 * module internals, which the architecture tests enforce. Both the persistent
 * worker and the fast XML bootstrap read the same list, because both render
 * the same documents — and both decide their boot shape through
 * `isSlimBoot()` below, so their behavior can never drift apart.
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

    /**
     * Whether this process is booting as a slim telephony renderer.
     *
     * The two renderers share this one gate: the persistent Octane XML worker
     * carries the flag in its systemd unit, and the fast XML controller sets
     * it around its nested boot and restores it afterwards. When the flag is
     * present, module providers skip the panel stack (views, translations,
     * migrations, panel routes, Livewire components, menus, permissions and
     * console commands) and keep only what FreeSWITCH lookups and call detail
     * record ingestion need: config merges and event listeners. Every other
     * process — the panel included — boots the full stack.
     */
    public static function isSlimBoot(): bool
    {
        return env('TALLPBX_WORKER_ROLE') === 'telephony';
    }
}
