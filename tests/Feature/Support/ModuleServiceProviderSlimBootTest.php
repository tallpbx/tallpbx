<?php

declare(strict_types=1);

use App\Services\MenuService;
use App\Services\ModuleState;
use App\Services\PermissionService;
use App\Support\ModuleServiceProvider as BaseModuleServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;

/*
 * Slim telephony boot gate for module providers.
 *
 * The telephony renderers — the Octane XML worker and the fast XML
 * controller — only answer FreeSWITCH lookups and ingest call detail records,
 * so their module boot must skip the panel stack (views, translations, panel
 * routes, Livewire components, menus, permissions, console commands) while
 * keeping the two things the telephony paths need: module config merges and
 * event listeners (the telephony write path depends on listeners).
 *
 * Both renderers share ONE gate — the TALLPBX_WORKER_ROLE=telephony flag — so
 * the gate contract is pinned here once. The rendered documents are pinned by
 * the byte-equality suites: XmlHandlerFastPathTest (fast controller) and
 * XmlHandlerOctaneParityTest (worker leg).
 */

/**
 * Build a fixture module provider rooted at tests/Fixtures/slim-module.
 *
 * The fixture is not a real module: it exists so boot() side effects can be
 * observed on a namespace no registered module has already touched.
 */
function slimBootFixtureProvider(): BaseModuleServiceProvider
{
    return new class(app(), base_path('tests/Fixtures/slim-module')) extends BaseModuleServiceProvider
    {
        /**
         * Absolute path to the fixture directory.
         */
        private string $fixturePath;

        /**
         * Bind the fixture path the parent class would otherwise resolve
         * through reflection over the provider's file location.
         */
        public function __construct($app, string $fixturePath)
        {
            parent::__construct($app);

            $this->fixturePath = $fixturePath;
        }

        /**
         * The fixture's kebab-case name, used as its config and view namespace.
         */
        protected function moduleName(): string
        {
            return 'slim-fixture';
        }

        /**
         * The fixture's PHP namespace (nothing resolves classes from it).
         */
        protected function moduleNamespace(): string
        {
            return 'Tests\\Fixtures\\Slim';
        }

        /**
         * Point the provider at the fixture directory instead of a module root.
         */
        protected function modulePath(): string
        {
            return $this->fixturePath;
        }

        /**
         * One listener on a fixture-only event so listener registration is
         * observable without touching any real event.
         *
         * @return array<string, class-string>
         */
        protected function listeners(): array
        {
            return ['slim-fixture.handled' => stdClass::class];
        }
    };
}

/**
 * Run a callback with TALLPBX_WORKER_ROLE=telephony set the way the worker's
 * systemd unit sets it, restoring the previous value afterwards so the flag
 * can never leak into a later test (or, in production, a later request).
 */
function withTelephonyRole(callable $callback): mixed
{
    $previousRole = getenv('TALLPBX_WORKER_ROLE');

    putenv('TALLPBX_WORKER_ROLE=telephony');
    $_ENV['TALLPBX_WORKER_ROLE'] = 'telephony';
    $_SERVER['TALLPBX_WORKER_ROLE'] = 'telephony';

    try {
        return $callback();
    } finally {
        if ($previousRole === false) {
            putenv('TALLPBX_WORKER_ROLE');
            unset($_ENV['TALLPBX_WORKER_ROLE'], $_SERVER['TALLPBX_WORKER_ROLE']);
        } else {
            putenv("TALLPBX_WORKER_ROLE={$previousRole}");
            $_ENV['TALLPBX_WORKER_ROLE'] = $previousRole;
            $_SERVER['TALLPBX_WORKER_ROLE'] = $previousRole;
        }
    }
}

/**
 * Boot the fixture provider against the test container with a module state
 * that reports every module enabled, so the enabled-gated registrations
 * (listeners) run for the fixture like they do for a real telephony module.
 */
function bootSlimFixture(BaseModuleServiceProvider $provider): void
{
    $alwaysEnabled = new class extends ModuleState
    {
        /**
         * Treat every module as enabled so the enabled-gated registrations run.
         */
        public function isEnabled(string $module): bool
        {
            return true;
        }

        /**
         * Never force migrations for the fixture.
         */
        public function shouldMigrateAll(): bool
        {
            return false;
        }
    };

    $provider->boot(
        app(MenuService::class),
        app(PermissionService::class),
        $alwaysEnabled,
    );
}

it('skips panel registrations during a slim telephony boot', function (): void {
    withTelephonyRole(function (): void {
        bootSlimFixture(slimBootFixtureProvider());
    });

    // View namespaces are panel-only: nothing renders a Blade view on the
    // FreeSWITCH lookup or CDR ingestion paths, so a slim boot must not
    // register them.
    expect(View::getFinder()->getHints())->not->toHaveKey('slim-fixture');
});

it('keeps config merges and event listeners during a slim telephony boot', function (): void {
    withTelephonyRole(function (): void {
        bootSlimFixture(slimBootFixtureProvider());
    });

    // Services read module config at render time, and listeners guard the
    // telephony write path, so both must survive the slim boot. This is the
    // regression guard against over-skipping when the gate is tightened.
    expect(config('slim-fixture.probed'))->toBe('merged')
        ->and(Event::hasListeners('slim-fixture.handled'))->toBeTrue();
});

it('registers the full panel stack for a normal boot', function (): void {
    bootSlimFixture(slimBootFixtureProvider());

    // The gate must only change telephony renderer boots: a normal boot keeps
    // the module's panel registrations. This also proves the fixture itself
    // registers, so the slim-boot absence above means what it claims.
    expect(View::getFinder()->getHints())->toHaveKey('slim-fixture');
});
