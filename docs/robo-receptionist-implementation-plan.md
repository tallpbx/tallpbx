# Robo Receptionist Module Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the `robo-receptionist` module: an AI-agent-driven IVR where administrators configure system-wide AI provider credentials (assignable to tenants), per-tenant receptionist agents with TTS greeting text, voice selection, and intent-phrase routes pointing at standard PBX destinations — with the call-time media transport stubbed for a later release.

**Architecture:** Approach A — one new module `app-modules/robo-receptionist/` owning the whole domain. A pluggable provider contract (`AiAgentProvider`) with a registry ships one concrete provider (OpenAI Realtime, config-only in v1). Receptionists are routable through a new `DestinationResolver` kind and contribute a graceful stub dialplan extension via the tagged `dialplan.xml` contributor system. CRUD follows the `ModuleServiceProvider` + `BaseListComponent`/`BaseEditComponent` + Livewire conventions used by `ivr-menus`.

**Tech Stack:** Laravel 13, Livewire 4 full-page components, Pest 4, MariaDB migrations, DaisyUI/Tailwind Blade views, FreeSWITCH dynamic XML via `mod_xml_curl`.

## Global Constraints

- Every PHP file starts with `declare(strict_types=1);` and uses native type hints.
- Every class and every method has a PHPDoc comment in plain language; add inline comments where intent is not obvious.
- TDD is mandatory: write the failing Pest test, run it and watch it fail for the expected reason, then implement, then watch it pass. A test written after the code proves nothing.
- All tests are Pest (never PHPUnit). Run targeted tests with `--parallel`, e.g. `php artisan test --compact --parallel --filter=RoboReceptionist`. Never run the full suite.
- Wrap multi-step database writes in `DB::transaction()`.
- Run `php artisan optimize:clear` after any code change (views, routes, config, components).
- Run `vendor/bin/pint --dirty --format agent` before finalizing PHP changes in each task.
- Commit steps are shown per task but MUST NOT be executed without explicit user approval.
- Translation keys go in `lang/en/admin.php` (grep-verified at the end).
- Do not touch the FreeSWITCH installer, `mod_audio_stream`, or any live media transport in this plan — the call path is stubbed by design.

---

## File Structure

New module root: `app-modules/robo-receptionist/`

| File | Responsibility |
| --- | --- |
| `module.json` | Module metadata for `module:sync` |
| `composer.json` | Path-repository package `tallpbx/module-robo-receptionist` |
| `database/migrations/2026_08_11_000001_create_ai_provider_configs_table.php` | System-wide AI provider credentials |
| `database/migrations/2026_08_11_000002_create_ai_provider_tenant_table.php` | Provider→tenant assignment pivot |
| `database/migrations/2026_08_11_000003_create_robo_receptionists_table.php` | Per-tenant receptionist agents |
| `database/migrations/2026_08_11_000004_create_robo_receptionist_routes_table.php` | Intent-phrase routes per agent |
| `src/Contracts/AiAgentProvider.php` | Pluggable AI provider contract |
| `src/Support/OpenAiRealtimeProvider.php` | First concrete provider (config-only) |
| `src/Services/AiAgentProviderRegistry.php` | Key→provider lookup |
| `src/Services/AiProviderConfigServiceInterface.php` | Provider config CRUD contract |
| `src/Services/AiProviderConfigService.php` | Provider config CRUD + tenant assignment sync |
| `src/Services/RoboReceptionistServiceInterface.php` | Receptionist CRUD contract |
| `src/Services/RoboReceptionistService.php` | Receptionist CRUD, route sync, unique name, dialplan stub contributor |
| `src/Models/AiProviderConfig.php` | Provider config model (encrypted `api_key`) |
| `src/Models/RoboReceptionist.php` | Agent model (tenant-scoped) |
| `src/Models/RoboReceptionistRoute.php` | Route model |
| `src/Livewire/AiProvidersList.php` / `AiProvidersEdit.php` | Provider config pages (admin, system-wide) |
| `src/Livewire/RoboReceptionistList.php` / `RoboReceptionistEdit.php` | Receptionist pages |
| `resources/views/ai-providers-list.blade.php` etc. | DaisyUI views |
| `routes/web.php` | Custom routes (providers pages are non-standard names) |
| `src/Providers/ModuleServiceProvider.php` | Bindings, menu, permissions, dialplan tag |

Modified core files:

| File | Change |
| --- | --- |
| `composer.json` (root) | Path repository + require entry |
| `app/Services/DestinationResolver.php` | `KIND_ROBO_RECEPTIONIST` + resolution |
| `app-modules/inbound-routes/resources/views/inbound-routes-edit.blade.php` | New action dropdown option |
| `lang/en/admin.php` | Translation keys |

New factories: `database/factories/Pbx/AiProviderConfigFactory.php`, `RoboReceptionistFactory.php`, `RoboReceptionistRouteFactory.php`.

New tests: under `tests/Feature/Pbx/Models`, `tests/Feature/Pbx/Services`, `tests/Feature/Pbx/Livewire`, `tests/Feature/Modules/RoboReceptionist`, plus a new section in `tests/Feature/Services/DestinationResolverTest.php`.

---

### Task 1: Module scaffold, composer registration, menu and permissions

**Files:**
- Create: `app-modules/robo-receptionist/module.json`
- Create: `app-modules/robo-receptionist/composer.json`
- Create: `app-modules/robo-receptionist/src/Providers/ModuleServiceProvider.php`
- Modify: `composer.json` (root — path repository + require)
- Modify: `lang/en/admin.php` (menu label keys only for now)
- Test: `tests/Feature/Modules/RoboReceptionist/RoboReceptionistModuleRegistrationTest.php`

**Interfaces:**
- Consumes: `App\Support\ModuleServiceProvider` base class; `MenuService::register()`; `PermissionService::register()`; `php artisan module:sync --only-local`.
- Produces: module name `robo-receptionist`, namespace `Modules\RoboReceptionist`, permissions `robo-receptionist.view|create|edit|delete` and `robo-receptionist.providers.view|create|edit|delete`, menu keys `robo-receptionist` (parent `pbx.routing`) and `robo-receptionist-providers` (parent `pbx.advanced`). Later tasks rely on these exact strings for route middleware (`admin.can:robo-receptionist.*`) and sidebar visibility.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

// tests/Feature/Modules/RoboReceptionist/RoboReceptionistModuleRegistrationTest.php

use App\Models\Admin;
use App\Models\Permission;

it('registers robo-receptionist permissions in the database', function () {
    // Permissions are synced by the module provider through PermissionService.
    Admin::factory()->create();

    foreach ([
        'robo-receptionist.view',
        'robo-receptionist.create',
        'robo-receptionist.edit',
        'robo-receptionist.delete',
        'robo-receptionist.providers.view',
        'robo-receptionist.providers.create',
        'robo-receptionist.providers.edit',
        'robo-receptionist.providers.delete',
    ] as $name) {
        expect(Permission::where('name', $name)->exists())
            ->toBeTrue("Permission {$name} should exist");
    }
});

