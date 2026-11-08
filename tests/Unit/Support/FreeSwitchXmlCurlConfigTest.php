<?php

declare(strict_types=1);

use App\Support\FreeSwitchXmlCurlConfig;

/**
 * Write a temporary xml_curl configuration file for a test.
 *
 * @param  string  $contents  XML document to write
 * @return string Absolute path to the temporary file
 */
function xmlCurlFixture(string $contents): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'xmlcurl');
    file_put_contents($path, $contents);

    return $path;
}

it('reports a binding that has no gateway-url', function (): void {
    // FreeSWITCH registers such a binding with a null url and segfaults on the
    // first lookup, so the validator must name the offending binding.
    $path = xmlCurlFixture(<<<'XML'
<configuration name="xml_curl.conf" description="cURL XML Gateway">
  <bindings>
    <binding name="broken">
      <param name="bindings" value="directory"/>
    </binding>
  </bindings>
</configuration>
XML);

    $errors = FreeSwitchXmlCurlConfig::errors($path);

    expect($errors)->toHaveCount(1)
        ->and($errors[0])->toContain('broken')
        ->and($errors[0])->toContain('gateway-url');

    unlink($path);
});

it('accepts a configuration whose bindings all carry a gateway-url', function (): void {
    $path = xmlCurlFixture(<<<'XML'
<configuration name="xml_curl.conf" description="cURL XML Gateway">
  <bindings>
    <binding name="tallpbx-fallback">
      <param name="gateway-url" value="http://127.0.0.1/api/v1/xml-handler?token=abc" bindings="directory|dialplan"/>
    </binding>
  </bindings>
</configuration>
XML);

    expect(FreeSwitchXmlCurlConfig::errors($path))->toBe([]);

    unlink($path);
});

it('ignores bindings that are commented out', function (): void {
    // The installer and the switch tooling disable a binding by commenting it
    // out; a commented binding cannot be registered and must not be reported.
    $path = xmlCurlFixture(<<<'XML'
<configuration name="xml_curl.conf" description="cURL XML Gateway">
  <bindings>
    <!-- <binding name="tallpbx-octane">
      <param name="gateway-url" value="http://127.0.0.1:8090/api/v1/xml-handler"/>
    </binding> -->
    <binding name="tallpbx-fallback">
      <param name="gateway-url" value="http://127.0.0.1/api/v1/xml-handler?token=abc"/>
    </binding>
  </bindings>
</configuration>
XML);

    expect(FreeSwitchXmlCurlConfig::errors($path))->toBe([]);

    unlink($path);
});

it('reports a missing configuration file', function (): void {
    $errors = FreeSwitchXmlCurlConfig::errors('/nonexistent/xml_curl.conf.xml');

    expect($errors)->toHaveCount(1)
        ->and($errors[0])->toContain('xml_curl.conf.xml');
});

it('reports malformed XML instead of silently passing', function (): void {
    $path = xmlCurlFixture('<configuration><bindings><binding name="x">');

    $errors = FreeSwitchXmlCurlConfig::errors($path);

    expect($errors)->toHaveCount(1)
        ->and($errors[0])->toContain('not valid XML');

    unlink($path);
});
