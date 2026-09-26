<?php

declare(strict_types=1);

/**
 * Set an environment variable in $_ENV, $_SERVER, and getenv() for testing.
 */
function setFsTestEnv(string $key, ?string $value): void
{
    if ($value === null) {
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);
    } else {
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv("{$key}={$value}");
    }
}

it('defines Sofia global defaults for XML configuration rendering', function () {
    expect(config('freeswitch.sofia'))
        ->toHaveKey('log_level', '0')
        ->toHaveKey('auto_restart', true)
        ->toHaveKey('debug_presence', false)
        ->toHaveKey('capture_server', '')
        ->toHaveKey('inbound_reg_in_new_thread', true)
        ->toHaveKey('max_reg_threads', 8);
});

it('binds connection and SIP realm from canonical FS_ environment variables', function () {
    setFsTestEnv('FS_SERVER', 'http://192.168.1.50');
    setFsTestEnv('FS_DEFAULT_SIP_REALM', 'sip.domain.local');
    setFsTestEnv('FS_ESL_HOST', '192.168.1.50');
    setFsTestEnv('FS_ESL_PORT', '9021');
    setFsTestEnv('FS_ESL_PASSWORD', 'SecretEslPass');
    setFsTestEnv('FS_ESL_RECONNECT_INTERVAL', '12');
    setFsTestEnv('FS_ESL_TIMEOUT', '25');

    $config = require config_path('freeswitch.php');

    expect($config['server'])->toBe('http://192.168.1.50')
        ->and($config['default_sip_realm'])->toBe('sip.domain.local')
        ->and($config['esl']['host'])->toBe('192.168.1.50')
        ->and($config['esl']['port'])->toBe(9021)
        ->and($config['esl']['password'])->toBe('SecretEslPass')
        ->and($config['esl']['reconnect_interval'])->toBe(12)
        ->and($config['esl']['timeout'])->toBe(25);

    // Clean up
    setFsTestEnv('FS_SERVER', null);
    setFsTestEnv('FS_DEFAULT_SIP_REALM', null);
    setFsTestEnv('FS_ESL_HOST', null);
    setFsTestEnv('FS_ESL_PORT', null);
    setFsTestEnv('FS_ESL_PASSWORD', null);
    setFsTestEnv('FS_ESL_RECONNECT_INTERVAL', null);
    setFsTestEnv('FS_ESL_TIMEOUT', null);
});

it('binds database and core switch settings from canonical FS_ environment variables', function () {
    setFsTestEnv('FS_LOG_LEVEL', 'notice');
    setFsTestEnv('FS_SESSIONS_PER_SECOND', '120');
    setFsTestEnv('FS_PIN_TRIGGER', '*98');
    setFsTestEnv('FS_HIREDIS_LIMIT_ENABLED', 'true');
    setFsTestEnv('FS_HIREDIS_LIMIT_MAX', '50000');
    setFsTestEnv('FS_HIREDIS_MARKER_ENABLED', 'true');
    setFsTestEnv('FS_DB_DRIVER', 'mariadb');
    setFsTestEnv('FS_DB_HOST', '10.0.0.5');
    setFsTestEnv('FS_DB_PORT', '3307');
    setFsTestEnv('FS_DB_NAME', 'fs_runtime');
    setFsTestEnv('FS_DB_USERNAME', 'fs_user');
    setFsTestEnv('FS_DB_PASSWORD', 'fs_secret');

    $config = require config_path('freeswitch.php');

    expect($config['switch']['loglevel'])->toBe('notice')
        ->and($config['switch']['sessions_per_second'])->toBe(120)
        ->and($config['xml_handler']['pin_trigger'])->toBe('*98')
        ->and($config['xml_handler']['hiredis_limit_enabled'])->toBeTrue()
        ->and($config['xml_handler']['hiredis_limit_max'])->toBe(50000)
        ->and($config['xml_handler']['hiredis_marker_enabled'])->toBeTrue()
        ->and($config['database']['driver'])->toBe('mariadb')
        ->and($config['database']['host'])->toBe('10.0.0.5')
        ->and($config['database']['port'])->toBe(3307)
        ->and($config['database']['database'])->toBe('fs_runtime')
        ->and($config['database']['username'])->toBe('fs_user')
        ->and($config['database']['password'])->toBe('fs_secret');

    // Clean up
    setFsTestEnv('FS_LOG_LEVEL', null);
    setFsTestEnv('FS_SESSIONS_PER_SECOND', null);
    setFsTestEnv('FS_PIN_TRIGGER', null);
    setFsTestEnv('FS_HIREDIS_LIMIT_ENABLED', null);
    setFsTestEnv('FS_HIREDIS_LIMIT_MAX', null);
    setFsTestEnv('FS_HIREDIS_MARKER_ENABLED', null);
    setFsTestEnv('FS_DB_DRIVER', null);
    setFsTestEnv('FS_DB_HOST', null);
    setFsTestEnv('FS_DB_PORT', null);
    setFsTestEnv('FS_DB_NAME', null);
    setFsTestEnv('FS_DB_USERNAME', null);
    setFsTestEnv('FS_DB_PASSWORD', null);
});

it('binds call broadcast runtime settings from FS_CALL_BROADCAST_ environment variables', function () {
    setFsTestEnv('FS_CALL_BROADCAST_MEDIA', 'local_stream://custom');
    setFsTestEnv('FS_CALL_BROADCAST_CALLER_ID', '18005550199');
    setFsTestEnv('FS_CALL_BROADCAST_PACING_SECONDS', '3');
    setFsTestEnv('FS_CALL_BROADCAST_OUTCOME_WINDOW_MINUTES', '30');

    $config = require config_path('call-broadcast.php');

    expect($config['media'])->toBe('local_stream://custom')
        ->and($config['caller_id_number'])->toBe('18005550199')
        ->and($config['pacing_seconds'])->toBe(3)
        ->and($config['outcome_window_minutes'])->toBe(30);

    // Clean up
    setFsTestEnv('FS_CALL_BROADCAST_MEDIA', null);
    setFsTestEnv('FS_CALL_BROADCAST_CALLER_ID', null);
    setFsTestEnv('FS_CALL_BROADCAST_PACING_SECONDS', null);
    setFsTestEnv('FS_CALL_BROADCAST_OUTCOME_WINDOW_MINUTES', null);
});
