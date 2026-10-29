<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Xml\XmlWriter;

describe('XmlWriter', function (): void {
    it('outputs standard xml declaration with startDocument', function (): void {
        $writer = new XmlWriter;
        $writer->startDocument();

        expect($writer->toString())->toBe("<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n");
    });

    it('outputs custom version and encoding with startDocument', function (): void {
        $writer = new XmlWriter;
        $writer->startDocument('1.1', 'ISO-8859-1');

        expect($writer->toString())->toBe("<?xml version=\"1.1\" encoding=\"ISO-8859-1\"?>\n");
    });

    it('renders openTag with attributes and indentation', function (): void {
        $writer = new XmlWriter;
        $writer->openTag('section', ['name' => 'dialplan'], 2);

        expect($writer->toString())->toBe("  <section name=\"dialplan\">\n");
    });

    it('renders selfClosingTag with attributes and indentation', function (): void {
        $writer = new XmlWriter;
        $writer->selfClosingTag('action', [
            'application' => 'hangup',
            'data' => 'NO_ROUTE_DESTINATION',
        ], 10);

        expect($writer->toString())->toBe("          <action application=\"hangup\" data=\"NO_ROUTE_DESTINATION\"/>\n");
    });

    it('renders closeTag with indentation and newline by default', function (): void {
        $writer = new XmlWriter;
        $writer->closeTag('section', 2);

        expect($writer->toString())->toBe("  </section>\n");
    });

    it('renders closeTag without trailing newline when requested', function (): void {
        $writer = new XmlWriter;
        $writer->closeTag('document', 0, false);

        expect($writer->toString())->toBe('</document>');
    });

    it('escapes special XML characters in attributes', function (): void {
        $writer = new XmlWriter;
        $writer->selfClosingTag('param', [
            'name' => 'foo<>&"\'',
            'value' => 'bar<>&"\'',
        ]);

        expect($writer->toString())->toBe("<param name=\"foo&lt;&gt;&amp;&quot;&apos;\" value=\"bar&lt;&gt;&amp;&quot;&apos;\"/>\n");
    });

    it('omits null attribute values', function (): void {
        $writer = new XmlWriter;
        $writer->selfClosingTag('extension', [
            'name' => 'local_extension',
            'continue' => null,
        ]);

        expect($writer->toString())->toBe("<extension name=\"local_extension\"/>\n");
    });

    it('formats boolean attribute values as true or false string', function (): void {
        $writer = new XmlWriter;
        $writer->selfClosingTag('extension', [
            'name' => 'local_extension',
            'continue' => true,
            'inline' => false,
        ]);

        expect($writer->toString())->toBe("<extension name=\"local_extension\" continue=\"true\" inline=\"false\"/>\n");
    });

    it('validates allowed tags correctly with isAllowedTag', function (): void {
        $writer = new XmlWriter;
        $allowed = ['condition', 'action', 'anti-action'];

        expect($writer->isAllowedTag('condition', $allowed))->toBeTrue();
        expect($writer->isAllowedTag('action', $allowed))->toBeTrue();
        expect($writer->isAllowedTag('anti-action', $allowed))->toBeTrue();
        expect($writer->isAllowedTag('script', $allowed))->toBeFalse();
        expect($writer->isAllowedTag('inject', $allowed))->toBeFalse();
    });

    it('appends raw trusted XML fragment untouched', function (): void {
        $writer = new XmlWriter;
        $raw = "      <extension name=\"custom\">\n        <condition/>\n      </extension>\n";
        $writer->rawTrustedFragment($raw);

        expect($writer->toString())->toBe($raw);
    });

    it('renders formatted XML comments with indentation', function (): void {
        $writer = new XmlWriter;
        $writer->comment('Feature module dialplan contributions', 6);

        expect($writer->toString())->toBe("      <!-- Feature module dialplan contributions -->\n");
    });

    it('escapes special characters using escape method', function (): void {
        $writer = new XmlWriter;

        expect($writer->escape('<test & "val\'>'))->toBe('&lt;test &amp; &quot;val&apos;&gt;');
        expect(XmlWriter::escapeXml('<test & "val\'>'))->toBe('&lt;test &amp; &quot;val&apos;&gt;');
    });

    it('appends newline via newline helper', function (): void {
        $writer = new XmlWriter;
        $writer->openTag('root')->newline()->closeTag('root');

        expect($writer->toString())->toBe("<root>\n\n</root>\n");
    });

    it('implements Stringable and supports string casting', function (): void {
        $writer = new XmlWriter;
        $writer->selfClosingTag('item', ['id' => 1]);

        expect((string) $writer)->toBe("<item id=\"1\"/>\n");
    });

    it('chains fluent calls into a complete XML document', function (): void {
        $writer = new XmlWriter;
        $xml = $writer->startDocument()
            ->openTag('document', ['type' => 'freeswitch/xml'])
            ->openTag('section', ['name' => 'dialplan'], 2)
            ->openTag('context', ['name' => 'default'], 4)
            ->openTag('extension', ['name' => 'test'], 6)
            ->openTag('condition', ['field' => 'destination_number', 'expression' => '^100$'], 8)
            ->selfClosingTag('action', ['application' => 'bridge', 'data' => 'user/100'], 10)
            ->closeTag('condition', 8)
            ->closeTag('extension', 6)
            ->closeTag('context', 4)
            ->closeTag('section', 2)
            ->closeTag('document', 0, false)
            ->toString();

        $expected = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<document type="freeswitch/xml">
  <section name="dialplan">
    <context name="default">
      <extension name="test">
        <condition field="destination_number" expression="^100$">
          <action application="bridge" data="user/100"/>
        </condition>
      </extension>
    </context>
  </section>
</document>
XML;

        expect($xml)->toBe($expected);
    });
});
