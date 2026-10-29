<?php

declare(strict_types=1);

namespace App\Support\Xml;

use Stringable;

/**
 * Fluent builder for generating well-formed, escaped FreeSWITCH XML documents.
 */
class XmlWriter implements Stringable
{
    /**
     * Accumulated XML content.
     */
    private string $xml = '';

    /**
     * Start the XML document with a standard declaration header.
     */
    public function startDocument(string $version = '1.0', string $encoding = 'UTF-8'): self
    {
        $this->xml .= '<?xml version="'.$version.'" encoding="'.$encoding.'"?>'."\n";

        return $this;
    }

    /**
     * Open an XML tag with optional attributes and indentation.
     *
     * @param  array<string, scalar|null>  $attributes
     */
    public function openTag(string $tag, array $attributes = [], int $indent = 0): self
    {
        $this->xml .= $this->indent($indent).'<'.$this->escape($tag).$this->formatAttributes($attributes).'>'."\n";

        return $this;
    }

    /**
     * Write a self-closing XML tag with optional attributes and indentation.
     *
     * @param  array<string, scalar|null>  $attributes
     */
    public function selfClosingTag(string $tag, array $attributes = [], int $indent = 0): self
    {
        $this->xml .= $this->indent($indent).'<'.$this->escape($tag).$this->formatAttributes($attributes).'/>'."\n";

        return $this;
    }

    /**
     * Close an XML tag with indentation and optional trailing newline.
     */
    public function closeTag(string $tag, int $indent = 0, bool $newline = true): self
    {
        $this->xml .= $this->indent($indent).'</'.$this->escape($tag).'>'.($newline ? "\n" : '');

        return $this;
    }

    /**
     * Append a raw trusted XML fragment without additional escaping.
     */
    public function rawTrustedFragment(string $xml): self
    {
        $this->xml .= $xml;

        return $this;
    }

    /**
     * Write an XML comment with indentation.
     */
    public function comment(string $comment, int $indent = 0): self
    {
        $this->xml .= $this->indent($indent).'<!-- '.$comment.' -->'."\n";

        return $this;
    }

    /**
     * Append a newline separator to the document.
     */
    public function newline(): self
    {
        $this->xml .= "\n";

        return $this;
    }

    /**
     * Check if a tag name is present in the allowlist.
     *
     * @param  array<int, string>  $allowlist
     */
    public function isAllowedTag(string $tag, array $allowlist): bool
    {
        return in_array($tag, $allowlist, true);
    }

    /**
     * Escape special XML characters for safe inclusion in XML.
     */
    public static function escapeXml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * Escape special XML characters for safe inclusion in XML.
     */
    public function escape(string $value): string
    {
        return self::escapeXml($value);
    }

    /**
     * Return the accumulated XML string.
     */
    public function toString(): string
    {
        return $this->xml;
    }

    /**
     * Return the accumulated XML string when cast to string.
     */
    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * Generate leading whitespace indentation.
     */
    private function indent(int $spaces): string
    {
        return $spaces > 0 ? str_repeat(' ', $spaces) : '';
    }

    /**
     * Format an attribute dictionary into an XML attribute string.
     *
     * @param  array<string, scalar|null>  $attributes
     */
    private function formatAttributes(array $attributes): string
    {
        $formatted = '';

        foreach ($attributes as $name => $value) {
            if ($value === null) {
                continue;
            }

            $stringVal = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            $formatted .= ' '.$this->escape($name).'="'.$this->escape($stringVal).'"';
        }

        return $formatted;
    }
}
