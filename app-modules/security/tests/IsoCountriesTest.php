<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use Modules\Security\Support\IsoCountries;

it('provides the complete standardized ISO 3166-1 alpha-2 countries catalog', function (): void {
    $countries = IsoCountries::all();

    expect($countries)->toBeArray()
        ->and(count($countries))->toBe(249)
        ->and($countries['TH'])->toBe('Thailand')
        ->and($countries['PH'])->toBe('Philippines')
        ->and($countries['PS'])->toBe('Palestine')
        ->and($countries['US'])->toBe('United States');

    foreach ($countries as $code => $name) {
        expect($code)->toMatch('/^[A-Z]{2}$/')
            ->and($name)->toBeString()->not->toBeEmpty();
    }
});

it('resolves country names and checks existence case-insensitively', function (): void {
    expect(IsoCountries::name('th'))->toBe('Thailand')
        ->and(IsoCountries::name('PS'))->toBe('Palestine')
        ->and(IsoCountries::name('XX'))->toBeNull()
        ->and(IsoCountries::has('ph'))->toBeTrue()
        ->and(IsoCountries::has('ZZ'))->toBeFalse();
});
