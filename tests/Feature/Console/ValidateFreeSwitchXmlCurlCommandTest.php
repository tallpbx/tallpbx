<?php

declare(strict_types=1);

/**
 * Write a temporary xml_curl configuration file for a command test.
 *
 * @param  string  $contents  XML document to write
 * @return string Absolute path to the temporary file
 */
function xmlCurlCommandFixture(string $contents): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'xmlcurl');
    file_put_contents($path, $contents);

    return $path;
}

it('fails when a binding has no gateway-url', function (): void {
    $path = xmlCurlCommandFixture(<<<'XML'
<configuration name="xml_curl.conf" description="cURL XML Gateway">
  <bindings>
    <binding name="broken">
      <param name="bindings" value="directory"/>
    </binding>
  </bindings>
</configuration>
XML);

    $this->artisan('freeswitch:validate-xml-curl', ['--path' => $path])
        ->expectsOutputToContain('gateway-url missing for binding [broken]')
        ->assertFailed();

    unlink($path);
});

it('passes for a configuration whose bindings carry a gateway-url', function (): void {
    $path = xmlCurlCommandFixture(<<<'XML'
<configuration name="xml_curl.conf" description="cURL XML Gateway">
  <bindings>
    <binding name="tallpbx-fallback">
      <param name="gateway-url" value="http://127.0.0.1/api/v1/xml-handler?token=abc"/>
    </binding>
  </bindings>
</configuration>
XML);

    $this->artisan('freeswitch:validate-xml-curl', ['--path' => $path])
        ->expectsOutputToContain('valid')
        ->assertSuccessful();

    unlink($path);
});

it('runs the validation from the FreeSWITCH installer before the module can load', function (): void {
    // The installer writes xml_curl.conf.xml on every host; validating there is
    // what stops a binding without a gateway-url from reaching a customer PBX.
    $script = (string) file_get_contents(base_path('scripts/resources/freeswitch.sh'));

    expect($script)->toContain('FreeSwitchXmlCurlConfig::errors')
        ->and($script)->toContain('binding without a gateway-url');
});
