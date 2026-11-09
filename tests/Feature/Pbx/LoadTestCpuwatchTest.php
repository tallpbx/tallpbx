<?php

declare(strict_types=1);

namespace Tests\Feature\Pbx;

use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| CPU Sampler Classification
|--------------------------------------------------------------------------
|
| cpuwatch.sh attributes CPU and memory to service classes by reading each
| process command line. A service missing from the classifier is invisible
| in every per-service sample a load cell records, which silently understates
| the total and misattributes its share to "idle". These checks pin the
| classification for the services a SIP cell depends on.
|
*/

it('classifies the reverse proxy, PHP-FPM and the switch services', function (): void {
    $script = base_path('load-testing/scripts/tools/cpuwatch.sh');

    // Extract only the classify() function: sourcing the whole script would
    // start its sampling loop, and executing it would sleep for its window.
    $probe = <<<'SH'
eval "$(sed -n '/^classify()/,/^}/p' "$1")"
classify "nginx: master process /usr/sbin/nginx -g daemon on; master_process on;"; echo
classify "nginx: worker process"; echo
classify "php-fpm: master process (/etc/php/8.5/fpm/php-fpm.conf)"; echo
classify "php-fpm: pool www"; echo
classify "/usr/bin/php /var/www/tallpbx/artisan queue:work"; echo
classify "/usr/bin/php /var/www/tallpbx/artisan cdr:work"; echo
classify "/usr/bin/php /var/www/tallpbx/artisan reverb:start"; echo
classify "php /var/www/tallpbx/artisan freeswitch:listen"; echo
classify "/usr/local/freeswitch/bin/freeswitch -nc -nonat"; echo
classify "/usr/sbin/mariadbd"; echo
classify "/usr/bin/redis-server 127.0.0.1:6379"; echo
SH;

    $process = new Process(['bash', '-c', $probe, 'cpuwatch-probe', $script], base_path());
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
    expect($process->getOutput())->toBe(implode("\n", [
        'nginx',
        'nginx',
        'php-fpm',
        'php-fpm',
        'queue-worker',
        'cdr-worker',
        'reverb',
        'esl-listener',
        'freeswitch',
        'mariadb',
        'redis',
    ])."\n");
});
