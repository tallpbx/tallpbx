<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use App\Services\TenantManager;
use Modules\CallDetailRecords\Models\Cdr;

/*
 * Recording playback must never serve a file outside the media roots or a
 * recording belonging to another tenant. The stored path comes from
 * FreeSWITCH, so it is joined to the configured recording root when relative,
 * canonicalized with realpath(), and prefix-checked before anything streams.
 */

beforeEach(function () {
    // Serve recordings from a throwaway media root so containment can be
    // exercised without touching real media.
    $this->mediaRoot = sys_get_temp_dir().'/cdr-media-'.uniqid();
    mkdir($this->mediaRoot.'/store', 0775, true);
    mkdir($this->mediaRoot.'/spool', 0775, true);
    config([
        'media-storage.store_root' => $this->mediaRoot.'/store',
        'media-storage.spool_root' => $this->mediaRoot.'/spool',
    ]);

    $this->tenant = Tenant::factory()->create();
});

afterEach(function () {
    app(TenantManager::class)->clear();
});

/**
 * Write one fake recording file inside a root and return its path.
 */
function recordingFixture(string $root, string $name): string
{
    $path = $root.'/'.$name;

    if (! is_dir($root)) {
        mkdir($root, 0775, true);
    }

    file_put_contents($path, 'RIFF-fake-wav');

    return $path;
}

it('streams a recording for an administrator', function () {
    $path = recordingFixture($this->mediaRoot.'/store', 'call-1.wav');
    $cdr = Cdr::factory()->create(['record_path' => $path]);
    $admin = grantAdminPermissions(Admin::factory()->create(['enabled' => true]), ['call-detail-records.view']);

    $response = $this->actingAs($admin, 'admin')->get(route('panel.cdr.recording', $cdr->id));

    $response->assertOk()
        ->assertHeader('Content-Type', 'audio/wav')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    // The exact canonical file is served — nothing substituted.
    expect((string) $response->baseResponse->getFile()->getPathname())->toBe(realpath($path));
});

it('streams a recording for the owning tenant user', function () {
    $path = recordingFixture($this->mediaRoot.'/spool', 'call-2.wav');
    $cdr = Cdr::factory()->create(['tenant_id' => $this->tenant->id, 'record_path' => $path]);
    $user = grantTenantUserPermissions($this->tenant, ['call-detail-records.view']);

    $response = $this->actingAs($user, 'web')->get(route('panel.cdr.recording', $cdr->id));

    $response->assertOk();

    expect((string) $response->baseResponse->getFile()->getPathname())->toBe(realpath($path));
});

it('hides another tenant\'s recording from a tenant user', function () {
    $otherTenant = Tenant::factory()->create();
    $path = recordingFixture($this->mediaRoot.'/store', 'call-3.wav');
    $foreignCdr = Cdr::factory()->create(['tenant_id' => $otherTenant->id, 'record_path' => $path]);
    $user = grantTenantUserPermissions($this->tenant, ['call-detail-records.view']);

    // Invisible, not forbidden: the answer must not disclose that another
    // tenant owns this record id.
    $this->actingAs($user, 'web')
        ->get(route('panel.cdr.recording', $foreignCdr->id))
        ->assertNotFound();
});

it('refuses paths that escape the recording roots', function () {
    $cdr = Cdr::factory()->create(['record_path' => $this->mediaRoot.'/store/../../etc/passwd']);
    $admin = grantAdminPermissions(Admin::factory()->create(['enabled' => true]), ['call-detail-records.view']);

    $this->actingAs($admin, 'admin')
        ->get(route('panel.cdr.recording', $cdr->id))
        ->assertNotFound();
});

it('refuses absolute paths outside the recording roots', function () {
    $cdr = Cdr::factory()->create(['record_path' => '/etc/hostname']);
    $admin = grantAdminPermissions(Admin::factory()->create(['enabled' => true]), ['call-detail-records.view']);

    $this->actingAs($admin, 'admin')
        ->get(route('panel.cdr.recording', $cdr->id))
        ->assertNotFound();
});

it('answers 404 for a missing file and for a record without a recording', function () {
    $missing = Cdr::factory()->create(['record_path' => $this->mediaRoot.'/store/gone.wav']);
    $unrecorded = Cdr::factory()->create(['record_path' => null]);
    $admin = grantAdminPermissions(Admin::factory()->create(['enabled' => true]), ['call-detail-records.view']);

    $this->actingAs($admin, 'admin')->get(route('panel.cdr.recording', $missing->id))->assertNotFound();

    $this->actingAs($admin, 'admin')->get(route('panel.cdr.recording', $unrecorded->id))->assertNotFound();
});
