<?php

declare(strict_types=1);

use App\Support\Concerns\EscapesXml;

it('escapes XML special characters', function (): void {
    $host = new class
    {
        use EscapesXml;

        public function escape(string $value): string
        {
            return $this->escapeXml($value);
        }
    };

    expect($host->escape('<name & "quoted">'))->toBe('&lt;name &amp; &quot;quoted&quot;&gt;');
});
