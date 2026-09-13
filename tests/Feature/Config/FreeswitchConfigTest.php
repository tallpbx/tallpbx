<?php

declare(strict_types=1);

it('defines Sofia global defaults for XML configuration rendering', function () {
    expect(config('freeswitch.sofia'))
        ->toHaveKey('log_level', '0')
        ->toHaveKey('auto_restart', true)
        ->toHaveKey('debug_presence', false)
        ->toHaveKey('capture_server', '')
        ->toHaveKey('inbound_reg_in_new_thread', true)
        ->toHaveKey('max_reg_threads', 8);
});
