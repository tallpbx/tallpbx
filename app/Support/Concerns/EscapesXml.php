<?php

declare(strict_types=1);

namespace App\Support\Concerns;

/**
 * Escapes values for safe inclusion in XML documents.
 */
trait EscapesXml
{
    /**
     * Escape special XML characters in a string.
     */
    protected function escapeXml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
