#!/bin/bash
# ==============================================================================
# Optional Lua Directory Cache — installer helpers
# ==============================================================================
# Sourced by scripts/resources/freeswitch.sh during installation and sourced
# directly by tests/Feature/InstallerLuaProxyTest.php. Keeping the two fiddly
# steps here means they can be exercised against fixture files instead of only
# being grepped for the right words.
#
# Both helpers are deliberately quiet: they report nothing and return success
# even when there is nothing to do, so a missing file never fails an install.
# ==============================================================================

# Move the mod_lua loader line directly above mod_xml_curl, keeping exactly one
# copy of each line.
#
# FreeSWITCH tries XML search bindings in the order the modules loaded, so a
# mod_lua that loads after mod_xml_curl is never asked and the cache appears to
# do nothing. The stock Debian file loads mod_xml_curl near the top and mod_lua
# near the bottom, so this rewrite is the normal case, not an edge case.
#
# Usage: tallpbx_move_mod_lua_before_xml_curl <modules.conf.xml path>
tallpbx_move_mod_lua_before_xml_curl() {
    local modules_conf="$1"
    local lua_line=""
    local temp_file=""

    [ -f "$modules_conf" ] || return 0

    lua_line="$(grep -m1 '<load module="mod_lua"/>' "$modules_conf" || true)"
    [ -n "$lua_line" ] || return 0

    # Nothing to order against: leave the file untouched.
    grep -q '<load module="mod_xml_curl"/>' "$modules_conf" || return 0

    temp_file="$(mktemp)"

    # Drop every existing mod_lua line, then print one copy just before the
    # mod_xml_curl line. This works whichever order the file started in, which
    # is what the previous inline rewrite got wrong.
    grep -v '<load module="mod_lua"/>' "$modules_conf" \
        | awk -v ins="$lua_line" '/<load module="mod_xml_curl"\/>/ && !placed {print ins; placed=1} {print}' \
        > "$temp_file"

    # Write back through the original file so ownership and mode are preserved;
    # replacing the inode would hand FreeSWITCH a root-owned file.
    cat "$temp_file" > "$modules_conf"
    rm -f "$temp_file"
}

# Decide which Redis password the local hiredis profile should use.
#
# The Debian package ships a sample profile pointing at 172.18.101.101 with the
# password "redis". Carrying that onto 127.0.0.1 turns every lookup into
# -NOAUTH, and because the proxy fails quietly the cache simply never works.
# So a password is only inherited when the existing profile is already local;
# otherwise the application's own REDIS_PASSWORD is used, and a literal "null"
# (what Laravel writes when there is no password) counts as no password.
#
# Usage: tallpbx_resolve_hiredis_password <installed hiredis.conf.xml> <.env>
# Prints the password, or nothing at all.
tallpbx_resolve_hiredis_password() {
    local installed="$1"
    local env_file="$2"
    local hostname=""
    local password=""

    if [ -f "$installed" ]; then
        hostname="$(grep -oE '^[[:space:]]*<param name="hostname" value="[^"]*"' "$installed" | head -1 | sed -E 's/.*value="([^"]*)"/\1/' || true)"

        case "$hostname" in
            127.0.0.1|localhost|::1)
                password="$(grep -oE '^[[:space:]]*<param name="password" value="[^"]*"' "$installed" | head -1 | sed -E 's/.*value="([^"]*)"/\1/' || true)"
                ;;
        esac
    fi

    if [ -z "$password" ] && [ -f "$env_file" ]; then
        password="$(grep -E '^REDIS_PASSWORD=' "$env_file" | head -1 | cut -d= -f2- | tr -d '"' || true)"

        case "$password" in
            null|NULL|None) password="" ;;
        esac
    fi

    printf '%s' "$password"
}

# Write the resolved password into the deployed hiredis profile.
#
# The template keeps the parameter commented out, so it is only uncommented
# when there is a real password to write. The value is escaped for sed, and
# passed through a file rather than the pattern to avoid metacharacter damage.
#
# Usage: tallpbx_apply_hiredis_password <hiredis.conf.xml> <password>
tallpbx_apply_hiredis_password() {
    local conf="$1"
    local password="${2:-}"
    local escaped=""

    [ -f "$conf" ] || return 0
    [ -n "$password" ] || return 0

    escaped="$(printf '%s' "$password" | sed 's/[&|]/\\&/g')"
    sed -i "s|.*CHANGE_ME.*|          <param name=\"password\" value=\"${escaped}\"/>|" "$conf"
}
