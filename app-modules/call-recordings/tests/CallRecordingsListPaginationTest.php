<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Livewire;
use Modules\CallRecordings\Livewire\CallRecordingsList;
use Modules\CallRecordings\Models\CallRecording;

beforeEach(function (): void {
    $this->admin = Admin::factory()->create(['enabled' => true]);
});

it('paginates call recordings to 25 items per page and prevents snapshot collection bloat', function (): void {
    $tenant = Tenant::factory()->create();
    CallRecording::factory()->count(60)->create(['tenant_id' => $tenant->id]);

    $component = Livewire::actingAs($this->admin, 'admin')
        ->test(CallRecordingsList::class)
        ->assertOk()
        ->assertViewHas('recordings', function ($recordings): bool {
            return $recordings instanceof LengthAwarePaginator
                && $recordings->count() === 25
                && $recordings->total() === 60;
        })
        ->assertSeeHtml('aria-label="Pagination Navigation"');

    preg_match('/wire:snapshot="([^"]+)"/', $component->html(), $matches);
    expect($matches)->not->toBeEmpty();

    $decoded = json_decode(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5), true);
    expect($decoded['data'])->not->toHaveKey('recordings');
});
