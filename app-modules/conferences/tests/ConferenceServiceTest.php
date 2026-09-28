<?php

declare(strict_types=1);

use App\Models\Tenant;
use Modules\Conferences\Models\Conference;
use Modules\Conferences\Services\ConferenceService;

beforeEach(function () {
    $this->service = app(ConferenceService::class);
    $this->tenant = Tenant::factory()->create();
});

it('creates a conference via service', function () {
    $conference = $this->service->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'All Hands',
        'profile' => 'default',
        'pin' => '4321',
        'max_members' => 50,
        'enabled' => true,
    ]);

    expect($conference)->toBeInstanceOf(Conference::class)
        ->name->toBe('All Hands')
        ->profile->toBe('default')
        ->pin->toBe('4321')
        ->max_members->toBe(50)
        ->enabled->toBeTrue();

    $this->assertDatabaseHas('conferences', [
        'id' => $conference->id,
        'name' => 'All Hands',
    ]);
});

it('updates a conference via service', function () {
    $conference = $this->service->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Old Room',
        'profile' => 'default',
        'pin' => '1111',
        'max_members' => 20,
        'enabled' => true,
    ]);

    $updated = $this->service->update($conference, [
        'name' => 'New Room',
        'pin' => '2222',
        'max_members' => 30,
    ]);

    expect($updated->name)->toBe('New Room')
        ->and($updated->pin)->toBe('2222')
        ->and($updated->max_members)->toBe(30);

    $this->assertDatabaseHas('conferences', [
        'id' => $conference->id,
        'name' => 'New Room',
        'pin' => '2222',
    ]);
});

it('deletes a conference via service', function () {
    $conference = $this->service->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'To Delete',
        'profile' => 'default',
        'max_members' => 10,
        'enabled' => true,
    ]);

    $this->service->delete($conference);

    $this->assertModelMissing($conference);
});

it('returns dialplan priority of 70', function () {
    expect($this->service->getDialplanPriority())->toBe(70);
});

it('returns null for dialplan xml when no conferences exist', function () {
    $xml = $this->service->generateDialplanXml($this->tenant->id, 'default', '1000');

    expect($xml)->toBeNull();
});

it('generates dialplan xml for enabled conferences with pin and max members', function () {
    $this->service->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'BoardRoom',
        'profile' => 'cdquality',
        'pin' => '9876',
        'max_members' => 25,
        'enabled' => true,
    ]);

    $xml = $this->service->generateDialplanXml($this->tenant->id, 'default', 'BoardRoom');

    expect($xml)->not->toBeNull()
        ->and($xml)->toContain('extension name="conference_BoardRoom"')
        ->and($xml)->toContain('condition field="destination_number" expression="^BoardRoom$"')
        ->and($xml)->toContain('application="conference"')
        ->and($xml)->toContain('BoardRoom@cdquality')
        ->and($xml)->toContain('+pin_9876')
        ->and($xml)->toContain('+max-members_25');
});
