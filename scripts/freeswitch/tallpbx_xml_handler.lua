-- TallPBX version-aware directory XML cache proxy (phase 1: directory domain
-- and user lookups).
--
-- Contract: every key string must match App\Services\Xml\FsXmlMirrorKeys
-- byte-for-byte. The shared fixtures in tests/fixtures/freeswitch/ prove the
-- two languages agree.
--
-- The script performs at most four GETs (identity epoch, tenant map,
-- revision, payload), contains no XML logic, and returns nil on any doubt so
-- FreeSWITCH falls through to mod_xml_curl, which rebuilds and re-warms the
-- mirror.
--
-- The identity epoch is the system-wide routing revision. It moves whenever a
-- SIP account or tenant domain changes ownership, so an answer written before
-- the change can never be read under the new epoch.
--
-- mod_lua supplies XML_REQUEST (section, tag_name, key_name, key_value) and
-- params (the request event). TALLPBX_DEBUG_KEYS is inert in production and
-- exists only so the shell harness can assert which keys were queried.

local ns = "pbx:fsxml"

-- Read one request header from the FreeSWITCH event, tolerating a missing
-- params object and non-string values.
local function header(name)
    if not params then
        return ""
    end

    local value = params:getHeader(name)

    if type(value) ~= "string" then
        return ""
    end

    return value
end

-- Run one hiredis_raw GET and return nil for a miss, an auth error, or any
-- other error reply. hiredis reports a missing key as "-ERR no reply".
local function redis_get(api, key)
    local reply = api:execute("hiredis_raw", "default GET " .. key)

    if type(reply) ~= "string" or reply == "" then
        return nil
    end

    if reply:sub(1, 1) == "-" then
        return nil
    end

    return reply
end

-- Resolve the mirrored document, or nil when anything is missing or odd.
local function proxy()
    if not XML_REQUEST or XML_REQUEST["section"] ~= "directory" then
        return nil
    end

    local tag_name = XML_REQUEST["tag_name"] or ""

    if tag_name ~= "domain" and tag_name ~= "user" then
        return nil
    end

    local key_value = XML_REQUEST["key_value"] or ""

    -- Match the PHP controller's identity inputs: sip_auth_username first,
    -- then the plain user header, and the domain header for the realm.
    local user = header("sip_auth_username")

    if user == "" then
        user = header("user")
    end

    local domain = header("domain")

    if domain == "" then
        domain = header("domain_name")
    end

    if domain == "" then
        domain = header("variable_domain_name")
    end

    if user == "" or domain == "" then
        return nil
    end

    -- Only the two shapes PHP mirrors are eligible. A key_value that does not
    -- match its tag scope was never mirrored, so asking Redis is pointless.
    if tag_name == "domain" then
        if key_value ~= domain then
            return nil
        end
    else
        if key_value ~= user then
            return nil
        end
    end

    -- Strict charset: a space or control character would split the hiredis
    -- command into an extra argument and query a different key.
    if not domain:match("^[%w%.%-_]+$") or not user:match("^[%w%.%-_]+$") then
        return nil
    end

    local api = freeswitch.API()

    -- The epoch is read first because the identity map key cannot be built
    -- without it. A missing or non-numeric value is a miss, never a default:
    -- guessing an epoch would defeat the whole invalidation contract.
    local epoch_key = ns .. ":ver:global"
    local epoch = redis_get(api, epoch_key)

    if not epoch or not epoch:match("^%d+$") then
        return nil
    end

    local tenant_key = ns .. ":tenant:user:" .. domain .. ":" .. user .. ":e" .. epoch
    local tenant = redis_get(api, tenant_key)

    if not tenant or not tenant:match("^%d+$") then
        return nil
    end

    local version_key = ns .. ":ver:" .. tenant
    local version = redis_get(api, version_key)

    if not version or not version:match("^%d+$") then
        return nil
    end

    local payload_key = ns .. ":dir:" .. tenant .. ":" .. tag_name .. ":" .. domain .. ":" .. user .. ":v" .. version

    TALLPBX_DEBUG_KEYS = {
        epoch = epoch_key,
        tenant = tenant_key,
        version = version_key,
        payload = payload_key,
    }

    local reply = redis_get(api, payload_key)

    if not reply then
        return nil
    end

    -- A partially written or foreign value must never reach FreeSWITCH's XML
    -- parser: require a plausible length and the document root marker.
    if #reply < 20 or not reply:find("<document", 1, true) then
        return nil
    end

    return reply
end

-- A Lua fault may cost a cache hit, never a registration.
local ok, result = pcall(proxy)

if ok and type(result) == "string" and result ~= "" then
    XML_STRING = result
else
    XML_STRING = nil
end
