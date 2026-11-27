#!/bin/bash
# ==============================================================================
# FreeSWITCH Log File Rollover Defaults
# ==============================================================================
# Rewrites logfile.conf.xml so mod_logfile rotates freeswitch.log at 250 MB per
# file and keeps at most 10 log files. Sourced by freeswitch.sh and exercised
# directly by the installer-default tests, so it must not depend on any other
# installer helper.
# ==============================================================================

# Rewrite (or insert) the rollover and maximum-rotate params in a
# logfile.conf.xml. Only active (uncommented) params are rewritten; a
# commented-out default is left untouched and a fresh active param is inserted
# before </settings> instead.
configure_logfile_rollover() {
    local logfile_conf="${1:-}"

    if [ -z "$logfile_conf" ] || [ ! -f "$logfile_conf" ]; then
        return 0
    fi

    _set_logfile_param "$logfile_conf" "rollover" "262144000"
    _set_logfile_param "$logfile_conf" "maximum-rotate" "10"

    # Ensure the logfile map retains debug so temporary runtime diagnosis via
    # 'fsctl loglevel debug' works.
    sed -i 's#<map name="all" value="console,info,notice,warning,err,crit,alert"/>#<map name="all" value="console,debug,info,notice,warning,err,crit,alert"/>#' "$logfile_conf"
}

# Set one active <param name="..." value="..."/> in logfile.conf.xml.
# A param is treated as active only when its line starts with optional
# whitespace and the tag itself, so a commented-out <!--<param .../>--> line
# never satisfies the match.
_set_logfile_param() {
    local logfile_conf="$1"
    local name="$2"
    local value="$3"

    if grep -qE "^[[:space:]]*<param name=\"${name}\"" "$logfile_conf"; then
        sed -i -E "s#^([[:space:]]*<param name=\"${name}\" value=\")[^\"]*(\")#\1${value}\2#" "$logfile_conf"
    else
        sed -i "/<profile name=\"default\">/,/<\/profile>/ s#</settings>#        <param name=\"${name}\" value=\"${value}\"/>\n    </settings>#" "$logfile_conf"
    fi
}