it('exposes the module provider as an App\Support\ModuleServiceProvider subclass', function () {
    expect(is_subclass_of(
        \Modules\RoboReceptionist\Providers\ModuleServiceProvider::class,
        \App\Support\ModuleServiceProvider::class,
    ))->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --parallel --filter=RoboReceptionistModuleRegistrationTest`
Expected: FAIL — class `Modules\RoboReceptionist\Providers\ModuleServiceProvider` does not exist.

- [ ] **Step 3: Create `module.json`**

```json
{
    "$schema": "../../resources/schemas/module.json",
    "name": "robo-receptionist",
    "version": "1.0.0",
    "namespace": "Modules\\RoboReceptionist",
    "display_name": "Robo Receptionist",
    "description": "AI-agent-driven receptionist: greets callers with configured voice prompts and routes calls by spoken intent.",
    "category": "PBX",
    "required": false,
    "protected": false,
    "priority": 192,
    "providers": [
        "Modules\\RoboReceptionist\\Providers\\ModuleServiceProvider"
    ],
    "requirements": {
        "php": ">=8.3",
        "laravel": ">=13.0",
        "modules": [],
        "packages": []
    }
}
```

- [ ] **Step 4: Create the module `composer.json`**

```json
{
    "name": "tallpbx/module-robo-receptionist",
    "type": "tallpbx-module",
    "description": "AI-agent-driven robo receptionist module for TallPBX.",
    "license": "Apache-2.0",
    "autoload": {
        "psr-4": {
            "Modules\\RoboReceptionist\\": "src/"
        }
    },
    "require": {
        "php": ">=8.3",
        "laravel/framework": ">=13.0"
    },
    "extra": {
        "laravel": {
            "providers": [
                "Modules\\RoboReceptionist\\Providers\\ModuleServiceProvider"
            ]
        }
    },
    "version": "1.0.0"
}
```

- [ ] **Step 5: Create the module service provider**

```php
<?php

declare(strict_types=1);

namespace Modules\RoboReceptionist\Providers;

use Modules\RoboReceptionist\Services\AiAgentProviderRegistry;
use Modules\RoboReceptionist\Services\AiProviderConfigService;
use Modules\RoboReceptionist\Services\AiProviderConfigServiceInterface;
use Modules\RoboReceptionist\Services\RoboReceptionistService;
use Modules\RoboReceptionist\Services\RoboReceptionistServiceInterface;
use Modules\RoboReceptionist\Support\OpenAiRealtimeProvider;

/**
 * Service provider for the robo-receptionist module.
 *
 * Registers service bindings, the AI provider registry singleton,
 * sidebar menu items, and module permissions. The receptionist
 * service is also tagged as a dialplan XML contributor so the
 * XML handler picks it up automatically.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /** @var array<class-string, class-string> */
    public $bindings = [
        AiProviderConfigServiceInterface::class => AiProviderConfigService::class,
        RoboReceptionistServiceInterface::class => RoboReceptionistService::class,
    ];

    /**
     * Register module services.
     *
     * The provider registry is built with every shipped AiAgentProvider
     * implementation, and the receptionist service is tagged so the
     * DialplanXmlCollector includes its dialplan fragments.
     */
    public function register(): void
    {
        parent::register();

        $this->app->singleton(
            AiAgentProviderRegistry::class,
            fn (): AiAgentProviderRegistry => new AiAgentProviderRegistry([
                new OpenAiRealtimeProvider,
            ]),
        );

        $this->app->tag(RoboReceptionistServiceInterface::class, 'dialplan.xml');
    }

    /** Kebab-case module identifier used for views, routes, and permissions. */
    protected function moduleName(): string
    {
        return 'robo-receptionist';
    }

    /** PHP root namespace for this module's classes. */
    protected function moduleNamespace(): string
    {
        return 'Modules\\RoboReceptionist';
    }

    /**
     * Register sidebar navigation menu items.
     *
     * Two entries: the per-tenant receptionist list under PBX routing,
     * and the system-wide AI provider configuration under PBX advanced.
     * Both are permission-gated; no guard key is needed.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function menuItems(): array
    {
        return [
            // Receptionist agents — visible to admin and tenant users
            // who hold the robo-receptionist.view permission.
            [
                'key' => 'robo-receptionist',
                'label' => 'admin.robo_receptionists',
                'route' => 'panel.robo-receptionist.index',
                'permission' => 'robo-receptionist.view',
                'icon' => 'heroicon-o-chat-bubble-left-right',
                'parent' => 'pbx.routing',
                'order' => 85,
            ],
            // AI provider credentials — system-wide, effectively admin-only
            // because tenant users are never granted providers.* permissions.
            [
                'key' => 'robo-receptionist-providers',
                'label' => 'admin.robo_receptionist_providers',
                'route' => 'panel.robo-receptionist.providers.index',
                'permission' => 'robo-receptionist.providers.view',
                'icon' => 'heroicon-o-key',
                'parent' => 'pbx.advanced',
                'order' => 40,
            ],
        ];
    }

    /**
     * Register module permissions for receptionist CRUD and
     * system-wide AI provider management.
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'robo-receptionist.view' => 'View robo receptionists',
            'robo-receptionist.create' => 'Create robo receptionists',
            'robo-receptionist.edit' => 'Edit robo receptionists',
            'robo-receptionist.delete' => 'Delete robo receptionists',
            'robo-receptionist.providers.view' => 'View AI provider configs',
            'robo-receptionist.providers.create' => 'Create AI provider configs',
            'robo-receptionist.providers.edit' => 'Edit AI provider configs',
            'robo-receptionist.providers.delete' => 'Delete AI provider configs',
        ];
    }
}
```

Note: this file references classes created in later tasks (`AiAgentProviderRegistry`, services, `OpenAiRealtimeProvider`). PHP only resolves them when `register()` runs, so create minimal placeholder versions if the app fails to boot before Task 3-5; normally the test in Step 2 is the only thing executed before they exist. If boot fails during Step 4 of this task, temporarily comment out the `register()` body and restore it at the end of Task 5.

- [ ] **Step 6: Register the module in root `composer.json`**

Add to the `repositories` array (next to the other `app-modules/*` entries):

```json
{
    "type": "path",
    "url": "app-modules/robo-receptionist"
},
```

Add to the `require` object (alphabetically near the other `tallpbx/module-*` entries):

```json
"tallpbx/module-robo-receptionist": "*",
```

Then run:

```bash
composer update tallpbx/module-robo-receptionist
php artisan module:sync --only-local
php artisan optimize:clear
```

- [ ] **Step 7: Add menu label translation keys to `lang/en/admin.php`**

Add near the existing IVR keys:

```php
'robo_receptionists' => 'Robo Receptionists',
'robo_receptionist_providers' => 'AI Providers',
```

- [ ] **Step 8: Run test to verify it passes**

Run: `php artisan test --compact --parallel --filter=RoboReceptionistModuleRegistrationTest`
Expected: PASS (permissions synced through `PermissionService` at boot).

- [ ] **Step 9: Format and clear caches**

```bash
vendor/bin/pint --dirty --format agent
php artisan optimize:clear
```

- [ ] **Step 10: Commit**

```bash
git add app-modules/robo-receptionist composer.json composer.lock lang/en/admin.php tests/Feature/Modules/RoboReceptionist
git commit -m "feat: scaffold robo-receptionist module with permissions and menu"
```

---

### Task 2: Database schema, models, and factories

**Files:**
- Create: `app-modules/robo-receptionist/database/migrations/2026_08_11_000001_create_ai_provider_configs_table.php`
- Create: `app-modules/robo-receptionist/database/migrations/2026_08_11_000002_create_ai_provider_tenant_table.php`
- Create: `app-modules/robo-receptionist/database/migrations/2026_08_11_000003_create_robo_receptionists_table.php`
- Create: `app-modules/robo-receptionist/database/migrations/2026_08_11_000004_create_robo_receptionist_routes_table.php`
- Create: `app-modules/robo-receptionist/src/Models/AiProviderConfig.php`
- Create: `app-modules/robo-receptionist/src/Models/RoboReceptionist.php`
- Create: `app-modules/robo-receptionist/src/Models/RoboReceptionistRoute.php`
- Create: `database/factories/Pbx/AiProviderConfigFactory.php`
- Create: `database/factories/Pbx/RoboReceptionistFactory.php`
- Create: `database/factories/Pbx/RoboReceptionistRouteFactory.php`
- Test: `tests/Feature/Pbx/Models/RoboReceptionistModelsTest.php`

**Interfaces:**
- Consumes: module provider from Task 1 (auto-loads `database/migrations/`); `App\Traits\BelongsToTenant`; `App\Models\Tenant`.
- Produces: models `Modules\RoboReceptionist\Models\AiProviderConfig` (uuid PK, encrypted `api_key`, `tenants()` BelongsToMany via `ai_provider_tenant`), `RoboReceptionist` (uuid PK, tenant-scoped, `routes()` HasMany, `provider()` BelongsTo), `RoboReceptionistRoute` (uuid PK, `receptionist()` BelongsTo). Column names used by later tasks: `name`, `provider_key`, `api_key`, `base_url`, `model`, `enabled` (providers); `tenant_id`, `name`, `greeting_text`, `instructions`, `voice`, `voice_gender`, `language`, `ai_provider_config_id`, `timeout`, `max_failures`, `fallback_kind`, `fallback_data`, `enabled` (receptionists); `robo_receptionist_id`, `label`, `intent_phrases`, `destination_kind`, `destination_data`, `order`, `enabled` (routes).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

// tests/Feature/Pbx/Models/RoboReceptionistModelsTest.php

use App\Models\Tenant;
use Modules\RoboReceptionist\Models\AiProviderConfig;
use Modules\RoboReceptionist\Models\RoboReceptionist;
use Modules\RoboReceptionist\Models\RoboReceptionistRoute;

it('encrypts the provider api key at rest', function () {
    $config = AiProviderConfig::factory()->create(['api_key' => 'sk-secret-123']);

    // Model access decrypts transparently.
    expect($config->fresh()->api_key)->toBe('sk-secret-123');

    // The raw database value must not be the plaintext key.
    $raw = \Illuminate\Support\Facades\DB::table('ai_provider_configs')
        ->where('id', $config->id)
        ->value('api_key');
    expect($raw)->not->toBe('sk-secret-123');
});

it('links providers to tenants through the assignment pivot', function () {
    $tenant = Tenant::factory()->create();
    $config = AiProviderConfig::factory()->create();

    $config->tenants()->sync([$tenant->id]);

    expect($config->fresh()->tenants->pluck('id'))->toContain($tenant->id);
});

it('scopes robo receptionists to their tenant', function () {
    $receptionist = RoboReceptionist::factory()->create();

    // The tenant global scope hides records outside the active tenant context,
    // so bypass it and confirm the record exists with the expected relations.
    $found = RoboReceptionist::withoutGlobalScope('tenant')->find($receptionist->id);

    expect($found)->not->toBeNull()
        ->and($found->tenant_id)->toBe($receptionist->tenant_id);
});

it('cascades route deletion with the receptionist', function () {
    $receptionist = RoboReceptionist::factory()->create();
    $route = RoboReceptionistRoute::factory()->create([
        'robo_receptionist_id' => $receptionist->id,
    ]);

    $receptionist->delete();

    expect(RoboReceptionistRoute::find($route->id))->toBeNull();
});

it('exposes the provider relation from a receptionist', function () {
    $config = AiProviderConfig::factory()->create();
    $receptionist = RoboReceptionist::factory()->create([
        'ai_provider_config_id' => $config->id,
    ]);

    expect($receptionist->provider->id)->toBe($config->id);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --parallel --filter=RoboReceptionistModelsTest`
Expected: FAIL — tables and model classes do not exist.

- [ ] **Step 3: Write the four migrations**

`2026_08_11_000001_create_ai_provider_configs_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * System-wide AI provider credential records. Each record names an
 * AI agent service (e.g. OpenAI Realtime) and stores the encrypted
 * API key plus optional endpoint/model overrides.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_provider_configs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->unique();
            $table->string('provider_key', 64)->index();
            $table->text('api_key'); // Stored encrypted via the model cast.
            $table->string('base_url')->nullable();
            $table->string('model')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_provider_configs');
    }
};
```

`2026_08_11_000002_create_ai_provider_tenant_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pivot granting tenants access to specific system-wide AI provider
 * configs. A receptionist may only reference a provider assigned to
 * its own tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_provider_tenant', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('ai_provider_config_id')
                ->constrained('ai_provider_configs')
                ->cascadeOnDelete();
            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['ai_provider_config_id', 'tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_provider_tenant');
    }
};
```

`2026_08_11_000003_create_robo_receptionists_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant AI receptionist agents. Stores the spoken greeting text,
 * persona instructions, voice selection, the assigned AI provider,
 * conversation limits, and an optional fallback destination used when
 * the agent cannot match any route.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('robo_receptionists', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->text('greeting_text');
            $table->text('instructions')->nullable();
            $table->string('voice')->nullable();
            $table->string('voice_gender', 16)->nullable();
            $table->string('language', 16)->nullable();
            $table->foreignUuid('ai_provider_config_id')
                ->nullable()
                ->constrained('ai_provider_configs')
                ->nullOnDelete();
            $table->unsignedInteger('timeout')->default(10);
            $table->unsignedInteger('max_failures')->default(3);
            $table->string('fallback_kind', 64)->nullable();
            $table->string('fallback_data')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('robo_receptionists');
    }
};
```

`2026_08_11_000004_create_robo_receptionist_routes_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Intent-phrase routes for a robo receptionist. Each route pairs a
 * label plus example phrases the AI should recognize with a typed
 * PBX destination (kind + identifier resolved by DestinationResolver).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('robo_receptionist_routes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('robo_receptionist_id')
                ->constrained('robo_receptionists')
                ->cascadeOnDelete();
            $table->string('label');
            $table->text('intent_phrases');
            $table->string('destination_kind', 64);
            $table->string('destination_data');
            $table->unsignedInteger('order')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->index('robo_receptionist_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('robo_receptionist_routes');
    }
};
```

- [ ] **Step 4: Create the three models**

`src/Models/AiProviderConfig.php`:

```php
<?php

declare(strict_types=1);

namespace Modules\RoboReceptionist\Models;

use App\Models\Tenant;
use Database\Factories\Pbx\AiProviderConfigFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A system-wide AI provider credential record.
 *
 * Stores which AI agent service to use (provider_key), the encrypted
 * API key, and optional endpoint/model overrides. Tenants must be
 * assigned a provider through the ai_provider_tenant pivot before
 * their receptionists can use it.
 *
 * @property string $id UUID primary key
 * @property string $name Unique human-readable label
 * @property string $provider_key Registry key of the AiAgentProvider
 * @property string $api_key Encrypted provider API key
 * @property string|null $base_url Optional provider endpoint override
 * @property string|null $model Optional provider model override
 * @property bool $enabled Whether tenants may use this provider
 */
class AiProviderConfig extends Model
{
    /** @use HasFactory<AiProviderConfigFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'name', 'provider_key', 'api_key', 'base_url', 'model', 'enabled',
    ];

    protected function casts(): array
    {
        return [
            // Laravel transparently encrypts on write and decrypts on read.
            'api_key' => 'encrypted',
            'enabled' => 'boolean',
        ];
    }

    /** Tenants allowed to use this provider. */
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(
            Tenant::class,
            'ai_provider_tenant',
            'ai_provider_config_id',
            'tenant_id',
        );
    }

    /** Receptionists that reference this provider. */
    public function receptionists(): HasMany
    {
        return $this->hasMany(RoboReceptionist::class, 'ai_provider_config_id');
    }

    protected static function newFactory(): AiProviderConfigFactory
    {
        return AiProviderConfigFactory::new();
    }
}
```

`src/Models/RoboReceptionist.php`:

```php
<?php

declare(strict_types=1);

namespace Modules\RoboReceptionist\Models;

use App\Models\Tenant;
use App\Traits\BelongsToTenant;
use Database\Factories\Pbx\RoboReceptionistFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An AI-agent-driven receptionist belonging to one tenant.
 *
 * Answers calls, speaks the configured greeting with the selected
 * voice, listens for the caller's intent, and routes the call using
 * its configured routes. Falls back to fallback_kind/fallback_data
 * when no route matches.
 *
 * @property string $id UUID primary key
 * @property int $tenant_id
 * @property string $name Unique within the tenant
 * @property string $greeting_text Spoken greeting (TTS text)
 * @property string|null $instructions Persona/behavior instructions for the AI
 * @property string|null $voice Provider voice identifier
 * @property string|null $voice_gender male|female|null
 * @property string|null $language BCP-47 language code, e.g. en-US
 * @property string|null $ai_provider_config_id Assigned provider
 * @property int $timeout Seconds to wait for caller input
 * @property int $max_failures Retries before falling back
 * @property string|null $fallback_kind DestinationResolver kind
 * @property string|null $fallback_data Destination identifier
 * @property bool $enabled
 */
class RoboReceptionist extends Model
{
    /** @use HasFactory<RoboReceptionistFactory> */
    use BelongsToTenant;

    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'name', 'greeting_text', 'instructions',
        'voice', 'voice_gender', 'language', 'ai_provider_config_id',
        'timeout', 'max_failures', 'fallback_kind', 'fallback_data', 'enabled',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'timeout' => 'integer',
            'max_failures' => 'integer',
        ];
    }

    /** The tenant owning this receptionist. */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** The assigned AI provider config (may be null). */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProviderConfig::class, 'ai_provider_config_id');
    }

    /** Intent-phrase routes, ordered for presentation to the AI. */
    public function routes(): HasMany
    {
        return $this->hasMany(RoboReceptionistRoute::class, 'robo_receptionist_id')
            ->orderBy('order');
    }

    protected static function newFactory(): RoboReceptionistFactory
    {
        return RoboReceptionistFactory::new();
    }
}
```

`src/Models/RoboReceptionistRoute.php`:

```php
<?php

declare(strict_types=1);

namespace Modules\RoboReceptionist\Models;

use Database\Factories\Pbx\RoboReceptionistRouteFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One intent-phrase route on a robo receptionist.
 *
 * Pairs a label plus example phrases ("sales, pricing, purchase")
 * with a typed PBX destination resolved by DestinationResolver.
 *
 * @property string $id UUID primary key
 * @property string $robo_receptionist_id
 * @property string $label Admin-facing route label
 * @property string $intent_phrases Comma/newline-separated example phrases
 * @property string $destination_kind DestinationResolver kind
 * @property string $destination_data Destination identifier
 * @property int $order Presentation order
 * @property bool $enabled
 */
class RoboReceptionistRoute extends Model
{
    /** @use HasFactory<RoboReceptionistRouteFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'robo_receptionist_id', 'label', 'intent_phrases',
        'destination_kind', 'destination_data', 'order', 'enabled',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'order' => 'integer',
        ];
    }

    /** The receptionist this route belongs to. */
    public function receptionist(): BelongsTo
    {
        return $this->belongsTo(RoboReceptionist::class, 'robo_receptionist_id');
    }

    protected static function newFactory(): RoboReceptionistRouteFactory
    {
        return RoboReceptionistRouteFactory::new();
    }
}
```

- [ ] **Step 5: Create the three factories in `database/factories/Pbx/`**

`AiProviderConfigFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories\Pbx;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\RoboReceptionist\Models\AiProviderConfig;

/**
 * Factory for AI provider config records in tests and seeders.
 *
 * @extends Factory<AiProviderConfig>
 */
class AiProviderConfigFactory extends Factory
{
    protected $model = AiProviderConfig::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true).' AI',
            'provider_key' => 'openai_realtime',
            'api_key' => 'sk-test-'.fake()->sha256(),
            'base_url' => null,
            'model' => null,
            'enabled' => true,
        ];
    }
}
```

`RoboReceptionistFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories\Pbx;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\RoboReceptionist\Models\RoboReceptionist;

/**
 * Factory for robo receptionist records in tests and seeders.
 *
 * @extends Factory<RoboReceptionist>
 */
class RoboReceptionistFactory extends Factory
{
    protected $model = RoboReceptionist::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->unique()->words(2, true).' Reception',
            'greeting_text' => 'Hello! How can I direct your call today?',
            'instructions' => null,
            'voice' => null,
            'voice_gender' => null,
            'language' => 'en-US',
            'ai_provider_config_id' => null,
            'timeout' => 10,
            'max_failures' => 3,
            'fallback_kind' => null,
            'fallback_data' => null,
            'enabled' => true,
        ];
    }

    /** Set the owning tenant for this receptionist. */
    public function forTenant(int $tenantId): static
    {
        return $this->state(fn (array $attributes) => ['tenant_id' => $tenantId]);
    }
}
```

`RoboReceptionistRouteFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories\Pbx;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\RoboReceptionist\Models\RoboReceptionist;
use Modules\RoboReceptionist\Models\RoboReceptionistRoute;

/**
 * Factory for robo receptionist route records in tests and seeders.
 *
 * @extends Factory<RoboReceptionistRoute>
 */
class RoboReceptionistRouteFactory extends Factory
{
    protected $model = RoboReceptionistRoute::class;

    public function definition(): array
    {
        return [
            'robo_receptionist_id' => RoboReceptionist::factory(),
            'label' => fake()->unique()->word(),
            'intent_phrases' => 'sales, purchase, pricing',
            'destination_kind' => 'extension',
            'destination_data' => '100',
            'order' => 0,
            'enabled' => true,
        ];
    }
}
```

- [ ] **Step 6: Run migrations and the test**

```bash
php artisan migrate
php artisan test --compact --parallel --filter=RoboReceptionistModelsTest
```

Expected: PASS (5 tests).

- [ ] **Step 7: Format and clear caches**

```bash
vendor/bin/pint --dirty --format agent
php artisan optimize:clear
```

- [ ] **Step 8: Commit**

```bash
git add app-modules/robo-receptionist/database app-modules/robo-receptionist/src/Models database/factories/Pbx tests/Feature/Pbx/Models/RoboReceptionistModelsTest.php
git commit -m "feat: add robo-receptionist schema, models, and factories"
```

---

### Task 3: AI provider contract, OpenAI Realtime provider, and registry

**Files:**
- Create: `app-modules/robo-receptionist/src/Contracts/AiAgentProvider.php`
- Create: `app-modules/robo-receptionist/src/Support/OpenAiRealtimeProvider.php`
- Create: `app-modules/robo-receptionist/src/Services/AiAgentProviderRegistry.php`
- Test: `tests/Feature/Pbx/Services/AiAgentProviderRegistryTest.php`

**Interfaces:**
- Consumes: `RoboReceptionist` model (Task 2); provider registration in the module `register()` from Task 1 (`new AiAgentProviderRegistry([new OpenAiRealtimeProvider])`).
- Produces: `AiAgentProvider` contract with `key(): string`, `label(): string`, `buildAgentConfig(RoboReceptionist $receptionist): array<string, mixed>`; registry with `all(): array<string, AiAgentProvider>`, `keys(): array<int, string>`, `get(string $key): AiAgentProvider` (throws `InvalidArgumentException` for unknown keys). The OpenAI provider key is exactly `openai_realtime`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

// tests/Feature/Pbx/Services/AiAgentProviderRegistryTest.php

use Modules\RoboReceptionist\Models\RoboReceptionist;
use Modules\RoboReceptionist\Models\RoboReceptionistRoute;
use Modules\RoboReceptionist\Services\AiAgentProviderRegistry;

beforeEach(function () {
    $this->registry = app(AiAgentProviderRegistry::class);
});

it('ships the openai_realtime provider', function () {
    expect($this->registry->keys())->toContain('openai_realtime');
    expect($this->registry->get('openai_realtime')->label())->toBe('OpenAI Realtime');
});

it('throws for an unknown provider key', function () {
    $this->registry->get('does-not-exist');
})->throws(InvalidArgumentException::class, 'Unknown AI provider');

it('builds a normalized agent config for a receptionist', function () {
    $receptionist = RoboReceptionist::factory()->create([
        'greeting_text' => 'Hello there',
        'voice' => 'alloy',
        'voice_gender' => 'female',
        'language' => 'en-US',
    ]);
    RoboReceptionistRoute::factory()->create([
        'robo_receptionist_id' => $receptionist->id,
        'label' => 'Sales',
        'intent_phrases' => 'sales, purchase',
        'destination_kind' => 'extension',
        'destination_data' => '101',
    ]);

    $config = $this->registry
        ->get('openai_realtime')
        ->buildAgentConfig($receptionist->fresh(['routes']));

    expect($config['provider'])->toBe('openai_realtime')
        ->and($config['greeting'])->toBe('Hello there')
        ->and($config['voice'])->toBe(['id' => 'alloy', 'gender' => 'female', 'language' => 'en-US'])
        ->and($config['routes'])->toHaveCount(1)
        ->and($config['routes'][0]['label'])->toBe('Sales')
        ->and($config['routes'][0]['intent_phrases'])->toBe('sales, purchase')
        ->and($config['routes'][0]['destination'])->toBe(['kind' => 'extension', 'data' => '101']);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --parallel --filter=AiAgentProviderRegistryTest`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement the contract**

