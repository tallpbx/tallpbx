#!/usr/bin/env bash
#
# Runs the FreeSWITCH Lua XML proxy against the shared JSON fixtures.
#
# The fixture file is the single source of truth: this harness and
# tests/Unit/Services/FsXmlMirrorKeysFixtureTest.php both read it, so a key
# format that changes in only one language fails one of the two tests.
#
# Exit codes: 0 = every case passed, 1 = a case failed, 2 = no Lua interpreter
# is installed (the PHP parity test still runs in that situation).

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
SCRIPT="$ROOT/scripts/freeswitch/tallpbx_xml_handler.lua"
FIXTURES="$ROOT/tests/fixtures/freeswitch/lua_key_fixtures.json"

if [ ! -f "$SCRIPT" ]; then
    echo "FAIL: missing $SCRIPT"
    exit 1
fi

LUA=""
for candidate in lua lua5.4 lua5.1 luajit; do
    if command -v "$candidate" >/dev/null 2>&1; then
        LUA="$candidate"
        break
    fi
done

if [ -z "$LUA" ]; then
    echo "SKIP: no lua interpreter"
    exit 2
fi

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# Convert the JSON fixtures into a Lua table. Keeping one JSON file means the
# PHP key builder and the Lua script are checked against the same strings.
cat > "$WORK/emit_lua.php" <<'PHP'
<?php

declare(strict_types=1);

$json = file_get_contents($argv[1]);
$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

function lua_string(string $value): string
{
    return '"'.strtr($value, [
        '\\' => '\\\\',
        '"' => '\\"',
        "\n" => '\\n',
        "\r" => '\\r',
        "\t" => '\\t',
    ]).'"';
}

function lua_value(mixed $value): string
{
    if ($value === null) {
        return 'nil';
    }

    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }

    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }

    if (is_string($value)) {
        return lua_string($value);
    }

    if (is_array($value)) {
        $isList = array_is_list($value);
        $parts = [];

        foreach ($value as $key => $item) {
            $parts[] = $isList
                ? lua_value($item)
                : '['.lua_string((string) $key).'] = '.lua_value($item);
        }

        return '{'.implode(', ', $parts).'}';
    }

    throw new RuntimeException('Unsupported fixture value type.');
}

echo 'return '.lua_value($data['cases'])."\n";
PHP

php "$WORK/emit_lua.php" "$FIXTURES" > "$WORK/cases.lua"

# Execute the real script once per case with a stubbed FreeSWITCH API, the
# same globals mod_lua provides (XML_REQUEST, params, XML_STRING).
cat > "$WORK/driver.lua" <<'LUA'
local script_path = arg[1]
local cases = dofile(arg[2])

local failures = 0
local executed = 0

local function fail(name, message)
    failures = failures + 1
    io.stderr:write("FAIL " .. name .. ": " .. message .. "\n")
end

local function same_keys(expected, actual)
    if expected == nil then
        return actual == nil
    end

    if actual == nil then
        return false
    end

    return expected.epoch == actual.epoch
        and expected.tenant == actual.tenant
        and expected.version == actual.version
        and expected.payload == actual.payload
end

for _, case in ipairs(cases) do
    XML_STRING = nil
    TALLPBX_DEBUG_KEYS = nil

    local replies = case.redis or {}

    freeswitch = {
        API = function()
            return {
                execute = function(self, command, args)
                    if command ~= "hiredis_raw" then
                        return "-ERR unsupported"
                    end

                    local key = string.match(args, "^default GET (.+)$")

                    if key == nil then
                        return "-ERR unsupported"
                    end

                    local reply = replies[key]

                    if reply == nil then
                        return "-ERR no reply"
                    end

                    return reply
                end,
            }
        end,
    }

    if case.params == nil then
        params = nil
    else
        local headers = case.params

        params = {
            getHeader = function(self, name)
                return headers[name]
            end,
        }
    end

    XML_REQUEST = case.request

    local ok, err = pcall(dofile, script_path)

    if not ok then
        fail(case.name, "script error: " .. tostring(err))
    else
        executed = executed + 1

        if XML_STRING ~= case.expected_xml then
            fail(case.name, "XML_STRING expected " .. tostring(case.expected_xml) .. " got " .. tostring(XML_STRING))
        end

        if not same_keys(case.expected_keys, TALLPBX_DEBUG_KEYS) then
            local got = "nil"

            if TALLPBX_DEBUG_KEYS ~= nil then
                got = tostring(TALLPBX_DEBUG_KEYS.epoch) .. " | "
                    .. tostring(TALLPBX_DEBUG_KEYS.tenant) .. " | "
                    .. tostring(TALLPBX_DEBUG_KEYS.version) .. " | "
                    .. tostring(TALLPBX_DEBUG_KEYS.payload)
            end

            fail(case.name, "debug keys mismatch: " .. got)
        end
    end
end

if failures > 0 then
    io.stderr:write(tostring(failures) .. " failure(s)\n")
    os.exit(1)
end

print("OK " .. tostring(executed) .. " cases")
LUA

"$LUA" "$WORK/driver.lua" "$SCRIPT" "$WORK/cases.lua"
