-- TallPBX version-aware XML cache proxy: directory domain/user lookups and
-- dialplan lookups.
--
-- Contract: every key string must match App\Services\Xml\FsXmlMirrorKeys
-- byte-for-byte. The shared fixtures in tests/fixtures/freeswitch/ prove the
-- two languages agree.
--
-- The script performs at most four GETs per lookup, contains no XML logic, and
-- returns nil on any doubt so FreeSWITCH falls through to mod_xml_curl, which
-- rebuilds and re-warms the mirror.
--
-- Directory lookups need the identity epoch (a system-wide routing revision
-- that moves when a SIP account or tenant domain changes ownership), the
-- tenant map, the tenant revision, and the payload.
--
-- Dialplan lookups run on every call leg. The tenant is encoded in the context
-- name (tenant_{id}_internal or tenant_{id}_public), so no tenant map is read;
-- only the epoch, the tenant revision, and the payload are fetched. Both
-- revisions are part of the key, so a routing save makes the next lookup miss
-- instead of serving rules that no longer apply.
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

-- Read a revision key. A missing or non-numeric value is a miss, never a
-- default: guessing a revision would defeat the whole invalidation contract.
local function read_revision(api, key)
    local value = redis_get(api, key)

    if not value or not value:match("^%d+$") then
        return nil
    end

    return value
end

-- Return true when a value is safe to interpolate into a hiredis command.
-- A space or control character would split the command into an extra argument
-- and query a different key.
local function safe_token(value)
    return value ~= "" and value:match("^[%w%._%+%-%*#]+$") ~= nil
end

-- Return true when a Redis reply looks like a complete XML document. A
-- partially written or foreign value must never reach FreeSWITCH's parser.
local function plausible_xml(reply)
    return type(reply) == "string" and #reply >= 20 and reply:find("<document", 1, true) ~= nil
end

-- Resolve a directory document, or nil when anything is missing or odd.
local function directory_lookup()
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

    if not safe_token(domain) or not safe_token(user) then
        return nil
    end

    local api = freeswitch.API()

    -- The epoch is read first because the identity map key cannot be built
    -- without it.
    local epoch_key = ns .. ":ver:global"
    local epoch = read_revision(api, epoch_key)

    if not epoch then
        return nil
    end

    local tenant_key = ns .. ":tenant:user:" .. domain .. ":" .. user .. ":e" .. epoch
    local tenant = redis_get(api, tenant_key)

    if not tenant or not tenant:match("^%d+$") then
        return nil
    end

    local version_key = ns .. ":ver:" .. tenant
    local version = read_revision(api, version_key)

    if not version then
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

    if not plausible_xml(reply) then
        return nil
    end

    return reply
end

-- Resolve a dialplan document, or nil when anything is missing or odd.
local function dialplan_lookup()
    local context = XML_REQUEST["Caller-Context"] or header("Caller-Context")
    local destination = XML_REQUEST["Caller-Destination-Number"] or header("Caller-Destination-Number")

    if type(context) ~= "string" or type(destination) ~= "string" then
        return nil
    end

    -- The tenant is only addressable when the context encodes it. PHP mirrors
    -- a dialplan under this same condition, so a context without a tenant
    -- prefix was never written and needs no lookup.
    local tenant = context:match("^tenant_([%w_%-]+)_internal$")
        or context:match("^tenant_([%w_%-]+)_public$")

    if not tenant or not tenant:match("^%d+$") then
        return nil
    end

    if not safe_token(context) or not safe_token(destination) then
        return nil
    end

    local api = freeswitch.API()

    local epoch_key = ns .. ":ver:global"
    local epoch = read_revision(api, epoch_key)

    if not epoch then
        return nil
    end

    local version_key = ns .. ":ver:" .. tenant
    local version = read_revision(api, version_key)

    if not version then
        return nil
    end

    local payload_key = ns .. ":dp:" .. tenant .. ":" .. context .. ":" .. destination
        .. ":v" .. version .. ":e" .. epoch

    TALLPBX_DEBUG_KEYS = {
        epoch = epoch_key,
        version = version_key,
        payload = payload_key,
    }

    local reply = redis_get(api, payload_key)

    if not plausible_xml(reply) then
        return nil
    end

    return reply
end

-- Dispatch on the requested section. Anything else (configuration, phrases,
-- ACL) stays on the PHP path.
local function proxy()
    if not XML_REQUEST then
        return nil
    end

    local section = XML_REQUEST["section"]

    if section == "directory" then
        return directory_lookup()
    end

    if section == "dialplan" then
        return dialplan_lookup()
    end

    return nil
end

-- A Lua fault may cost a cache hit, never a registration or a call.
local ok, result = pcall(proxy)

if ok and type(result) == "string" and result ~= "" then
    XML_STRING = result
else
    XML_STRING = nil
end