```php
<?php

declare(strict_types=1);

namespace Modules\RoboReceptionist\Contracts;

use Modules\RoboReceptionist\Models\RoboReceptionist;

/**
 * Contract for external AI agent services that can power a
 * robo receptionist conversation.
 *
 * Implementations describe themselves (key/label) and translate a
 * receptionist record into a provider-neutral agent configuration
 * array. The v1 implementations are config-only; a future media
 * transport consumes the same array.
 */
interface AiAgentProvider
{
    /** Stable registry key stored in ai_provider_configs.provider_key. */
    public function key(): string;

    /** Human-readable provider label shown in admin dropdowns. */
    public function label(): string;

    /**
     * Build the normalized agent configuration for one receptionist.
     *
     * The receptionist must have its routes relation loaded.
     *
     * @return array<string, mixed>
     */
    public function buildAgentConfig(RoboReceptionist $receptionist): array;
}
```

- [ ] **Step 4: Implement the OpenAI Realtime provider**

```php
<?php

declare(strict_types=1);

namespace Modules\RoboReceptionist\Support;

use Modules\RoboReceptionist\Contracts\AiAgentProvider;
use Modules\RoboReceptionist\Models\RoboReceptionist;

/**
 * First concrete AI agent provider: OpenAI Realtime.
 *
 * In v1 this provider only normalizes receptionist configuration
 * into the shape a future realtime transport will consume. No live
 * API calls are made from this class.
 */
class OpenAiRealtimeProvider implements AiAgentProvider
{
    /** Registry key stored in ai_provider_configs.provider_key. */
    public function key(): string
    {
        return 'openai_realtime';
    }

    /** Human-readable label for admin dropdowns. */
    public function label(): string
    {
        return 'OpenAI Realtime';
    }

    /**
     * Normalize the receptionist into a provider-ready agent config.
     *
     * @return array<string, mixed>
     */
    public function buildAgentConfig(RoboReceptionist $receptionist): array
    {
        return [
            'provider' => $this->key(),
            'greeting' => $receptionist->greeting_text,
            'instructions' => $receptionist->instructions,
            'voice' => [
                'id' => $receptionist->voice,
                'gender' => $receptionist->voice_gender,
                'language' => $receptionist->language,
            ],
            'limits' => [
                'timeout_seconds' => $receptionist->timeout,
                'max_failures' => $receptionist->max_failures,
            ],
            // One entry per enabled intent-phrase route.
            'routes' => $receptionist->routes
                ->filter(fn ($route): bool => $route->enabled)
                ->values()
                ->map(fn ($route): array => [
                    'label' => $route->label,
                    'intent_phrases' => $route->intent_phrases,
                    'destination' => [
                        'kind' => $route->destination_kind,
                        'data' => $route->destination_data,
                    ],
                ])
                ->all(),
            'fallback' => $receptionist->fallback_kind !== null
                ? ['kind' => $receptionist->fallback_kind, 'data' => $receptionist->fallback_data ?? '']
                : null,
        ];
    }
}
```

- [ ] **Step 5: Implement the registry**

```php
<?php

declare(strict_types=1);

namespace Modules\RoboReceptionist\Services;

use InvalidArgumentException;
use Modules\RoboReceptionist\Contracts\AiAgentProvider;

/**
 * Registry of available AI agent providers.
 *
 * Built once as a singleton by the module service provider with every
 * shipped AiAgentProvider implementation. Lookups by key throw for
 * unknown keys so misconfigured records fail loudly.
 */
class AiAgentProviderRegistry
{
    /** @var array<string, AiAgentProvider> key => provider */
    private array $providers;

    /**
     * @param  array<int, AiAgentProvider>  $providers
     */
    public function __construct(array $providers)
    {
        foreach ($providers as $provider) {
            $this->providers[$provider->key()] = $provider;
        }
    }

    /** All registered providers keyed by registry key. */
    public function all(): array
    {
        return $this->providers;
    }

    /** All registered provider keys. */
    public function keys(): array
    {
        return array_keys($this->providers);
    }

    /**
     * Resolve one provider by its registry key.
     *
     * @throws InvalidArgumentException When the key is not registered
     */
    public function get(string $key): AiAgentProvider
    {
        if (! isset($this->providers[$key])) {
            throw new InvalidArgumentException("Unknown AI provider: {$key}");
        }

        return $this->providers[$key];
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --compact --parallel --filter=AiAgentProviderRegistryTest`
Expected: PASS (3 tests).

- [ ] **Step 7: Format and clear caches**

```bash
vendor/bin/pint --dirty --format agent
php artisan optimize:clear
```

- [ ] **Step 8: Commit**

```bash
git add app-modules/robo-receptionist/src/Contracts app-modules/robo-receptionist/src/Support app-modules/robo-receptionist/src/Services/AiAgentProviderRegistry.php tests/Feature/Pbx/Services/AiAgentProviderRegistryTest.php
git commit -m "feat: add pluggable AI agent provider contract with OpenAI Realtime"
```

---

### Task 4: AiProviderConfigService (CRUD + tenant assignment)

**Files:**
- Create: `app-modules/robo-receptionist/src/Services/AiProviderConfigServiceInterface.php`
- Create: `app-modules/robo-receptionist/src/Services/AiProviderConfigService.php`
- Test: `tests/Feature/Pbx/Services/AiProviderConfigServiceTest.php`

**Interfaces:**
- Consumes: `AiProviderConfig` model; `AiAgentProviderRegistry::keys()`; module binding from Task 1.
- Produces: `AiProviderConfigServiceInterface` with `all(): Collection`, `find(string $id): AiProviderConfig`, `create(array $data, array $tenantIds): AiProviderConfig`, `update(AiProviderConfig $config, array $data, array $tenantIds): AiProviderConfig`, `delete(AiProviderConfig $config): void`, `assignedToTenant(AiProviderConfig $config, int $tenantId): bool`. `create`/`update` validate `provider_key` against the registry and reject duplicate names via `ValidationException`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

// tests/Feature/Pbx/Services/AiProviderConfigServiceTest.php

use App\Models\Tenant;
use Illuminate\Validation\ValidationException;
use Modules\RoboReceptionist\Models\AiProviderConfig;
use Modules\RoboReceptionist\Services\AiProviderConfigServiceInterface;

beforeEach(function () {
    $this->service = app(AiProviderConfigServiceInterface::class);
});

it('creates a provider config and syncs tenant assignments', function () {
    $tenant = Tenant::factory()->create();

    $config = $this->service->create([
        'name' => 'Main OpenAI',
        'provider_key' => 'openai_realtime',
        'api_key' => 'sk-secret-1',
    ], [$tenant->id]);

    expect($config->name)->toBe('Main OpenAI')
        ->and($config->tenants->pluck('id'))->toContain($tenant->id);
});

it('rejects an unknown provider key on create', function () {
    $this->service->create([
        'name' => 'Bad',
        'provider_key' => 'nope',
        'api_key' => 'sk-x',
    ], []);
})->throws(ValidationException::class);

it('rejects duplicate provider config names', function () {
    AiProviderConfig::factory()->create(['name' => 'Main OpenAI']);

    $this->service->create([
        'name' => 'Main OpenAI',
        'provider_key' => 'openai_realtime',
        'api_key' => 'sk-x',
    ], []);
})->throws(ValidationException::class);

it('updates a provider config and replaces tenant assignments', function () {
    $first = Tenant::factory()->create();
    $second = Tenant::factory()->create();
    $config = AiProviderConfig::factory()->create(['name' => 'Old']);
    $config->tenants()->sync([$first->id]);

    $updated = $this->service->update($config, ['name' => 'New'], [$second->id]);

    expect($updated->name)->toBe('New')
        ->and($updated->tenants->pluck('id')->all())->toBe([$second->id]);
});

it('deletes a provider config', function () {
    $config = AiProviderConfig::factory()->create();

    $this->service->delete($config);

    $this->assertModelMissing($config);
});

