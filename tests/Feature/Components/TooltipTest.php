<?php

declare(strict_types=1);

test('it renders text tooltip with data-tip attribute', function (): void {
    $view = $this->blade('<x-tooltip tip="Help text">Hover me</x-tooltip>');

    $view->assertSee('data-tip="Help text"', false)
        ->assertSee('Hover me', false)
        ->assertSee('tooltip', false);
});

test('it renders html tooltip with tooltip-content div', function (): void {
    $view = $this->blade(<<<'BLADE'
        <x-tooltip>
            Hover me
            <x-slot:content><strong>Rich</strong> content</x-slot:content>
        </x-tooltip>
    BLADE);

    $view->assertSee('tooltip-content', false)
        ->assertSee('<strong>Rich</strong> content', false)
        ->assertSee('Hover me', false)
        ->assertDontSee('data-tip', false);
});

test('it renders icon trigger when no default slot provided', function (): void {
    $view = $this->blade('<x-tooltip icon="heroicon-o-question-mark-circle" tip="Help" />');

    $view->assertSee('data-tip="Help"', false)
        ->assertSee('cursor-help', false)
        ->assertSee('<svg', false);
});

test('it applies position class', function (): void {
    $view = $this->blade('<x-tooltip tip="Help" position="bottom">Hover</x-tooltip>');

    $view->assertSee('tooltip-bottom', false);
});

test('it defaults to top position with no extra class', function (): void {
    $view = $this->blade('<x-tooltip tip="Help">Hover</x-tooltip>');

    $view->assertDontSee('tooltip-top', false);
});

test('it renders trigger named slot when provided', function (): void {
    $view = $this->blade(<<<'BLADE'
        <x-tooltip tip="Help">
            <x-slot:trigger>Custom Trigger</x-slot:trigger>
        </x-tooltip>
    BLADE);

    $view->assertSee('Custom Trigger', false)
        ->assertSee('data-tip="Help"', false);
});

test('it renders nothing inside tooltip bubble when no tip or content', function (): void {
    $view = $this->blade('<x-tooltip>Just text</x-tooltip>');

    $view->assertDontSee('data-tip', false)
        ->assertDontSee('tooltip-content', false)
        ->assertSee('Just text', false);
});

test('it merges additional attributes onto the wrapper div', function (): void {
    $view = $this->blade('<x-tooltip tip="Help" class="extra-class" id="my-tooltip">Hover</x-tooltip>');

    $view->assertSee('extra-class', false)
        ->assertSee('id="my-tooltip"', false)
        ->assertSee('tooltip', false);
});

test('it prefers html content slot over data-tip when both provided', function (): void {
    $view = $this->blade(<<<'BLADE'
        <x-tooltip tip="Should not appear">
            Hover
            <x-slot:content>HTML wins</x-slot:content>
        </x-tooltip>
    BLADE);

    $view->assertSee('tooltip-content', false)
        ->assertSee('HTML wins', false)
        ->assertDontSee('data-tip', false);
});
