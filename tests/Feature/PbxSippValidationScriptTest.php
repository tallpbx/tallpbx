<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('keeps the SIPp end-to-end validation runner syntactically valid', function (): void {
    $script = base_path('scripts/pbx-sipp-validate.sh');
    $process = new Process(['bash', '-n', $script], base_path());
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
});

it('documents the expected SIPp scenarios in the validation runner', function (): void {
    $script = file_get_contents(base_path('scripts/pbx-sipp-validate.sh'));

    expect($script)->toContain('tools/sipp/register.xml')
        ->and($script)->toContain('tools/sipp/uac-extension.xml')
        ->and($script)->toContain('tools/sipp/uac-outbound.xml')
        ->and($script)->toContain('tools/sipp/uas-auto-answer.xml')
        ->and($script)->toContain('tools/sipp/uac-media-client-hangup.xml')
        ->and($script)->toContain('tools/sipp/uac-media-server-hangup.xml')
        ->and($script)->toContain('MEDIA_FLOW')
        ->and($script)->toContain('--include-media-fixtures')
        ->and($script)->toContain('-mi')
        ->and($script)->toContain('-mp')
        ->and($script)->toContain('pbx:load-test:seed')
        ->and($script)->toContain('sipp-users-auth.csv')
        ->and($script)->toContain('trace_counts')
        ->and($script)->toContain('summary.md');
});

it('keeps custom SIPp XML scenarios well formed', function (string $scenario): void {
    $path = base_path("tools/sipp/{$scenario}");
    $document = simplexml_load_file($path);

    expect($document)->not->toBeFalse();
})->with([
    'register.xml',
    'register-uas-auto-answer.xml',
    'uac-call-block.xml',
    'uac-call-forward.xml',
    'uac-conference.xml',
    'uac-emergency.xml',
    'uac-extension-capacity.xml',
    'uac-extension.xml',
    'uac-follow-me.xml',
    'uac-media-client-hangup.xml',
    'uac-media-server-hangup.xml',
    'uac-outbound.xml',
    'uac-ring-group.xml',
    'uac-time-condition.xml',
    'uac-voicemail.xml',
    'uas-auto-answer-capacity.xml',
    'uas-auto-answer.xml',
]);

it('measures end-to-end call setup time in the capacity scenario', function (): void {
    $scenario = (string) file_get_contents(base_path('tools/sipp/uac-extension-capacity.xml'));

    expect($scenario)->toContain('start_rtd="setup"')
        ->and($scenario)->toContain('rtd="setup"')
        ->and($scenario)->toContain('<pause/>');
});

it('keeps the capacity destination duration configurable', function (): void {
    $scenario = (string) file_get_contents(base_path('tools/sipp/uas-auto-answer-capacity.xml'));

    expect($scenario)->toContain('<pause/>')
        ->and($scenario)->toContain('<recv request="BYE"/>');
});
