<?php

declare(strict_types=1);

namespace App\Support\Concerns;

use App\Support\Xml\XmlWriter;

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
        return XmlWriter::escapeXml($value);
    }
}
