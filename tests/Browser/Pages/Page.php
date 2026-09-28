<?php

namespace Tests\Browser\Pages;

use Laravel\Dusk\Page as BasePage;

/**
 * Base page object for the Dusk browser tests.
 */
abstract class Page extends BasePage
{
    /**
     * Get the global element shortcuts for the site.
     *
     * @return array<string, string>
     */
    public static function siteElements(): array
    {
        return [
            '@element' => '#selector',
        ];
    }
}
