<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Marks a dialplan contributor whose XML does not vary by destination number.
 *
 * The collector can safely cache these contributors by tenant and context
 * because they generate a full set of dialplan extensions for that context.
 * Contributors that build different XML for each destination should not
 * implement this interface.
 */
interface ContextWideDialplanXmlContributor extends DialplanXmlContributor
{
    //
}
