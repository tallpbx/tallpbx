<?php

declare(strict_types=1);

namespace App\Support;

use SimpleXMLElement;

/**
 * Validates FreeSWITCH's mod_xml_curl configuration before the switch loads it.
 *
 * FreeSWITCH registers every `<binding>` it finds, even one without a
 * `gateway-url`. Such a binding reaches the module with a null url, and the
 * first lookup that lands on it passes NULL to strstr() and kills the switch
 * with a SIGSEGV. The check below refuses that configuration at deploy time,
 * where the failure is cheap, instead of at the first registration.
 */
final class FreeSwitchXmlCurlConfig
{
    /**
     * Default location of the generated configuration file.
     */
    public const DEFAULT_PATH = '/etc/freeswitch/autoload_configs/xml_curl.conf.xml';

    /**
     * Return every reason the configuration would crash the switch, or an
     * empty array when it is safe to load.
     *
     * The checks are intentionally narrow: only conditions that make
     * mod_xml_curl dereference a null or unusable binding are reported.
     *
     * @param  string  $path  Absolute path to xml_curl.conf.xml
     * @return array<int, string> Human-readable problems, empty when valid
     */
    public static function errors(string $path): array
    {
        if (! is_file($path)) {
            return ["xml_curl.conf.xml not found at {$path}"];
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            return ["xml_curl.conf.xml at {$path} could not be read"];
        }

        // Disabled bindings are written as XML comments by the installer and
        // by the switch tooling. Strip them first so a commented-out binding
        // is never mistaken for an active one, and never reported as broken.
        $contents = (string) preg_replace('/<!--.*?-->/s', '', $contents);

        $document = self::parse($contents);

        if ($document === null) {
            return ["xml_curl.conf.xml at {$path} is not valid XML"];
        }

        $errors = [];

        foreach (self::bindings($document) as $binding) {
            $name = trim((string) ($binding['name'] ?? '')) ?: 'unnamed';

            if (! self::hasGatewayUrl($binding)) {
                $errors[] = "gateway-url missing for binding [{$name}]";
            }
        }

        return $errors;
    }

    /**
     * Parse the document without letting libxml emit warnings into the app log.
     *
     * @param  string  $contents  XML document body
     * @return SimpleXMLElement|null Parsed document, or null when malformed
     */
    private static function parse(string $contents): ?SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);

        $document = simplexml_load_string($contents);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document === false ? null : $document;
    }

    /**
     * List the active `<binding>` elements, accepting a document that omits
     * the optional `<bindings>` wrapper.
     *
     * @param  SimpleXMLElement  $document  Parsed configuration
     * @return array<int, SimpleXMLElement> Binding elements
     */
    private static function bindings(SimpleXMLElement $document): array
    {
        $bindings = [];

        foreach ($document->bindings->binding ?? [] as $binding) {
            $bindings[] = $binding;
        }

        foreach ($document->binding ?? [] as $binding) {
            $bindings[] = $binding;
        }

        return $bindings;
    }

    /**
     * Report whether a binding carries a usable gateway-url parameter.
     *
     * An empty value is rejected as well: the module would build a request
     * against an empty URL and never receive a document to parse.
     *
     * @param  SimpleXMLElement  $binding  A `<binding>` element
     */
    private static function hasGatewayUrl(SimpleXMLElement $binding): bool
    {
        foreach ($binding->param as $param) {
            if ((string) $param['name'] !== 'gateway-url') {
                continue;
            }

            if (trim((string) $param['value']) !== '') {
                return true;
            }
        }

        return false;
    }
}
