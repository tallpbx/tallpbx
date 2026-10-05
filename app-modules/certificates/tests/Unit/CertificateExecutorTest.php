<?php

declare(strict_types=1);

namespace Modules\Certificates\Tests\Unit;

use Modules\Certificates\Services\CertificateExecutor;

it('blocks privileged system helper execution during unit test runs', function (): void {
    $executor = new CertificateExecutor('/usr/local/sbin/tallpbx-certificate');

    $result = $executor->deployWeb('test_cert');

    expect($result['success'])->toBeTrue()
        ->and($result['output'])->toBe('MOCKED_TEST_OUTPUT')
        ->and($result['exit_code'])->toBe(0);
});

it('executes custom test stub scripts safely', function (): void {
    $tempStub = tempnam(sys_get_temp_dir(), 'cert_stub_');
    file_put_contents($tempStub, "#!/bin/bash\necho \"tallpbx-cert-helper-version: 1\"\n");
    chmod($tempStub, 0755);

    try {
        $executor = new CertificateExecutor($tempStub);
        $version = $executor->version();

        expect($version)->toBe('tallpbx-cert-helper-version: 1');
    } finally {
        @unlink($tempStub);
    }
});

it('parses structured JSON status from stub output', function (): void {
    $tempStub = tempnam(sys_get_temp_dir(), 'cert_stub_status_');
    file_put_contents($tempStub, "#!/bin/bash\necho '{\"active_web\":\"main_pbx\",\"telephony_active\":true}'\n");
    chmod($tempStub, 0755);

    try {
        $executor = new CertificateExecutor($tempStub);
        $status = $executor->status();

        expect($status)->toBe([
            'active_web' => 'main_pbx',
            'telephony_active' => true,
        ]);
    } finally {
        @unlink($tempStub);
    }
});
