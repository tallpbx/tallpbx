<?php

declare(strict_types=1);

/**
 * Feature tests for the opt-in XML handler timing response headers.
 *
 * The headers are the transport that lets the load-test harness record
 * Laravel-side latency without parsing log files.
 */
describe('xml handler timing headers', function (): void {
    beforeEach(function (): void {
        // Start from a clean, production-default configuration for every case.
        config([
            'freeswitch.xml_handler.auth' => false,
            'freeswitch.xml_handler.log_timing' => false,
            'freeswitch.xml_handler.timing_headers' => false,
        ]);
    });

    test('does not emit timing headers by default', function () {
        $response = $this->get('/api/v1/xml-handler?section=directory&tag_name=user&key_value=1001&domain=example.test');

        expect($response->headers->has('X-TallPBX-Handler-Ms'))->toBeFalse()
            ->and($response->headers->has('X-TallPBX-Request-Ms'))->toBeFalse()
            ->and($response->headers->has('X-TallPBX-DB-Queries'))->toBeFalse();
    });

    test('emits timing headers only when enabled and explicitly requested', function () {
        // The middleware derives request_ms from LARAVEL_START, which the
        // test bootstrap does not define; define it for this case.
        if (! defined('LARAVEL_START')) {
            define('LARAVEL_START', microtime(true));
        }

        config([
            'freeswitch.xml_handler.log_timing' => true,
            'freeswitch.xml_handler.timing_headers' => true,
        ]);

        $response = $this->withHeaders(['X-TallPBX-Timing' => '1'])
            ->get('/api/v1/xml-handler?section=directory&tag_name=user&key_value=1001&domain=example.test');

        expect(is_numeric($response->headers->get('X-TallPBX-Handler-Ms')))->toBeTrue()
            ->and(is_numeric($response->headers->get('X-TallPBX-Request-Ms')))->toBeTrue()
            ->and(is_numeric($response->headers->get('X-TallPBX-DB-Queries')))->toBeTrue();
    });

    test('does not emit headers when the caller omits the request header', function () {
        config(['freeswitch.xml_handler.timing_headers' => true]);

        $response = $this->get('/api/v1/xml-handler?section=directory&tag_name=user&key_value=1001&domain=example.test');

        expect($response->headers->has('X-TallPBX-Handler-Ms'))->toBeFalse()
            ->and($response->headers->has('X-TallPBX-Request-Ms'))->toBeFalse();
    });
});