it('reports tenant assignment state', function () {
    $tenant = Tenant::factory()->create();
    $config = AiProviderConfig::factory()->create();
    $config->tenants()->sync([$tenant->id]);

    expect($this->service->assignedToTenant($config, $tenant->id))->toBeTrue()
        ->and($this->service->assignedToTenant($config, 999999))->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --parallel --filter=AiProviderConfigServiceTest`
Expected: FAIL — interface/class not found.

- [ ] **Step 3: Implement the interface**

```php
<?php

declare(strict_types=1);

namespace Modules\RoboReceptionist\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\RoboReceptionist\Models\AiProviderConfig;

/**
 * Contract for managing system-wide AI provider configurations
 * and their per-tenant assignments.
 */
interface AiProviderConfigServiceInterface
{
    /** All provider configs ordered by name. */
    public function all(): Collection;

    /** Find one provider config by ID or fail with 404. */
    public function find(string $id): AiProviderConfig;

    /**
     * Create a provider config and assign it to the given tenants.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, int>  $tenantIds
     */
    public function create(array $data, array $tenantIds): AiProviderConfig;

    /**
     * Update a provider config and replace its tenant assignments.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, int>  $tenantIds
     */
    public function update(AiProviderConfig $config, array $data, array $tenantIds): AiProviderConfig;

    /** Delete a provider config (receptionists keep working with null provider). */
    public function delete(AiProviderConfig $config): void;

    /** Whether the provider is assigned to the given tenant. */
    public function assignedToTenant(AiProviderConfig $config, int $tenantId): bool;
}
```

- [ ] **Step 4: Implement the service**

```php
<?php

declare(strict_types=1);

namespace Modules\RoboReceptionist\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\RoboReceptionist\Models\AiProviderConfig;

/**
 * System-wide AI provider configuration management.
 *
 * Validates the provider key against the AiAgentProviderRegistry,
 * enforces unique names, and keeps config writes and tenant
 * assignment syncs inside one transaction.
 */
class AiProviderConfigService implements AiProviderConfigServiceInterface
{
    /** Build the service with the provider registry for key validation. */
    public function __construct(private readonly AiAgentProviderRegistry $registry) {}

    /** All provider configs ordered by name. */
    public function all(): Collection
    {
        return AiProviderConfig::withCount('tenants')->orderBy('name')->get();
    }

    /** Find one provider config by ID or fail with 404. */
    public function find(string $id): AiProviderConfig
    {
        return AiProviderConfig::findOrFail($id);
    }

    /**
     * Create a provider config and assign it to the given tenants.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, int>  $tenantIds
     */
    public function create(array $data, array $tenantIds): AiProviderConfig
    {
        $this->validateProviderKey($data['provider_key'] ?? '');
        $this->validateUniqueName($data['name'] ?? '', null);

        return DB::transaction(function () use ($data, $tenantIds): AiProviderConfig {
            $config = AiProviderConfig::create($data);
            $config->tenants()->sync($tenantIds);

            return $config->load('tenants');
        });
    }

    /**
     * Update a provider config and replace its tenant assignments.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, int>  $tenantIds
     */
    public function update(AiProviderConfig $config, array $data, array $tenantIds): AiProviderConfig
    {
        if (isset($data['provider_key'])) {
            $this->validateProviderKey($data['provider_key']);
        }

        if (isset($data['name']) && $data['name'] !== $config->name) {
            $this->validateUniqueName($data['name'], $config->id);
        }

        return DB::transaction(function () use ($config, $data, $tenantIds): AiProviderConfig {
            $config->update($data);
            $config->tenants()->sync($tenantIds);

            return $config->fresh('tenants');
        });
    }

    /** Delete a provider config; receptionists referencing it become unassigned. */
    public function delete(AiProviderConfig $config): void
    {
        DB::transaction(fn (): ?AiProviderConfig => $config->delete());
    }

    /** Whether the provider is assigned to the given tenant. */
    public function assignedToTenant(AiProviderConfig $config, int $tenantId): bool
    {
        return $config->tenants()->whereKey($tenantId)->exists();
    }

    /** Ensure the provider key is registered, so stale keys fail loudly. */
    private function validateProviderKey(string $key): void
    {
        if (! in_array($key, $this->registry->keys(), true)) {
            throw ValidationException::withMessages([
                'provider_key' => ["The AI provider '{$key}' is not available."],
            ]);
        }
    }

    /** Ensure the config name is unique (case-insensitive). */
    private function validateUniqueName(string $name, ?string $excludeId): void
    {
        $exists = AiProviderConfig::where('name', $name)
            ->when($excludeId !== null, fn ($query) => $query->where('id', '!=', $excludeId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => ['An AI provider config with this name already exists.'],
            ]);
        }
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact --parallel --filter=AiProviderConfigServiceTest`
Expected: PASS (6 tests).

- [ ] **Step 6: Format and clear caches**

```bash
vendor/bin/pint --dirty --format agent
php artisan optimize:clear
```

- [ ] **Step 7: Commit**

```bash
git add app-modules/robo-receptionist/src/Services/AiProviderConfigService*.php tests/Feature/Pbx/Services/AiProviderConfigServiceTest.php
git commit -m "feat: add AI provider config service with tenant assignment sync"
```

---

### Task 5: RoboReceptionistService (CRUD, route sync, provider assignment guard)

**Files:**
- Create: `app-modules/robo-receptionist/src/Services/RoboReceptionistServiceInterface.php`
- Create: `app-modules/robo-receptionist/src/Services/RoboReceptionistService.php`
- Test: `tests/Feature/Pbx/Services/RoboReceptionistServiceTest.php`

**Interfaces:**
- Consumes: `RoboReceptionist`, `RoboReceptionistRoute` models; `AiProviderConfigServiceInterface::assignedToTenant()`; `App\Services\DestinationResolver::routeSelectableKinds()` (read-only — the new kind itself is added in Task 6; route destination kinds are validated against `routeSelectableKinds()` as of Task 6, so this task's tests use kinds that already exist, e.g. `extension`).
- Produces: `RoboReceptionistServiceInterface` with `create(array $data, array $routes): RoboReceptionist`, `update(RoboReceptionist $receptionist, array $data, array $routes): RoboReceptionist`, `delete(RoboReceptionist $receptionist): void`, `getByTenant(int $tenantId): Collection`. Route arrays use keys `label`, `intent_phrases`, `destination_kind`, `destination_data`, `order`, `enabled`. Task 7 adds `ContextWideDialplanXmlContributor` to the implementation class.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

// tests/Feature/Pbx/Services/RoboReceptionistServiceTest.php

use App\Models\Tenant;
use Illuminate\Validation\ValidationException;
use Modules\RoboReceptionist\Models\AiProviderConfig;
use Modules\RoboReceptionist\Models\RoboReceptionist;
use Modules\RoboReceptionist\Services\RoboReceptionistServiceInterface;

beforeEach(function () {
    $this->service = app(RoboReceptionistServiceInterface::class);
    $this->tenant = Tenant::factory()->create();
});

it('creates a receptionist with routes in one transaction', function () {
    $receptionist = $this->service->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Front Desk',
        'greeting_text' => 'Hi! How can I help?',
    ], [
        [
            'label' => 'Sales',
            'intent_phrases' => 'sales, purchase',
            'destination_kind' => 'extension',
            'destination_data' => '101',
            'order' => 0,
            'enabled' => true,
        ],
    ]);

    expect($receptionist->routes)->toHaveCount(1)
        ->and($receptionist->routes->first()->label)->toBe('Sales');
});

it('rejects duplicate receptionist names within a tenant', function () {
    RoboReceptionist::factory()->forTenant($this->tenant->id)->create(['name' => 'Front Desk']);

    $this->service->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Front Desk',
        'greeting_text' => 'Hi',
    ], []);
})->throws(ValidationException::class);

it('allows the same name in different tenants', function () {
    RoboReceptionist::factory()->forTenant($this->tenant->id)->create(['name' => 'Front Desk']);
    $other = Tenant::factory()->create();

    $receptionist = $this->service->create([
        'tenant_id' => $other->id,
        'name' => 'Front Desk',
        'greeting_text' => 'Hi',
    ], []);

    expect($receptionist->exists)->toBeTrue();
});

it('rejects a provider that is not assigned to the tenant', function () {
    $config = AiProviderConfig::factory()->create(); // Not assigned to anyone.

    $this->service->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Front Desk',
        'greeting_text' => 'Hi',
        'ai_provider_config_id' => $config->id,
    ], []);
})->throws(ValidationException::class);

it('accepts a provider assigned to the tenant', function () {
    $config = AiProviderConfig::factory()->create();
    $config->tenants()->sync([$this->tenant->id]);

    $receptionist = $this->service->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Front Desk',
        'greeting_text' => 'Hi',
        'ai_provider_config_id' => $config->id,
    ], []);

    expect($receptionist->ai_provider_config_id)->toBe($config->id);
});

it('rejects route destination kinds outside the resolver whitelist', function () {
    $this->service->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Front Desk',
        'greeting_text' => 'Hi',
    ], [
        [
            'label' => 'Bad',
            'intent_phrases' => 'x',
            'destination_kind' => 'bogus_kind',
            'destination_data' => '1',
            'order' => 0,
            'enabled' => true,
        ],
    ]);
})->throws(ValidationException::class);

it('replaces all routes on update', function () {
    $receptionist = RoboReceptionist::factory()->forTenant($this->tenant->id)->create();
    $receptionist->routes()->create([
        'label' => 'Old',
        'intent_phrases' => 'old',
        'destination_kind' => 'extension',
        'destination_data' => '100',
        'order' => 0,
        'enabled' => true,
    ]);

    $updated = $this->service->update($receptionist, ['name' => 'Renamed'], [
        [
            'label' => 'New',
            'intent_phrases' => 'support',
            'destination_kind' => 'voicemail',
            'destination_data' => '100',
            'order' => 0,
            'enabled' => true,
        ],
    ]);

    expect($updated->routes)->toHaveCount(1)
        ->and($updated->routes->first()->label)->toBe('New');
});

it('deletes a receptionist and its routes', function () {
    $receptionist = RoboReceptionist::factory()->forTenant($this->tenant->id)->create();
    $receptionist->routes()->create([
        'label' => 'Sales',
        'intent_phrases' => 'sales',
        'destination_kind' => 'extension',
        'destination_data' => '100',
        'order' => 0,
        'enabled' => true,
    ]);

    $this->service->delete($receptionist);

    $this->assertModelMissing($receptionist);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --parallel --filter=RoboReceptionistServiceTest`
Expected: FAIL — interface/class not found.

- [ ] **Step 3: Implement the interface**

```php
<?php

declare(strict_types=1);

namespace Modules\RoboReceptionist\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\RoboReceptionist\Models\RoboReceptionist;

/**
 * Contract for robo receptionist CRUD and route management.
 */
interface RoboReceptionistServiceInterface
{
    /**
     * Create a receptionist with its routes in one transaction.
     *
     * @param  array<string, mixed>  $data  Receptionist attributes
     * @param  array<int, array<string, mixed>>  $routes  Route rows
     */
    public function create(array $data, array $routes): RoboReceptionist;

    /**
     * Update a receptionist and replace all of its routes.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $routes
     */
    public function update(RoboReceptionist $receptionist, array $data, array $routes): RoboReceptionist;

    /** Delete a receptionist (routes cascade). */
    public function delete(RoboReceptionist $receptionist): void;

    /** All receptionists for one tenant ordered by name. */
    public function getByTenant(int $tenantId): Collection;
}
```

- [ ] **Step 4: Implement the service**

```php
<?php

declare(strict_types=1);

namespace Modules\RoboReceptionist\Services;

use App\Services\DestinationResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\RoboReceptionist\Models\RoboReceptionist;

/**
 * Robo receptionist CRUD service.
 *
 * Enforces per-tenant unique names, verifies the assigned AI provider
 * belongs to the receptionist's tenant, validates route destination
 * kinds against the resolver whitelist, and keeps receptionist +
 * route writes in one transaction.
 */
class RoboReceptionistService implements RoboReceptionistServiceInterface
{
    /** Build the service with provider-assignment checking. */
    public function __construct(private readonly AiProviderConfigServiceInterface $providers) {}

    /**
     * Create a receptionist with its routes in one transaction.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $routes
     */
    public function create(array $data, array $routes): RoboReceptionist
    {
        $this->validateUniqueName((int) $data['tenant_id'], $data['name'] ?? '', null);
        $this->validateProviderAssignment($data);
        $this->validateRoutes($routes);

        return DB::transaction(function () use ($data, $routes): RoboReceptionist {
            $receptionist = RoboReceptionist::withoutGlobalScope('tenant')->create($data);
            $this->syncRoutes($receptionist, $routes);

            return $receptionist->load('routes');
        });
    }

    /**
     * Update a receptionist and replace all of its routes.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $routes
     */
    public function update(RoboReceptionist $receptionist, array $data, array $routes): RoboReceptionist
    {
        $tenantId = (int) ($data['tenant_id'] ?? $receptionist->tenant_id);

        if (isset($data['name']) && $data['name'] !== $receptionist->name) {
            $this->validateUniqueName($tenantId, $data['name'], $receptionist->id);
        }

        $this->validateProviderAssignment($data + ['tenant_id' => $tenantId]);
        $this->validateRoutes($routes);

        return DB::transaction(function () use ($receptionist, $data, $routes): RoboReceptionist {
            $receptionist->update($data);
            $this->syncRoutes($receptionist, $routes);

            return $receptionist->fresh('routes');
        });
    }

    /** Delete a receptionist; its routes cascade via foreign key. */
    public function delete(RoboReceptionist $receptionist): void
    {
        DB::transaction(fn (): ?RoboReceptionist => $receptionist->delete());
    }

    /** All receptionists for one tenant ordered by name. */
    public function getByTenant(int $tenantId): Collection
    {
        return RoboReceptionist::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->withCount('routes')
            ->orderBy('name')
            ->get();
    }

    /** Replace all routes on the receptionist with the given rows. */
    private function syncRoutes(RoboReceptionist $receptionist, array $routes): void
    {
        $receptionist->routes()->delete();

        foreach ($routes as $index => $route) {
            $receptionist->routes()->create([
                'label' => $route['label'],
                'intent_phrases' => $route['intent_phrases'],
                'destination_kind' => $route['destination_kind'],
                'destination_data' => $route['destination_data'],
                'order' => $route['order'] ?? $index,
                'enabled' => $route['enabled'] ?? true,
            ]);
        }
    }

    /** Enforce unique names per tenant. */
    private function validateUniqueName(int $tenantId, string $name, ?string $excludeId): void
    {
        $exists = RoboReceptionist::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('name', $name)
            ->when($excludeId !== null, fn ($query) => $query->where('id', '!=', $excludeId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => ['A robo receptionist with this name already exists in this tenant.'],
            ]);
        }
    }

    /** The assigned provider must exist, be enabled, and belong to the tenant. */
    private function validateProviderAssignment(array $data): void
    {
        $providerId = $data['ai_provider_config_id'] ?? null;

        if ($providerId === null || $providerId === '') {
            return;
        }

        $config = \Modules\RoboReceptionist\Models\AiProviderConfig::find($providerId);

        if ($config === null || ! $config->enabled) {
            throw ValidationException::withMessages([
                'providerConfigId' => ['The selected AI provider is not available.'],
            ]);
        }

        if (! $this->providers->assignedToTenant($config, (int) $data['tenant_id'])) {
            throw ValidationException::withMessages([
                'providerConfigId' => ['The selected AI provider is not assigned to this tenant.'],
            ]);
        }
    }

    /** Every route must target a resolver-supported destination kind. */
    private function validateRoutes(array $routes): void
    {
        $allowed = DestinationResolver::routeSelectableKinds();

        foreach ($routes as $route) {
            if (! in_array($route['destination_kind'] ?? '', $allowed, true)) {
                throw ValidationException::withMessages([
                    'routes' => ["Unsupported route destination kind: {$route['destination_kind']}."],
                ]);
            }
        }
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact --parallel --filter=RoboReceptionistServiceTest`
Expected: PASS (8 tests).

- [ ] **Step 6: Format and clear caches**

```bash
vendor/bin/pint --dirty --format agent
php artisan optimize:clear
```

- [ ] **Step 7: Commit**

```bash
git add app-modules/robo-receptionist/src/Services/RoboReceptionistService*.php tests/Feature/Pbx/Services/RoboReceptionistServiceTest.php
git commit -m "feat: add robo receptionist service with route sync and provider guard"
```

---

### Task 6: DestinationResolver `robo_receptionist` kind

**Files:**
- Modify: `app/Services/DestinationResolver.php`
- Test: `tests/Feature/Services/DestinationResolverTest.php` (append section)

**Interfaces:**
- Consumes: `RoboReceptionist` model (Task 2).
- Produces: `DestinationResolver::KIND_ROBO_RECEPTIONIST === 'robo_receptionist'`, included in `routeSelectableKinds()` (so inbound-route validation accepts it automatically in Task 10). `resolve('robo_receptionist', $nameOrUuid, $tenantId)` returns `['application' => 'transfer', 'data' => '{name} XML {name}']`, matching the IVR transfer pattern.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Services/DestinationResolverTest.php` (before the closing of the file; uses existing `beforeEach`):

```php
// ─── Robo Receptionist ──────────────────────────────────────────

it('resolves a robo receptionist destination to a transfer', function () {
    $receptionist = \Modules\RoboReceptionist\Models\RoboReceptionist::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Front Desk',
        'enabled' => true,
    ]);

    $result = $this->resolver->resolve(
        \App\Services\DestinationResolver::KIND_ROBO_RECEPTIONIST,
        'Front Desk',
        $this->tenant->id,
    );

    expect($result)->toBe([
        'application' => 'transfer',
        'data' => 'Front Desk XML Front Desk',
    ]);
});

it('rejects a disabled robo receptionist', function () {
    \Modules\RoboReceptionist\Models\RoboReceptionist::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Front Desk',
        'enabled' => false,
    ]);

    $this->resolver->resolve(
        \App\Services\DestinationResolver::KIND_ROBO_RECEPTIONIST,
        'Front Desk',
        $this->tenant->id,
    );
})->throws(InvalidArgumentException::class, 'Robo receptionist not found');

