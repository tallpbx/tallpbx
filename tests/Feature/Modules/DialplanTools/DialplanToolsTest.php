<?php

declare(strict_types=1);

use App\Models\Admin;
use Livewire\Livewire;
use Modules\DialplanTools\Livewire\DialplanTester;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
});

it('renders the dialplan tools page', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(DialplanTester::class)
        ->assertOk()
        ->assertSee('Dialplan Tools');
});

it('tests a regex pattern against a number', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(DialplanTester::class)
        ->set('testPattern', '^(\\d{3})(\\d{4})$')
        ->set('testNumber', '1234567')
        ->call('testRegex')
        ->assertOk()
        ->assertSee('123')
        ->assertSee('4567');
});

it('shows no match message', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(DialplanTester::class)
        ->set('testPattern', '^999$')
        ->set('testNumber', '1234567')
        ->call('testRegex')
        ->assertOk()
        ->assertSee('No match');
});

it('validates required fields', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(DialplanTester::class)
        ->call('testRegex')
        ->assertHasErrors(['testPattern', 'testNumber']);
});
