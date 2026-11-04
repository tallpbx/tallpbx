<?php

declare(strict_types=1);

namespace Tests\Feature\Pbx;

use App\Services\Xml\FsXmlMirrorKeys;
use App\Services\Xml\FsXmlMirrorWriter;
use Illuminate\Support\Facades\Redis;

/*
|--------------------------------------------------------------------------
| Lua Mirror Epoch Agreement
|--------------------------------------------------------------------------
|
| The Lua handler reads one shared epoch key and builds the identity key from
| it. PHP publishes that identity key under its own epoch. If the two ever
| disagree, every Lua lookup misses and the proxy can never serve a hit —
| silently, because the keys look structurally correct.
|
| These checks pin the agreement, and pin that both builders publish the
| epoch through the one method that keeps the shared key in step.
|
*/

describe('lua mirror epoch agreement', function (): void {
    beforeEach(function (): void {
        config(['freeswitch.lua_proxy.enabled' => true]);

        Redis::connection('lua_proxy')->del(FsXmlMirrorKeys::globalVersion());
    });

    afterEach(function (): void {
        Redis::connection('lua_proxy')->del(FsXmlMirrorKeys::globalVersion());
    });

    it('publishes the epoch the Lua handler reads', function (): void {
        $writer = app(FsXmlMirrorWriter::class);

        // A mirror that already holds an older epoch, as a restarted
        // appliance leaves behind.
        $writer->writeGlobalVersion(85);

        $published = $writer->publishEpoch(210);

        expect($published)->toBe(210)
            ->and($writer->readGlobalVersion())->toBe(210);
    });

    it('never lowers the published epoch', function (): void {
        $writer = app(FsXmlMirrorWriter::class);

        $writer->writeGlobalVersion(210);

        // A restarted appliance counts from a lower number. The mirror keeps
        // the higher epoch so previously published keys stay reachable.
        expect($writer->publishEpoch(85))->toBe(210)
            ->and($writer->readGlobalVersion())->toBe(210);
    });

    it('publishes the epoch through one method in both builders', function (): void {
        foreach (['DirectoryXmlBuilder', 'DialplanXmlBuilder'] as $builder) {
            $source = (string) file_get_contents(app_path("Services/Xml/{$builder}.php"));

            expect($source)->toContain('publishEpoch(');
        }
    });

    it('writes nothing to the shared epoch when the mirror is disabled', function (): void {
        config(['freeswitch.lua_proxy.enabled' => false]);

        $writer = app(FsXmlMirrorWriter::class);

        // A disabled mirror touches no key at all, so the Lua handler — which
        // is also disabled in this state — cannot read a stale epoch.
        expect($writer->publishEpoch(210))->toBe(210)
            ->and($writer->readGlobalVersion())->toBeNull();
    });
});