it('includes robo_receptionist in route selectable kinds', function () {
    expect(\App\Services\DestinationResolver::routeSelectableKinds())
        ->toContain(\App\Services\DestinationResolver::KIND_ROBO_RECEPTIONIST);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --parallel --filter=DestinationResolverTest`
Expected: FAIL — undefined constant `KIND_ROBO_RECEPTIONIST`.

- [ ] **Step 3: Implement the kind in `DestinationResolver`**

Add the constant after `KIND_QUEUE` in `app/Services/DestinationResolver.php`:

```php
/** Route to an AI robo receptionist. Identifier is the receptionist name or UUID. */
public const KIND_ROBO_RECEPTIONIST = 'robo_receptionist';
```

Add it to `routeSelectableKinds()` after `self::KIND_QUEUE`:

```php
self::KIND_ROBO_RECEPTIONIST,
```

Add the match arm in `resolve()`:

```php
self::KIND_ROBO_RECEPTIONIST => $this->resolveRoboReceptionist($identifier, $tenantId),
```

Add the resolver method after `resolveQueue()`:

```php
/**
 * Resolve a robo receptionist destination.
 *
 * Transfers the call by receptionist name so the module's dialplan
 * contributor can hand the call to the AI agent stub.
 */
private function resolveRoboReceptionist(string $identifier, int $tenantId): array
{
    $receptionist = \Modules\RoboReceptionist\Models\RoboReceptionist::withoutGlobalScope('tenant')
        ->where('tenant_id', $tenantId)
        ->where('enabled', true)
        ->where(function ($query) use ($identifier) {
            $query->where('name', $identifier)
                ->orWhere('id', $identifier);
        })
        ->first();

    if ($receptionist === null) {
        throw new InvalidArgumentException("Robo receptionist not found: {$identifier} in tenant {$tenantId}");
    }

    return [
        'application' => 'transfer',
        'data' => $receptionist->name.' XML '.$receptionist->name,
    ];
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --parallel --filter=DestinationResolverTest`
Expected: PASS (all existing + 3 new tests).

- [ ] **Step 5: Format and clear caches**

```bash
vendor/bin/pint --dirty --format agent
php artisan optimize:clear
```

- [ ] **Step 6: Commit**

```bash
git add app/Services/DestinationResolver.php tests/Feature/Services/DestinationResolverTest.php
git commit -m "feat: add robo_receptionist destination kind to resolver"
```

---

### Task 7: Dialplan XML contributor (v1 stub)

**Files:**
- Modify: `app-modules/robo-receptionist/src/Services/RoboReceptionistService.php`
- Test: `tests/Feature/Pbx/Services/RoboReceptionistDialplanXmlTest.php`

**Interfaces:**
- Consumes: `App\Contracts\ContextWideDialplanXmlContributor` (methods `getDialplanPriority(): int` and `generateDialplanXml(int $tenantId, string $context, string $destination): ?string`); the `dialplan.xml` container tag registered in Task 1.
- Produces: one dialplan `<extension>` per enabled receptionist named `robo_receptionist_{name}` with condition `^({name})$`, actions: `answer`, `set robo_receptionist_id={id}`, `log INFO`, `sleep 1500`, `hangup NORMAL_CLEARING`. Priority is `75` (after IVR's 70). This is the documented v1 stub — a future transport replaces the body without schema changes.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

// tests/Feature/Pbx/Services/RoboReceptionistDialplanXmlTest.php

use App\Models\Tenant;
use Modules\RoboReceptionist\Models\RoboReceptionist;
use Modules\RoboReceptionist\Services\RoboReceptionistServiceInterface;

beforeEach(function () {
    $this->service = app(RoboReceptionistServiceInterface::class);
    $this->tenant = Tenant::factory()->create();
});

it('generates a stub dialplan extension for enabled receptionists', function () {
    RoboReceptionist::factory()->forTenant($this->tenant->id)->create(['name' => 'Front Desk']);

    $xml = $this->service->generateDialplanXml($this->tenant->id, 'tenant_'.$this->tenant->id.'_internal', 'Front Desk');

    expect($xml)->toContain('<extension name="robo_receptionist_Front Desk">')
        ->and($xml)->toContain('expression="^Front Desk$"')
        ->and($xml)->toContain('<action application="answer"/>')
        ->and($xml)->toContain('application="hangup" data="NORMAL_CLEARING"');
});

it('skips disabled receptionists', function () {
    RoboReceptionist::factory()->forTenant($this->tenant->id)->create([
        'name' => 'Front Desk',
        'enabled' => false,
    ]);

    $xml = $this->service->generateDialplanXml($this->tenant->id, 'ctx', 'Front Desk');

    expect($xml)->toBeNull();
});

it('does not leak receptionists across tenants', function () {
    $other = Tenant::factory()->create();
    RoboReceptionist::factory()->forTenant($other->id)->create(['name' => 'Front Desk']);

    $xml = $this->service->generateDialplanXml($this->tenant->id, 'ctx', 'Front Desk');

    expect($xml)->toBeNull();
});

it('runs after the IVR contributor', function () {
    expect($this->service->getDialplanPriority())->toBe(75);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --parallel --filter=RoboReceptionistDialplanXmlTest`
Expected: FAIL — `generateDialplanXml` does not exist on the service.

- [ ] **Step 3: Implement the contributor**

Modify `RoboReceptionistService` to implement the contract:

```php
use App\Contracts\ContextWideDialplanXmlContributor;
// ...

class RoboReceptionistService implements ContextWideDialplanXmlContributor, RoboReceptionistServiceInterface
{

    /**
     * Feature-level routing — robo receptionists run right after IVR menus.
     */
    public function getDialplanPriority(): int
    {
        return 75;
    }

    /**
     * Generate dialplan XML for robo receptionists.
     *
     * V1 STUB: the AI media transport is not implemented yet, so each
     * enabled receptionist answers, records which agent matched, waits
     * briefly, and hangs down gracefully. A future transport replaces
     * the body of this extension without schema or routing changes.
     */
    public function generateDialplanXml(int $tenantId, string $context, string $destination): ?string
    {
        $receptionists = RoboReceptionist::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('enabled', true)
            ->orderBy('name')
            ->get();

        if ($receptionists->isEmpty()) {
            return null;
        }

        $xml = '';

        foreach ($receptionists as $receptionist) {
            $safeName = htmlspecialchars($receptionist->name, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $safeId = htmlspecialchars($receptionist->id, ENT_XML1 | ENT_QUOTES, 'UTF-8');

            $xml .= "      <extension name=\"robo_receptionist_{$safeName}\">\n";
            $xml .= "        <condition field=\"destination_number\" expression=\"^{$safeName}$\">\n";
            $xml .= "          <action application=\"answer\"/>\n";
            // Identify the matched agent for CDR debugging.
            $xml .= "          <action application=\"set\" data=\"robo_receptionist_id={$safeId}\"/>\n";
            $xml .= "          <action application=\"log\" data=\"INFO robo receptionist {$safeName} reached (v1 stub, transport pending)\"/>\n";
            $xml .= "          <action application=\"sleep\" data=\"1500\"/>\n";
            $xml .= "          <action application=\"hangup\" data=\"NORMAL_CLEARING\"/>\n";
            $xml .= "        </condition>\n";
            $xml .= "      </extension>\n";
        }

        return $xml;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --parallel --filter=RoboReceptionistDialplanXmlTest`
Expected: PASS (4 tests).

Also verify the collector picks the contributor up:

Run: `php artisan test --compact --parallel --filter=XmlHandlerContributorIndexTest`
Expected: PASS (no regressions in the contributor index).

- [ ] **Step 5: Format and clear caches**

```bash
vendor/bin/pint --dirty --format agent
php artisan optimize:clear
```

- [ ] **Step 6: Commit**

```bash
git add app-modules/robo-receptionist/src/Services/RoboReceptionistService.php tests/Feature/Pbx/Services/RoboReceptionistDialplanXmlTest.php
git commit -m "feat: add robo receptionist dialplan stub contributor"
```

---

### Task 8: Module routes and AI provider Livewire pages

**Files:**
- Create: `app-modules/robo-receptionist/routes/web.php`
- Create: `app-modules/robo-receptionist/src/Livewire/AiProvidersList.php`
- Create: `app-modules/robo-receptionist/src/Livewire/AiProvidersEdit.php`
- Create: `app-modules/robo-receptionist/resources/views/ai-providers-list.blade.php`
- Create: `app-modules/robo-receptionist/resources/views/ai-providers-edit.blade.php`
- Modify: `lang/en/admin.php` (provider page keys)
- Test: `tests/Feature/Pbx/Livewire/AiProvidersListTest.php`
- Test: `tests/Feature/Pbx/Livewire/AiProvidersEditTest.php`

**Interfaces:**
- Consumes: `AiProviderConfigServiceInterface` (Task 4); `AiAgentProviderRegistry` (Task 3); permission names from Task 1.
- Produces: routes `panel.robo-receptionist.providers.index|create|edit` (path `/panel/robo-receptionist/providers[...]`) and `panel.robo-receptionist.index|create|edit` (declared now, components in Task 9). The routes file replaces auto-registration for the whole module, so ALL six routes must be declared in this task even though Task 9's components do not exist yet — route registration references component classes lazily, so boot is safe, but visiting receptionist URLs 404s until Task 9.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Pbx/Livewire/AiProvidersListTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Admin;
use Livewire\Livewire;
use Modules\RoboReceptionist\Livewire\AiProvidersList;
use Modules\RoboReceptionist\Models\AiProviderConfig;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
});

it('renders the AI providers list', function () {
    AiProviderConfig::factory()->count(2)->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(AiProvidersList::class)
        ->assertOk()
        ->assertViewHas('providers', fn ($providers) => $providers->count() === 2);
});

it('deletes a provider config after confirmation', function () {
    $config = AiProviderConfig::factory()->create(['name' => 'Main OpenAI']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(AiProvidersList::class)
        ->call('confirmProviderDeletion', $config->id)
        ->assertSet('pendingDeletionId', $config->id)
        ->call('deleteProvider', $config->id);

    $this->assertModelMissing($config);
});

it('shows empty state when no providers exist', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(AiProvidersList::class)
        ->assertSee('No AI provider configs yet');
});
```

`tests/Feature/Pbx/Livewire/AiProvidersEditTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use Livewire\Livewire;
use Modules\RoboReceptionist\Livewire\AiProvidersEdit;
use Modules\RoboReceptionist\Models\AiProviderConfig;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
});

it('creates a provider config with tenant assignments', function () {
    $tenant = Tenant::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(AiProvidersEdit::class)
        ->set('name', 'Main OpenAI')
        ->set('providerKey', 'openai_realtime')
        ->set('apiKey', 'sk-secret-123')
        ->set('tenantIds', [$tenant->id])
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('panel.robo-receptionist.providers.index'));

    $config = AiProviderConfig::firstWhere('name', 'Main OpenAI');
    expect($config)->not->toBeNull()
        ->and($config->api_key)->toBe('sk-secret-123')
        ->and($config->tenants->pluck('id'))->toContain($tenant->id);
});

