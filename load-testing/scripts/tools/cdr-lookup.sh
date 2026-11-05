<?php

declare(strict_types=1);

// Show the CDR rows for the outbound calls, so we can see what the call did:
// answered locally, or bridged out to the gateway.

require '/var/www/tallpbx/vendor/autoload.php';

$app = require '/var/www/tallpbx/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== CDR rows for destination 91555123000 ===\n";
$rows = Illuminate\Support\Facades\DB::table('cdrs')
    ->where('destination', '91555123000')
    ->orderBy('id')
    ->get(['id', 'leg', 'destination', 'caller_id', 'duration', 'billsec', 'hangup_cause', 'direction', 'created_at']);

foreach ($rows as $row) {
    printf(
        "leg=%s dest=%s caller=%s dur=%ss bill=%ss cause=%-22s dir=%-8s at=%s\n",
        $row->leg,
        $row->destination,
        $row->caller_id,
        $row->duration,
        $row->billsec,
        (string) $row->hangup_cause,
        (string) $row->direction,
        $row->created_at,
    );
}

echo "\ncount=".$rows->count()."\n";

echo "\n=== the most recent rows of any kind (for contrast) ===\n";
foreach (Illuminate\Support\Facades\DB::table('cdrs')->orderBy('id', 'desc')->limit(6)->get(['leg', 'destination', 'duration', 'hangup_cause', 'created_at']) as $row) {
    printf(
        "leg=%s dest=%-16s dur=%-4s cause=%-22s at=%s\n",
        $row->leg,
        (string) $row->destination,
        (string) $row->duration,
        (string) $row->hangup_cause,
        $row->created_at,
    );
}