it('keeps the existing api key when left blank on edit', function () {
    $config = AiProviderConfig::factory()->create(['api_key' => 'sk-original']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(AiProvidersEdit::class, ['providerId' => $config->id])
        ->set('name', $config->name)
        ->set('apiKey', '')
        ->call('save')
        ->assertHasNoErrors();

    expect($config->fresh()->api_key)->toBe('sk-original');
});

it('requires a valid provider key', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(AiProvidersEdit::class)
        ->set('name', 'Bad')
        ->set('providerKey', 'nope')
        ->set('apiKey', 'sk-x')
        ->call('save')
        ->assertHasErrors(['providerKey']);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --parallel --filter=AiProviders`
Expected: FAIL — Livewire component classes not found.

- [ ] **Step 3: Create `routes/web.php`**

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\RoboReceptionist\Livewire\AiProvidersEdit;
use Modules\RoboReceptionist\Livewire\AiProvidersList;
use Modules\RoboReceptionist\Livewire\RoboReceptionistEdit;
use Modules\RoboReceptionist\Livewire\RoboReceptionistList;

/*
 * Robo receptionist panel routes.
 *
 * A routes file is used instead of auto-registration because the
 * module ships two page pairs (receptionists + AI providers) and the
 * provider pages do not follow the {PascalModule}List/Edit naming.
 * Provider routes are declared before the parameterized receptionist
 * edit route so literal segments always win.
 */
Route::prefix('panel')->name('panel.')->middleware(['web', 'auth.panel', 'throttle:60,1'])->group(function (): void {
    // System-wide AI provider configuration pages.
    Route::get('/robo-receptionist/providers', AiProvidersList::class)
        ->middleware('admin.can:robo-receptionist.providers.view')
        ->name('robo-receptionist.providers.index');
    Route::get('/robo-receptionist/providers/create', AiProvidersEdit::class)
        ->middleware('admin.can:robo-receptionist.providers.create')
        ->name('robo-receptionist.providers.create');
    Route::get('/robo-receptionist/providers/{providerId}/edit', AiProvidersEdit::class)
        ->middleware('admin.can:robo-receptionist.providers.edit')
        ->name('robo-receptionist.providers.edit');

    // Per-tenant receptionist pages.
    Route::get('/robo-receptionist', RoboReceptionistList::class)
        ->middleware('admin.can:robo-receptionist.view')
        ->name('robo-receptionist.index');
    Route::get('/robo-receptionist/create', RoboReceptionistEdit::class)
        ->middleware('admin.can:robo-receptionist.create')
        ->name('robo-receptionist.create');
    Route::get('/robo-receptionist/{receptionistId}/edit', RoboReceptionistEdit::class)
        ->middleware('admin.can:robo-receptionist.edit')
        ->name('robo-receptionist.edit');
});
```

- [ ] **Step 4: Implement `AiProvidersList`**

```php
<?php

declare(strict_types=1);

namespace Modules\RoboReceptionist\Livewire;

use App\Jobs\ReloadFreeSwitchXml;
use App\Support\BaseListComponent;
use Illuminate\Database\Eloquent\Collection;
use Modules\RoboReceptionist\Models\AiProviderConfig;
use Modules\RoboReceptionist\Services\AiProviderConfigServiceInterface;

/**
 * Admin page listing system-wide AI provider configurations
 * with their tenant assignment counts and delete confirmation.
 */
class AiProvidersList extends BaseListComponent
{
    /** @var Collection<int, AiProviderConfig> All provider configs */
    public Collection $providers;

    /** ID awaiting delete confirmation in the shared modal. */
    public ?string $pendingDeletionId = null;

    /** Name shown in the delete confirmation modal. */
    public ?string $pendingDeletionName = null;

    /** Error message when a delete fails. */
    public ?string $deleteError = null;

    private AiProviderConfigServiceInterface $providerService;

    /** Inject the provider config service. */
    public function boot(AiProviderConfigServiceInterface $providerService): void
    {
        $this->providerService = $providerService;
    }

    /** Load all provider configs with tenant counts. */
    public function mount(): void
    {
        $this->providers = $this->providerService->all();
    }

    /** Open the confirmation modal for one provider config. */
    public function confirmProviderDeletion(string $providerId): void
    {
        $config = AiProviderConfig::findOrFail($providerId);

        $this->pendingDeletionId = $config->id;
        $this->pendingDeletionName = $config->name;
        $this->deleteError = null;
    }

    /** Close the confirmation modal without deleting. */
    public function cancelProviderDeletion(): void
    {
        $this->pendingDeletionId = null;
        $this->pendingDeletionName = null;
    }

    /** Delete the confirmed provider config and refresh the list. */
    public function deleteProvider(string $providerId): void
    {
        $config = AiProviderConfig::findOrFail($providerId);

        $this->providerService->delete($config);

        $this->cancelProviderDeletion();
        $this->providers = $this->providerService->all();
        $this->dispatch('provider-deleted');

        // No dialplan XML depends on provider configs yet, but keep the
        // reload pattern consistent for when the transport lands.
        ReloadFreeSwitchXml::dispatch('AI provider config deleted');
    }
}
```

- [ ] **Step 5: Implement `AiProvidersEdit`**

```php
<?php

declare(strict_types=1);

namespace Modules\RoboReceptionist\Livewire;

use App\Support\BaseEditComponent;
use Modules\RoboReceptionist\Models\AiProviderConfig;
use Modules\RoboReceptionist\Services\AiAgentProviderRegistry;
use Modules\RoboReceptionist\Services\AiProviderConfigServiceInterface;

/**
 * Create/edit form for system-wide AI provider configurations.
 *
 * The API key field is write-only: on edit, leaving it blank keeps
 * the stored key. Tenant assignment is a multi-select of all tenants.
 */
class AiProvidersEdit extends BaseEditComponent
{
    public string $name = '';

    public string $providerKey = '';

    public string $apiKey = '';

    public string $baseUrl = '';

    public string $model = '';

    /** @var array<int, int> Selected tenant IDs */
    public array $tenantIds = [];

    public ?string $providerId = null;

    private AiProviderConfigServiceInterface $providerService;

    private AiAgentProviderRegistry $registry;

    /** Inject the provider service and registry. */
    public function boot(AiProviderConfigServiceInterface $providerService, AiAgentProviderRegistry $registry): void
    {
        $this->providerService = $providerService;
        $this->registry = $registry;
    }

    /** Load all tenants and, in edit mode, the existing record. */
    public function mount(?string $providerId = null): void
    {
        $this->loadTenants();

        if ($providerId !== null) {
            $this->providerId = $providerId;
            $config = $this->providerService->find($providerId);

            $this->name = $config->name;
            $this->providerKey = $config->provider_key;
            $this->baseUrl = $config->base_url ?? '';
            $this->model = $config->model ?? '';
            $this->enabled = $config->enabled;
            $this->tenantIds = $config->tenants->pluck('id')->map(fn ($id) => (int) $id)->all();
            // Never prefill the API key — it stays write-only.
        }
    }

    /** Whether we are editing an existing provider config. */
    public function getIsEditProperty(): bool
    {
        return $this->providerId !== null;
    }

    /** Registry entries for the provider dropdown. */
    public function getProviderOptionsProperty(): array
    {
        return array_map(
            fn ($provider): array => ['key' => $provider->key(), 'label' => $provider->label()],
            array_values($this->registry->all()),
        );
    }

    /** Validate and save the provider config. */
    public function save(): void
    {
        $this->validate($this->rules());

        $data = [
            'name' => $this->name,
            'provider_key' => $this->providerKey,
            'base_url' => $this->baseUrl ?: null,
            'model' => $this->model ?: null,
            'enabled' => $this->enabled,
        ];

        // Blank key on edit means "keep the existing stored key".
        if ($this->apiKey !== '') {
            $data['api_key'] = $this->apiKey;
        }

        if ($this->providerId !== null) {
            $this->providerService->update($this->providerService->find($this->providerId), $data, $this->tenantIds);
        } else {
            $this->providerService->create($data, $this->tenantIds);
        }

        $this->redirect(route('panel.robo-receptionist.providers.index'));
    }

    /** Validation rules for the provider form. */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'providerKey' => ['required', 'string', 'in:'.implode(',', $this->registry->keys())],
            // Key is required on create; optional on edit (keep existing).
            'apiKey' => [$this->providerId === null ? 'required' : 'nullable', 'string', 'max:2048'],
            'baseUrl' => ['nullable', 'url', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'tenantIds' => ['array'],
            'tenantIds.*' => ['integer', 'exists:tenants,id'],
        ];
    }
}
```

- [ ] **Step 6: Create the two Blade views**

`resources/views/ai-providers-list.blade.php`:

```blade
<div>
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-2"><h2 class="text-2xl font-semibold">{{ __('admin.robo_receptionist_providers_title') }}</h2><x-tooltip :tip="__('admin.page_info_tooltip')" position="right"><x-heroicon-o-information-circle class="w-5 h-5 cursor-help opacity-40 hover:opacity-80" /></x-tooltip></div>
        <a href="{{ route('panel.robo-receptionist.providers.create') }}" class="btn btn-primary btn-sm">
            <x-heroicon-o-plus class="w-4 h-4" />
            {{ __('admin.create_ai_provider') }}
        </a>
    </div>

    @if ($operationalMessage !== null)
        <x-inline-alert :type="$operationalMessageType" :title="null" class="mb-4">{{ $operationalMessage }}</x-inline-alert>
    @endif

    <div class="card bg-base-100 border border-base-300">
        <div class="overflow-x-auto">
            <table class="table table-zebra">
                <thead>
                    <tr>
                        <th>{{ __('admin.ai_provider_name') }}</th>
                        <th>{{ __('admin.ai_provider_type') }}</th>
                        <th>{{ __('admin.ai_provider_tenants') }}</th>
                        <th>{{ __('client.status') }}</th>
                        <th>{{ __('client.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($providers as $provider)
                        <tr>
                            <td class="font-medium">{{ $provider->name }}</td>
                            <td><span class="badge badge-ghost">{{ $provider->provider_key }}</span></td>
                            <td>
                                @if ($provider->tenants_count > 0)
                                    <span class="badge badge-ghost">{{ $provider->tenants_count }} {{ __('admin.tenants') }}</span>
                                @else
                                    <span class="text-base-content/50">—</span>
                                @endif
                            </td>
                            <td>
                                @if ($provider->enabled)
                                    <span class="badge badge-success badge-sm">{{ __('admin.enabled') }}</span>
                                @else
                                    <span class="badge badge-ghost badge-sm">{{ __('admin.disabled') }}</span>
                                @endif
                            </td>
                            <td>
                                <div class="flex gap-2">
                                    <a href="{{ route('panel.robo-receptionist.providers.edit', $provider->id) }}" class="btn btn-ghost btn-xs">
                                        <x-heroicon-o-pencil class="w-4 h-4" />
                                    </a>
                                    <button wire:click="confirmProviderDeletion('{{ $provider->id }}')" class="btn btn-ghost btn-xs text-error">
                                        <x-heroicon-o-trash class="w-4 h-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center py-8 text-base-content/40">
                                {{ __('admin.no_ai_providers_found') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($pendingDeletionId !== null)
        <x-confirmation-modal
            :open="true"
            title="Delete AI Provider?"
            :message="'Delete “'.$pendingDeletionName.'”. Receptionists using it become unassigned. This cannot be undone.'"
            confirm-label="Delete AI Provider"
            confirm-action="deleteProvider('{{ $pendingDeletionId }}')"
            cancel-action="cancelProviderDeletion"
            :error="$deleteError"
            error-title="AI Provider Cannot Be Deleted"
        />
    @endif
</div>
```

`resources/views/ai-providers-edit.blade.php`:

```blade
<div class="card bg-base-100 shadow-xl max-w-2xl">
    <div class="card-body">
        <h1 class="text-2xl font-bold mb-6">{{ $this->isEdit ? __('client.edit') : __('client.create') }} {{ __('admin.ai_provider_config') }}</h1>
        <form wire:submit="save" class="space-y-6">
            <div class="form-control w-full">
                <label class="label" for="name"><span class="label-text">{{ __('admin.ai_provider_name') }}</span></label>
                <input wire:model="name" id="name" type="text" class="input input-bordered w-full" />
                @error('name')<span class="text-error text-xs mt-1">{{ $message }}</span>@enderror
            </div>
            <div class="form-control w-full">
                <label class="label" for="providerKey"><span class="label-text">{{ __('admin.ai_provider_type') }}</span></label>
                <select wire:model="providerKey" id="providerKey" class="select select-bordered w-full">
                    <option value="">Select a provider...</option>
                    @foreach ($this->providerOptions as $option)
                        <option value="{{ $option['key'] }}">{{ $option['label'] }}</option>
                    @endforeach
                </select>
                @error('providerKey')<span class="text-error text-xs mt-1">{{ $message }}</span>@enderror
            </div>
            <div class="form-control w-full">
                <x-tooltip tip="Stored encrypted. Leave blank on edit to keep the current key." position="right">
                    <label class="label" for="apiKey"><span class="label-text">{{ __('admin.ai_provider_api_key') }}</span></label>
                </x-tooltip>
                <input wire:model="apiKey" id="apiKey" type="password" class="input input-bordered w-full font-mono" placeholder="{{ $this->isEdit ? '••••••••' : '' }}" />
                @error('apiKey')<span class="text-error text-xs mt-1">{{ $message }}</span>@enderror
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="form-control w-full">
                    <label class="label" for="baseUrl"><span class="label-text">{{ __('admin.ai_provider_base_url') }}</span></label>
                    <input wire:model="baseUrl" id="baseUrl" type="url" class="input input-bordered w-full" placeholder="https://api.openai.com/v1" />
                    @error('baseUrl')<span class="text-error text-xs mt-1">{{ $message }}</span>@enderror
                </div>
                <div class="form-control w-full">
                    <label class="label" for="model"><span class="label-text">{{ __('admin.ai_provider_model') }}</span></label>
                    <input wire:model="model" id="model" type="text" class="input input-bordered w-full" placeholder="gpt-4o-realtime" />
                    @error('model')<span class="text-error text-xs mt-1">{{ $message }}</span>@enderror
                </div>
            </div>
            <div class="form-control w-full">
                <label class="label" for="tenantIds"><span class="label-text">{{ __('admin.ai_provider_tenants') }}</span></label>
                <select wire:model="tenantIds" id="tenantIds" multiple class="select select-bordered w-full min-h-32">
                    @foreach ($tenants as $tenant)<option value="{{ $tenant->id }}">{{ $tenant->name }}</option>@endforeach
                </select>
                @error('tenantIds')<span class="text-error text-xs mt-1">{{ $message }}</span>@enderror
            </div>
            <div class="form-control">
                <label class="label cursor-pointer justify-start gap-3">
                    <input wire:model="enabled" type="checkbox" class="toggle toggle-primary" />
                    <span class="label-text">{{ __('admin.enabled') }}</span>
                </label>
            </div>
            <div class="flex gap-3">
                <button type="submit" class="btn btn-primary">{{ __('client.save') }}</button>
                <a href="{{ route('panel.robo-receptionist.providers.index') }}" class="btn btn-ghost">{{ __('client.cancel') }}</a>
            </div>
        </form>
    </div>
</div>
```

- [ ] **Step 7: Add translation keys to `lang/en/admin.php`**

```php
'robo_receptionist_providers_title' => 'AI Provider Configuration',
'ai_provider_config' => 'AI Provider Config',
'create_ai_provider' => 'Create AI Provider',
'ai_provider_name' => 'Name',
'ai_provider_type' => 'Provider',
'ai_provider_api_key' => 'API Key',
'ai_provider_base_url' => 'Base URL',
'ai_provider_model' => 'Model',
'ai_provider_tenants' => 'Assigned Tenants',
'no_ai_providers_found' => 'No AI provider configs yet',
'tenants' => 'tenants',
```

- [ ] **Step 8: Run tests to verify they pass**

```bash
php artisan optimize:clear
php artisan test --compact --parallel --filter=AiProviders
```

Expected: PASS (6 tests).

- [ ] **Step 9: Verify route registration**

Run: `php artisan route:list --name=robo-receptionist`
Expected: six routes listed (`providers.index/create/edit`, `index/create/edit`). Receptionist component classes do not exist yet; that is resolved in Task 9.

- [ ] **Step 10: Format and clear caches**

```bash
vendor/bin/pint --dirty --format agent
php artisan optimize:clear
```

- [ ] **Step 11: Commit**

```bash
git add app-modules/robo-receptionist/routes app-modules/robo-receptionist/src/Livewire/AiProviders*.php app-modules/robo-receptionist/resources/views/ai-providers-*.blade.php lang/en/admin.php tests/Feature/Pbx/Livewire/AiProviders*.php
git commit -m "feat: add AI provider config pages for robo receptionist module"
```

---

### Task 9: Receptionist Livewire pages (list + edit with route editor)

**Files:**
- Create: `app-modules/robo-receptionist/src/Livewire/RoboReceptionistList.php`
- Create: `app-modules/robo-receptionist/src/Livewire/RoboReceptionistEdit.php`
- Create: `app-modules/robo-receptionist/resources/views/robo-receptionist-list.blade.php`
- Create: `app-modules/robo-receptionist/resources/views/robo-receptionist-edit.blade.php`
- Modify: `lang/en/admin.php` (receptionist page keys)
- Test: `tests/Feature/Pbx/Livewire/RoboReceptionistListTest.php`
- Test: `tests/Feature/Pbx/Livewire/RoboReceptionistEditTest.php`

**Interfaces:**
- Consumes: `RoboReceptionistServiceInterface` (Task 5); `AiProviderConfigServiceInterface` + `AiAgentProviderRegistry` (Tasks 3-4); `DestinationResolver::routeSelectableKinds()` (Task 6); routes declared in Task 8.
- Produces: pages reachable at `panel.robo-receptionist.index|create|edit`. The edit component exposes `public array $routes` (rows with keys `label`, `intentPhrases`, `destinationKind`, `destinationData`, `enabled`), methods `addRoute()`, `removeRoute(int $index)`, `save()`; computed `providerOptions` (providers assigned to the form's tenant) and `destinationKinds` (resolver selectable kinds).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Pbx/Livewire/RoboReceptionistListTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Admin;
use Livewire\Livewire;
use Modules\RoboReceptionist\Livewire\RoboReceptionistList;
use Modules\RoboReceptionist\Models\RoboReceptionist;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
});

it('renders the receptionist list', function () {
    RoboReceptionist::factory()->count(2)->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(RoboReceptionistList::class)
        ->assertOk()
        ->assertViewHas('receptionists', fn ($items) => $items->count() === 2);
});

it('deletes a receptionist after confirmation', function () {
    $receptionist = RoboReceptionist::factory()->create(['name' => 'Front Desk']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(RoboReceptionistList::class)
        ->call('confirmReceptionistDeletion', $receptionist->id)
        ->assertSet('pendingDeletionId', $receptionist->id)
        ->call('deleteReceptionist', $receptionist->id);

    $this->assertModelMissing($receptionist);
});

it('shows empty state when no receptionists exist', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(RoboReceptionistList::class)
        ->assertSee('No robo receptionists configured yet');
});
```

`tests/Feature/Pbx/Livewire/RoboReceptionistEditTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use App\Services\DestinationResolver;
use Livewire\Livewire;
use Modules\RoboReceptionist\Models\AiProviderConfig;
use Modules\RoboReceptionist\Models\RoboReceptionist;
use Modules\RoboReceptionist\Livewire\RoboReceptionistEdit;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
    $this->tenant = Tenant::factory()->create();
});

it('creates a receptionist with one route', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(RoboReceptionistEdit::class)
        ->set('tenantId', $this->tenant->id)
        ->set('name', 'Front Desk')
        ->set('greetingText', 'Hello! How can I direct your call?')
        ->set('voiceGender', 'female')
        ->set('language', 'en-US')
        ->call('addRoute')
        ->set('routes.0.label', 'Sales')
        ->set('routes.0.intentPhrases', 'sales, purchase, pricing')
        ->set('routes.0.destinationKind', DestinationResolver::KIND_EXTENSION)
        ->set('routes.0.destinationData', '101')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('panel.robo-receptionist.index'));

    $receptionist = RoboReceptionist::withoutGlobalScope('tenant')->firstWhere('name', 'Front Desk');
    expect($receptionist)->not->toBeNull()
        ->and($receptionist->routes)->toHaveCount(1)
        ->and($receptionist->routes->first()->destination_kind)->toBe('extension');
});

it('only offers providers assigned to the selected tenant', function () {
    $assigned = AiProviderConfig::factory()->create(['name' => 'Assigned']);
    $assigned->tenants()->sync([$this->tenant->id]);
    AiProviderConfig::factory()->create(['name' => 'Unassigned']);

    $component = Livewire::actingAs($this->admin, 'admin')
        ->test(RoboReceptionistEdit::class)
        ->set('tenantId', $this->tenant->id);

    $labels = collect($component->get('providerOptions'))->pluck('name')->all();
    expect($labels)->toContain('Assigned')->not->toContain('Unassigned');
});

it('rejects a provider not assigned to the tenant', function () {
    $config = AiProviderConfig::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(RoboReceptionistEdit::class)
        ->set('tenantId', $this->tenant->id)
        ->set('name', 'Front Desk')
        ->set('greetingText', 'Hello')
        ->set('providerConfigId', $config->id)
        ->call('save')
        ->assertHasErrors(['providerConfigId']);
});

it('removes a route row', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(RoboReceptionistEdit::class)
        ->call('addRoute')
        ->call('addRoute')
        ->call('removeRoute', 0)
        ->assertSet('routes', fn (array $routes): bool => count($routes) === 1);
});

it('loads existing receptionist data including routes', function () {
    $receptionist = RoboReceptionist::factory()->forTenant($this->tenant->id)->create(['name' => 'Front Desk']);
    $receptionist->routes()->create([
        'label' => 'Support',
        'intent_phrases' => 'help, support',
        'destination_kind' => 'voicemail',
        'destination_data' => '100',
        'order' => 0,
        'enabled' => true,
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(RoboReceptionistEdit::class, ['receptionistId' => $receptionist->id])
        ->assertSet('name', 'Front Desk')
        ->assertSet('routes.0.label', 'Support')
        ->assertSet('routes.0.destinationKind', 'voicemail');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --parallel --filter=RoboReceptionist`
Expected: FAIL — Livewire component classes not found.

- [ ] **Step 3: Implement `RoboReceptionistList`**

```php
<?php

declare(strict_types=1);

namespace Modules\RoboReceptionist\Livewire;

use App\Jobs\ReloadFreeSwitchXml;
use App\Support\BaseListComponent;
use Illuminate\Database\Eloquent\Collection;
use Modules\RoboReceptionist\Models\RoboReceptionist;
use Modules\RoboReceptionist\Services\RoboReceptionistServiceInterface;

/**
 * Page listing robo receptionists with delete confirmation.
 * Admins see all tenants; tenant users see only their own.
 */
class RoboReceptionistList extends BaseListComponent
{
    /** @var Collection<int, RoboReceptionist> Visible receptionists */
    public Collection $receptionists;

    /** ID awaiting delete confirmation in the shared modal. */
    public ?string $pendingDeletionId = null;

    /** Name shown in the delete confirmation modal. */
    public ?string $pendingDeletionName = null;

    /** Error message when a delete fails. */
    public ?string $deleteError = null;

    private RoboReceptionistServiceInterface $receptionistService;

    /** Inject the receptionist service. */
    public function boot(RoboReceptionistServiceInterface $receptionistService): void
    {
        $this->receptionistService = $receptionistService;
    }

    /** Load receptionists — all tenants for admins, own tenant otherwise. */
    public function mount(): void
    {
        $this->receptionists = RoboReceptionist::query()
            ->when($this->isAdminGuard(), fn ($query) => $query->withoutGlobalScope('tenant'))
            ->withCount('routes')
            ->with('provider')
            ->orderBy('name')
            ->get();
    }

    /** Open the confirmation modal for one receptionist. */
    public function confirmReceptionistDeletion(string $receptionistId): void
    {
        $receptionist = RoboReceptionist::withoutGlobalScope('tenant')->findOrFail($receptionistId);

        $this->pendingDeletionId = $receptionist->id;
        $this->pendingDeletionName = $receptionist->name;
        $this->deleteError = null;
    }

    /** Close the confirmation modal without deleting. */
    public function cancelReceptionistDeletion(): void
    {
        $this->pendingDeletionId = null;
        $this->pendingDeletionName = null;
    }

    /** Delete the confirmed receptionist and refresh the list. */
    public function deleteReceptionist(string $receptionistId): void
    {
        $receptionist = RoboReceptionist::withoutGlobalScope('tenant')->findOrFail($receptionistId);

        $this->receptionistService->delete($receptionist);

        $this->cancelReceptionistDeletion();
        $this->mount();
        $this->dispatch('receptionist-deleted');

        // Dialplan fragments changed — FreeSWITCH must re-fetch XML.
        ReloadFreeSwitchXml::dispatch('Robo receptionist deleted');
    }
}
```

- [ ] **Step 4: Implement `RoboReceptionistEdit`**

```php
<?php

declare(strict_types=1);

namespace Modules\RoboReceptionist\Livewire;

use App\Jobs\ReloadFreeSwitchXml;
use App\Services\DestinationResolver;
use App\Support\BaseEditComponent;
use Modules\RoboReceptionist\Models\AiProviderConfig;
use Modules\RoboReceptionist\Models\RoboReceptionist;
use Modules\RoboReceptionist\Services\RoboReceptionistServiceInterface;

/**
 * Create/edit form for robo receptionists.
 *
 * Includes the greeting text, voice selection, assigned AI provider
 * (limited to providers assigned to the selected tenant), fallback
 * destination, and a dynamic list of intent-phrase routes.
 */
class RoboReceptionistEdit extends BaseEditComponent
{
    public string $name = '';

    public string $greetingText = '';

    public string $instructions = '';

    public string $voice = '';

    public string $voiceGender = '';

    public string $language = 'en-US';

    public ?string $providerConfigId = null;

    public int $timeout = 10;

    public int $maxFailures = 3;

    public string $fallbackKind = '';

    public string $fallbackData = '';

    /**
     * Route rows being edited. Each row:
     * ['label' => string, 'intentPhrases' => string,
     *  'destinationKind' => string, 'destinationData' => string,
     *  'enabled' => bool]
     *
     * @var array<int, array<string, mixed>>
     */
    public array $routes = [];

    public ?string $receptionistId = null;

    private RoboReceptionistServiceInterface $receptionistService;

    /** Inject the receptionist service. */
    public function boot(RoboReceptionistServiceInterface $receptionistService): void
    {
        $this->receptionistService = $receptionistService;
    }

    /** Load tenants and, in edit mode, the existing receptionist. */
    public function mount(?string $receptionistId = null): void
    {
        $this->loadTenants();

        if ($receptionistId !== null) {
            $this->receptionistId = $receptionistId;
            $receptionist = RoboReceptionist::withoutGlobalScope('tenant')
                ->with('routes')
                ->findOrFail($receptionistId);

            $this->tenantId = $receptionist->tenant_id;
            $this->name = $receptionist->name;
            $this->greetingText = $receptionist->greeting_text;
            $this->instructions = $receptionist->instructions ?? '';
            $this->voice = $receptionist->voice ?? '';
            $this->voiceGender = $receptionist->voice_gender ?? '';
            $this->language = $receptionist->language ?? 'en-US';
            $this->providerConfigId = $receptionist->ai_provider_config_id;
            $this->timeout = $receptionist->timeout;
            $this->maxFailures = $receptionist->max_failures;
            $this->fallbackKind = $receptionist->fallback_kind ?? '';
            $this->fallbackData = $receptionist->fallback_data ?? '';
            $this->enabled = $receptionist->enabled;

            // Map stored route rows into the camelCase form-field shape.
            $this->routes = $receptionist->routes->map(fn ($route): array => [
                'label' => $route->label,
                'intentPhrases' => $route->intent_phrases,
                'destinationKind' => $route->destination_kind,
                'destinationData' => $route->destination_data,
                'enabled' => $route->enabled,
            ])->all();
        }
    }

    /** Whether we are editing an existing receptionist. */
    public function getIsEditProperty(): bool
    {
        return $this->receptionistId !== null;
    }

    /**
     * Provider configs assignable to the selected tenant:
     * enabled and assigned through the pivot.
     */
    public function getProviderOptionsProperty(): array
    {
        if ($this->tenantId === null) {
            return [];
        }

        return AiProviderConfig::where('enabled', true)
            ->whereHas('tenants', fn ($query) => $query->whereKey($this->tenantId))
            ->orderBy('name')
            ->get()
            ->all();
    }

    /** Resolver destination kinds selectable for routes and fallback. */
    public function getDestinationKindsProperty(): array
    {
        return DestinationResolver::routeSelectableKinds();
    }

    /** Append an empty route row. */
    public function addRoute(): void
    {
        $this->routes[] = [
            'label' => '',
            'intentPhrases' => '',
            'destinationKind' => '',
            'destinationData' => '',
            'enabled' => true,
        ];
    }

    /** Remove the route row at the given index. */
    public function removeRoute(int $index): void
    {
        unset($this->routes[$index]);
        $this->routes = array_values($this->routes);
    }

    /** Validate and save the receptionist with its routes. */
    public function save(): void
    {
        $this->validate($this->rules());

        $data = [
            'tenant_id' => $this->resolveTenantId(),
            'name' => $this->name,
            'greeting_text' => $this->greetingText,
            'instructions' => $this->instructions ?: null,
            'voice' => $this->voice ?: null,
            'voice_gender' => $this->voiceGender ?: null,
            'language' => $this->language ?: null,
            'ai_provider_config_id' => $this->providerConfigId,
            'timeout' => $this->timeout,
            'max_failures' => $this->maxFailures,
            'fallback_kind' => $this->fallbackKind ?: null,
            'fallback_data' => $this->fallbackKind !== '' ? $this->fallbackData : null,
            'enabled' => $this->enabled,
        ];

        // Convert camelCase form rows to the service's snake_case shape.
        $routes = array_map(fn (array $row): array => [
            'label' => $row['label'],
            'intent_phrases' => $row['intentPhrases'],
            'destination_kind' => $row['destinationKind'],
            'destination_data' => $row['destinationData'],
            'enabled' => $row['enabled'],
        ], $this->routes);

        if ($this->receptionistId !== null) {
            $receptionist = RoboReceptionist::withoutGlobalScope('tenant')->findOrFail($this->receptionistId);
            $this->receptionistService->update($receptionist, $data, $routes);
        } else {
            $this->receptionistService->create($data, $routes);
        }

        $this->redirect(route('panel.robo-receptionist.index'));

        // Dialplan fragments changed — FreeSWITCH must re-fetch XML.
        ReloadFreeSwitchXml::dispatch('Robo receptionist saved');
    }

    /** Validation rules for the receptionist form. */
    public function rules(): array
    {
        $kinds = implode(',', $this->getDestinationKindsProperty());

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'greetingText' => ['required', 'string', 'max:2000'],
            'instructions' => ['nullable', 'string', 'max:4000'],
            'voice' => ['nullable', 'string', 'max:255'],
            'voiceGender' => ['nullable', 'string', 'in:male,female'],
            'language' => ['nullable', 'string', 'max:16'],
            'providerConfigId' => ['nullable', 'string', 'exists:ai_provider_configs,id'],
            'timeout' => ['required', 'integer', 'min:1', 'max:120'],
            'maxFailures' => ['required', 'integer', 'min:1', 'max:10'],
            'fallbackKind' => ['nullable', 'string', 'in:'.$kinds],
            'fallbackData' => ['nullable', 'string', 'max:255'],
            'routes' => ['array'],
            'routes.*.label' => ['required', 'string', 'max:255'],
            'routes.*.intentPhrases' => ['required', 'string', 'max:2000'],
            'routes.*.destinationKind' => ['required', 'string', 'in:'.$kinds],
            'routes.*.destinationData' => ['required', 'string', 'max:255'],
        ];

        // Admins pick the tenant from a dropdown; tenant users are auto-scoped.
        if ($this->isAdminGuard()) {
            $rules['tenantId'] = ['required', 'integer', 'exists:tenants,id'];
        }

        return $rules;
    }
}
```

Note: the provider-assignment violation is raised by the service as a `ValidationException` keyed on `providerConfigId`, which Livewire maps back to a form error — this is what the "rejects a provider not assigned" test asserts.

- [ ] **Step 5: Create the two Blade views**

`resources/views/robo-receptionist-list.blade.php`:

```blade
<div>
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-2"><h2 class="text-2xl font-semibold">{{ __('admin.robo_receptionists_title') }}</h2><x-tooltip :tip="__('admin.page_info_tooltip')" position="right"><x-heroicon-o-information-circle class="w-5 h-5 cursor-help opacity-40 hover:opacity-80" /></x-tooltip></div>
        <a href="{{ route('panel.robo-receptionist.create') }}" class="btn btn-primary btn-sm">
            <x-heroicon-o-plus class="w-4 h-4" />
            {{ __('admin.create_robo_receptionist') }}
        </a>
    </div>

    @if ($operationalMessage !== null)
        <x-inline-alert :type="$operationalMessageType" :title="null" class="mb-4">{{ $operationalMessage }}</x-inline-alert>
    @endif

    <div class="card bg-base-100 border border-base-300">
        <div class="overflow-x-auto">
            <table class="table table-zebra">
                <thead>
                    <tr>
                        <th>{{ __('admin.robo_receptionist_name') }}</th>
                        <th>{{ __('admin.ai_provider_type') }}</th>
                        <th>{{ __('admin.robo_receptionist_routes') }}</th>
                        <th>{{ __('admin.robo_receptionist_voice') }}</th>
                        <th>{{ __('client.status') }}</th>
                        <th>{{ __('client.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($receptionists as $receptionist)
                        <tr>
                            <td class="font-medium">{{ $receptionist->name }}</td>
                            <td>
                                @if ($receptionist->provider !== null)
                                    <span class="badge badge-ghost">{{ $receptionist->provider->name }}</span>
                                @else
                                    <span class="text-base-content/50">—</span>
                                @endif
                            </td>
                            <td>
                                @if ($receptionist->routes_count > 0)
                                    <span class="badge badge-ghost">{{ $receptionist->routes_count }} {{ __('admin.robo_receptionist_routes') }}</span>
                                @else
                                    <span class="text-base-content/50">—</span>
                                @endif
                            </td>
                            <td>{{ $receptionist->voice_gender ?? '—' }}{{ $receptionist->language ? ' / '.$receptionist->language : '' }}</td>
                            <td>
                                @if ($receptionist->enabled)
                                    <span class="badge badge-success badge-sm">{{ __('admin.enabled') }}</span>
                                @else
                                    <span class="badge badge-ghost badge-sm">{{ __('admin.disabled') }}</span>
                                @endif
                            </td>
                            <td>
                                <div class="flex gap-2">
                                    <a href="{{ route('panel.robo-receptionist.edit', $receptionist->id) }}" class="btn btn-ghost btn-xs">
                                        <x-heroicon-o-pencil class="w-4 h-4" />
                                    </a>
                                    <button wire:click="confirmReceptionistDeletion('{{ $receptionist->id }}')" class="btn btn-ghost btn-xs text-error">
                                        <x-heroicon-o-trash class="w-4 h-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center py-8 text-base-content/40">
                                {{ __('admin.no_robo_receptionists_found') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($pendingDeletionId !== null)
        <x-confirmation-modal
            :open="true"
            title="Delete Robo Receptionist?"
            :message="'Delete “'.$pendingDeletionName.'”. This cannot be undone.'"
            confirm-label="Delete Robo Receptionist"
            confirm-action="deleteReceptionist('{{ $pendingDeletionId }}')"
            cancel-action="cancelReceptionistDeletion"
            :error="$deleteError"
            error-title="Robo Receptionist Cannot Be Deleted"
        />
    @endif
</div>
```

`resources/views/robo-receptionist-edit.blade.php`:

```blade
<div class="card bg-base-100 shadow-xl max-w-3xl">
    <div class="card-body">
        <h1 class="text-2xl font-bold mb-6">{{ $this->isEdit ? __('client.edit') : __('client.create') }} {{ __('admin.robo_receptionist') }}</h1>
        <form wire:submit="save" class="space-y-6">
            @if ($this->isAdminGuard())
                <div class="form-control w-full">
                    <label class="label" for="tenantId"><span class="label-text">Tenant</span></label>
                    <select wire:model.live="tenantId" id="tenantId" class="select select-bordered w-full">
                        <option value="">Select a tenant...</option>
                        @foreach ($tenants as $tenant)<option value="{{ $tenant->id }}">{{ $tenant->name }}</option>@endforeach
                    </select>
                    @error('tenantId')<span class="text-error text-xs mt-1">{{ $message }}</span>@enderror
                </div>
            @endif

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="form-control w-full">
                    <label class="label" for="name"><span class="label-text">{{ __('admin.robo_receptionist_name') }}</span></label>
                    <input wire:model="name" id="name" type="text" class="input input-bordered w-full" />
                    @error('name')<span class="text-error text-xs mt-1">{{ $message }}</span>@enderror
                </div>
                <div class="form-control w-full">
                    <label class="label" for="providerConfigId"><span class="label-text">{{ __('admin.ai_provider_type') }}</span></label>
                    <select wire:model="providerConfigId" id="providerConfigId" class="select select-bordered w-full">
                        <option value="">None (stub only)</option>
                        @foreach ($this->providerOptions as $provider)<option value="{{ $provider->id }}">{{ $provider->name }}</option>@endforeach
                    </select>
                    @error('providerConfigId')<span class="text-error text-xs mt-1">{{ $message }}</span>@enderror
                </div>
            </div>

            <div class="form-control w-full">
                <x-tooltip tip="Text the AI speaks when it answers, using the selected voice." position="right">
                    <label class="label" for="greetingText"><span class="label-text">{{ __('admin.robo_receptionist_greeting') }}</span></label>
                </x-tooltip>
                <textarea wire:model="greetingText" id="greetingText" rows="2" class="textarea textarea-bordered w-full"></textarea>
                @error('greetingText')<span class="text-error text-xs mt-1">{{ $message }}</span>@enderror
            </div>

            <div class="form-control w-full">
                <label class="label" for="instructions"><span class="label-text">{{ __('admin.robo_receptionist_instructions') }}</span></label>
                <textarea wire:model="instructions" id="instructions" rows="3" class="textarea textarea-bordered w-full" placeholder="You are a friendly receptionist..."></textarea>
                @error('instructions')<span class="text-error text-xs mt-1">{{ $message }}</span>@enderror
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="form-control w-full">
                    <label class="label" for="voice"><span class="label-text">{{ __('admin.robo_receptionist_voice') }}</span></label>
                    <input wire:model="voice" id="voice" type="text" class="input input-bordered w-full font-mono" placeholder="alloy" />
                    @error('voice')<span class="text-error text-xs mt-1">{{ $message }}</span>@enderror
                </div>
                <div class="form-control w-full">
                    <label class="label" for="voiceGender"><span class="label-text">{{ __('admin.robo_receptionist_voice_gender') }}</span></label>
                    <select wire:model="voiceGender" id="voiceGender" class="select select-bordered w-full">
                        <option value="">Any</option>
                        <option value="female">Female</option>
                        <option value="male">Male</option>
                    </select>
                    @error('voiceGender')<span class="text-error text-xs mt-1">{{ $message }}</span>@enderror
                </div>
                <div class="form-control w-full">
                    <label class="label" for="language"><span class="label-text">{{ __('admin.robo_receptionist_language') }}</span></label>
                    <input wire:model="language" id="language" type="text" class="input input-bordered w-full" placeholder="en-US" />
                    @error('language')<span class="text-error text-xs mt-1">{{ $message }}</span>@enderror
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="form-control w-full">
                    <label class="label" for="timeout"><span class="label-text">{{ __('admin.robo_receptionist_timeout') }}</span></label>
                    <input wire:model="timeout" id="timeout" type="number" min="1" max="120" class="input input-bordered w-full" />
                    @error('timeout')<span class="text-error text-xs mt-1">{{ $message }}</span>@enderror
                </div>
                <div class="form-control w-full">
                    <label class="label" for="maxFailures"><span class="label-text">{{ __('admin.robo_receptionist_max_failures') }}</span></label>
                    <input wire:model="maxFailures" id="maxFailures" type="number" min="1" max="10" class="input input-bordered w-full" />
                    @error('maxFailures')<span class="text-error text-xs mt-1">{{ $message }}</span>@enderror
                </div>
            </div>

            {{-- Intent-phrase routes --}}
            <div class="divider">{{ __('admin.robo_receptionist_routes') }}</div>
            @foreach ($routes as $index => $route)
                <div class="border border-base-300 rounded-lg p-4 space-y-3">
                    <div class="flex items-end justify-between">
                        <span class="text-sm font-semibold">{{ __('admin.robo_receptionist_route') }} #{{ $index + 1 }}</span>
                        <button type="button" wire:click="removeRoute({{ $index }})" class="btn btn-ghost btn-xs text-error">
                            <x-heroicon-o-trash class="w-4 h-4" />
                        </button>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="form-control w-full">
                            <label class="label"><span class="label-text">{{ __('admin.robo_receptionist_route_label') }}</span></label>
                            <input wire:model="routes.{{ $index }}.label" type="text" class="input input-bordered w-full" placeholder="Sales" />
                            @error('routes.'.$index.'.label')<span class="text-error text-xs mt-1">{{ $message }}</span>@enderror
                        </div>
                        <div class="form-control w-full">
                            <label class="label"><span class="label-text">{{ __('admin.robo_receptionist_route_phrases') }}</span></label>
                            <input wire:model="routes.{{ $index }}.intentPhrases" type="text" class="input input-bordered w-full" placeholder="sales, purchase, pricing" />
                            @error('routes.'.$index.'.intentPhrases')<span class="text-error text-xs mt-1">{{ $message }}</span>@enderror
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="form-control w-full">
                            <label class="label"><span class="label-text">{{ __('admin.robo_receptionist_route_kind') }}</span></label>
                            <select wire:model="routes.{{ $index }}.destinationKind" class="select select-bordered w-full">
                                <option value="">Select destination type...</option>
                                @foreach ($this->destinationKinds as $kind)<option value="{{ $kind }}">{{ $kind }}</option>@endforeach
                            </select>
                            @error('routes.'.$index.'.destinationKind')<span class="text-error text-xs mt-1">{{ $message }}</span>@enderror
                        </div>
                        <div class="form-control w-full">
                            <label class="label"><span class="label-text">{{ __('admin.robo_receptionist_route_data') }}</span></label>
                            <input wire:model="routes.{{ $index }}.destinationData" type="text" class="input input-bordered w-full" placeholder="Identifier" />
                            @error('routes.'.$index.'.destinationData')<span class="text-error text-xs mt-1">{{ $message }}</span>@enderror
                        </div>
                    </div>
                    <label class="label cursor-pointer justify-start gap-3">
                        <input wire:model="routes.{{ $index }}.enabled" type="checkbox" class="toggle toggle-primary toggle-sm" />
                        <span class="label-text">{{ __('admin.enabled') }}</span>
                    </label>
                </div>
            @endforeach
            <button type="button" wire:click="addRoute" class="btn btn-outline btn-sm">
                <x-heroicon-o-plus class="w-4 h-4" />
                {{ __('admin.robo_receptionist_add_route') }}
            </button>

            {{-- Fallback destination --}}
            <div class="divider">{{ __('admin.robo_receptionist_fallback') }}</div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="form-control w-full">
                    <label class="label" for="fallbackKind"><span class="label-text">{{ __('admin.robo_receptionist_fallback_kind') }}</span></label>
                    <select wire:model="fallbackKind" id="fallbackKind" class="select select-bordered w-full">
                        <option value="">None (hang up)</option>
                        @foreach ($this->destinationKinds as $kind)<option value="{{ $kind }}">{{ $kind }}</option>@endforeach
                    </select>
                    @error('fallbackKind')<span class="text-error text-xs mt-1">{{ $message }}</span>@enderror
                </div>
                <div class="form-control w-full">
                    <label class="label" for="fallbackData"><span class="label-text">{{ __('admin.robo_receptionist_fallback_data') }}</span></label>
                    <input wire:model="fallbackData" id="fallbackData" type="text" class="input input-bordered w-full" placeholder="Identifier" />
                    @error('fallbackData')<span class="text-error text-xs mt-1">{{ $message }}</span>@enderror
                </div>
            </div>

            <div class="form-control">
                <label class="label cursor-pointer justify-start gap-3">
                    <input wire:model="enabled" type="checkbox" class="toggle toggle-primary" />
                    <span class="label-text">{{ __('admin.enabled') }}</span>
                </label>
            </div>

            <div class="flex gap-3">
                <button type="submit" class="btn btn-primary">{{ __('client.save') }}</button>
                <a href="{{ route('panel.robo-receptionist.index') }}" class="btn btn-ghost">{{ __('client.cancel') }}</a>
            </div>
        </form>
    </div>
</div>
```

- [ ] **Step 6: Add translation keys to `lang/en/admin.php`**

```php
'robo_receptionists_title' => 'Robo Receptionists',
'robo_receptionist' => 'Robo Receptionist',
'create_robo_receptionist' => 'Create Robo Receptionist',
'robo_receptionist_name' => 'Name',
'robo_receptionist_greeting' => 'Greeting Text',
'robo_receptionist_instructions' => 'Instructions',
'robo_receptionist_voice' => 'Voice',
'robo_receptionist_voice_gender' => 'Voice Gender',
'robo_receptionist_language' => 'Language',
'robo_receptionist_timeout' => 'Timeout (seconds)',
'robo_receptionist_max_failures' => 'Max Failures',
'robo_receptionist_routes' => 'Routes',
'robo_receptionist_route' => 'Route',
'robo_receptionist_route_label' => 'Label',
'robo_receptionist_route_phrases' => 'Intent Phrases',
'robo_receptionist_route_kind' => 'Destination Type',
'robo_receptionist_route_data' => 'Destination',
'robo_receptionist_add_route' => 'Add Route',
'robo_receptionist_fallback' => 'Fallback',
'robo_receptionist_fallback_kind' => 'Fallback Destination Type',
'robo_receptionist_fallback_data' => 'Fallback Destination',
'no_robo_receptionists_found' => 'No robo receptionists configured yet',
```

- [ ] **Step 7: Run tests to verify they pass**

```bash
php artisan optimize:clear
php artisan test --compact --parallel --filter=RoboReceptionist
```

Expected: PASS (all RoboReceptionist tests, 8 new + previous tasks' tests).

- [ ] **Step 8: Format and clear caches**

```bash
vendor/bin/pint --dirty --format agent
php artisan optimize:clear
```

- [ ] **Step 9: Commit**

```bash
git add app-modules/robo-receptionist/src/Livewire/RoboReceptionist*.php app-modules/robo-receptionist/resources/views/robo-receptionist-*.blade.php lang/en/admin.php tests/Feature/Pbx/Livewire/RoboReceptionist*.php
git commit -m "feat: add robo receptionist list and edit pages with route editor"
```

---

### Task 10: Inbound-route wiring, super-admin permissions, and final verification

**Files:**
- Modify: `app-modules/inbound-routes/resources/views/inbound-routes-edit.blade.php`
- Test: `tests/Feature/Modules/RoboReceptionist/RoboReceptionistRoutingWiringTest.php`

**Interfaces:**
- Consumes: `DestinationResolver::KIND_ROBO_RECEPTIONIST` in `routeSelectableKinds()` (Task 6) — inbound-route validation already accepts the kind; this task only exposes it in the dropdown and proves the wiring end-to-end.
- Produces: inbound routes can select `robo_receptionist` as an action.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

// tests/Feature/Modules/RoboReceptionist/RoboReceptionistRoutingWiringTest.php

use App\Models\Admin;
use App\Models\Tenant;
use Livewire\Livewire;
use Modules\InboundRoutes\Livewire\InboundRoutesEdit;
use Modules\InboundRoutes\Models\InboundRoute;
use Modules\RoboReceptionist\Models\RoboReceptionist;

it('accepts robo_receptionist as an inbound route action', function () {
    $admin = Admin::factory()->create(['enabled' => true]);
    $tenant = Tenant::factory()->create();
    RoboReceptionist::factory()->forTenant($tenant->id)->create(['name' => 'Front Desk']);

    Livewire::actingAs($admin, 'admin')
        ->test(InboundRoutesEdit::class)
        ->set('tenantId', $tenant->id)
        ->set('name', 'Main DID')
        ->set('destinationNumber', '+15551234567')
        ->set('action', 'robo_receptionist')
        ->set('actionData', 'Front Desk')
        ->call('save')
        ->assertHasNoErrors();

    expect(InboundRoute::withoutGlobalScope('tenant')->where('action', 'robo_receptionist')->exists())->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --parallel --filter=RoboReceptionistRoutingWiringTest`
Expected: this test may actually PASS already because validation is whitelist-based — verify first. If it passes, keep it as regression coverage and proceed directly to Step 3 (the dropdown change is UI-only). If it fails, inspect `InboundRouteService` handling of resolver kinds and fix before continuing.

- [ ] **Step 3: Add the dropdown option**

In `app-modules/inbound-routes/resources/views/inbound-routes-edit.blade.php`, extend the action `<select>`:

```blade
<option value="robo_receptionist">Robo Receptionist</option>
```

(Insert after the `<option value="voicemail">Voicemail</option>` line.)

- [ ] **Step 4: Run wiring test again**

Run: `php artisan test --compact --parallel --filter=RoboReceptionistRoutingWiringTest`
Expected: PASS.

- [ ] **Step 5: Grant permissions to the Super Admin group**

```bash
php artisan db:seed --class=AdminSeeder
php artisan tinker --execute 'echo Permission::where("name","robo-receptionist.view")->exists() ? "YES" : "NO";'
```

Expected: `YES`. Repeat for `robo-receptionist.providers.view` if desired.

- [ ] **Step 6: Full module verification checklist**

```bash
php artisan optimize:clear
php artisan module:sync --only-local
php artisan route:list --name=robo-receptionist
php artisan test --compact --parallel --filter=RoboReceptionist
php artisan test --compact --parallel --filter=AiProviders
php artisan test --compact --parallel --filter=DestinationResolverTest
php artisan test --compact --parallel --filter=XmlHandlerContributorIndexTest
vendor/bin/pint --dirty --format agent
```

Verify with grep that translation keys exist:

```bash
grep -c "robo_receptionist" lang/en/admin.php
```

Expected: 6 routes in `route:list`, all tests green, grep count ≥ 20.

- [ ] **Step 7: Optional Dusk smoke test (UI changes are present)**

If the environment supports it per `scripts/dusk.sh`, run the browser suite and confirm the new pages render:

```bash
bash scripts/dusk.sh
```

If Dusk coverage for this module is desired, add a smoke visit of `/panel/robo-receptionist` and `/panel/robo-receptionist/providers` in the existing Dusk smoke test — otherwise note the gap in the task report.

- [ ] **Step 8: Commit**

```bash
git add app-modules/inbound-routes/resources/views/inbound-routes-edit.blade.php tests/Feature/Modules/RoboReceptionist/RoboReceptionistRoutingWiringTest.php
git commit -m "feat: expose robo receptionist as inbound route destination"
```

---

## Self-Review Notes

- **Spec coverage:** AI-provider subscription with admin API keys → Tasks 3, 4, 8 (system-wide configs assignable to tenants). Voice prompts/voice type config → receptionist fields (Tasks 2, 5, 9). IVR-style route configuration → intent-phrase routes with resolver kinds (Tasks 2, 5, 9). Pluggable provider with one first implementation → Task 3. Config-plane complete with transport stubbed → Tasks 6, 7. Reachability as destination → Tasks 6, 10.
- **Type consistency:** service method names (`create(array $data, array $routes)`, `assignedToTenant()`, `syncRoutes()`) and model column names are identical across schema, services, Livewire components, and tests. Registry key `openai_realtime` matches the factory default and registry tests.
- **Known deferrals (intentional, documented in the spec):** live media transport, provider-side call handling, ES/fr locales for new keys (en is authoritative here), and Dusk coverage are out of scope for this plan.
