#!/bin/bash
# ==============================================================================
# FreeSWITCH VoIP Engine
# ==============================================================================
# This step installs FreeSWITCH, the phone system engine. There are two ways
# to install it:
#
#   1) From the FreeSWITCH source code (builds from GitHub)
#   2) From SignalWire's apt packages (easier, recommended)
#
# The main installer selects the method and exports it as
# FSPBX_FREESWITCH_INSTALL_METHOD. The config settings remain a manual fallback.
#
# Safe to re-run: already-installed packages are skipped by apt.
# ==============================================================================

cd "$(dirname "$0")"

. ./config.sh
. ./colors.sh
. ./environment.sh

# Find the active FreeSWITCH configuration directory for either package or
# source installations, falling back to the package location on fresh systems.
freeswitch_conf_dir() {
    if [ -d /etc/freeswitch/autoload_configs ]; then
        echo "/etc/freeswitch"
        return 0
    fi

    if [ -d /usr/local/freeswitch/conf/autoload_configs ]; then
        echo "/usr/local/freeswitch/conf"
        return 0
    fi

    echo "/etc/freeswitch"
}

# Make one FreeSWITCH module load at startup without duplicating its XML line.
ensure_freeswitch_module_enabled() {
    local modules_conf="$1"
    local module="$2"
    local section_marker="${3:-}"

    if [ ! -f "$modules_conf" ]; then
        warning "FreeSWITCH modules.conf.xml not found; skipping ${module} enablement"
        return 0
    fi

    sed -i "s#<!--[[:space:]]*<load module=\"${module}\"/>[[:space:]]*-->#<load module=\"${module}\"/>#" "$modules_conf"

    if grep -q "<load module=\"${module}\"/>" "$modules_conf"; then
        verbose "${module} is enabled"
        return 0
    fi

    if [ -n "$section_marker" ] && grep -q "$section_marker" "$modules_conf"; then
        sed -i "/${section_marker}/a\\    <load module=\"${module}\"/>" "$modules_conf"
    else
        sed -i "/<\\/modules>/i\\    <load module=\"${module}\"/>" "$modules_conf"
    fi

    verbose "${module} enabled"
}

# Comment out one legacy module so it cannot start on the next FreeSWITCH boot.
disable_freeswitch_module() {
    local modules_conf="$1"
    local module="$2"

    if [ ! -f "$modules_conf" ]; then
        warning "FreeSWITCH modules.conf.xml not found; skipping ${module} disablement"
        return 0
    fi

    if grep -q "<load module=\"${module}\"/>" "$modules_conf"; then
        sed -i "s#^[[:space:]]*<load module=\"${module}\"/>#    <!--<load module=\"${module}\"/>-->#" "$modules_conf"
        verbose "${module} disabled"
        return 0
    fi

    verbose "${module} is not enabled"
}

# Reload runtime modules when the command-line controller is available. Each
# command tolerates an already loaded or unavailable module during re-runs.
load_required_freeswitch_modules() {
    if ! command -v fs_cli >/dev/null 2>&1; then
        return 0
    fi

    fs_cli -x 'reloadxml' >/dev/null 2>&1 || true
    fs_cli -x 'unload mod_redis' >/dev/null 2>&1 || true
    fs_cli -x 'unload mod_memcache' >/dev/null 2>&1 || true
    fs_cli -x 'load mod_hiredis' >/dev/null 2>&1 || true
    fs_cli -x 'load mod_xml_curl' >/dev/null 2>&1 || true
    fs_cli -x 'load mod_sofia' >/dev/null 2>&1 || true
    fs_cli -x 'load mod_voicemail' >/dev/null 2>&1 || true
    fs_cli -x 'reload mod_callcenter' >/dev/null 2>&1 || fs_cli -x 'load mod_callcenter' >/dev/null 2>&1 || true
    fs_cli -x 'load mod_local_stream' >/dev/null 2>&1 || true
}

# Set the safe call-session rate used by a new TallPBX server and apply it to a
# running FreeSWITCH process when possible.
configure_freeswitch_switch_defaults() {
    local conf_dir=""
    local switch_conf=""
    local sessions_per_second="60"

    conf_dir="$(freeswitch_conf_dir)"
    switch_conf="${conf_dir}/autoload_configs/switch.conf.xml"

    if [ ! -f "$switch_conf" ]; then
        warning "FreeSWITCH switch.conf.xml not found; skipping core switch defaults"
        return 0
    fi

    if grep -q 'name="sessions-per-second"' "$switch_conf"; then
        sed -i 's#<param name="sessions-per-second" value="[^"]*"/>#<param name="sessions-per-second" value="60"/>#' "$switch_conf"
    else
        sed -i "/<settings>/a\\    <param name=\"sessions-per-second\" value=\"${sessions_per_second}\"/>" "$switch_conf"
    fi

    if command -v fs_cli >/dev/null 2>&1; then
        fs_cli -x "fsctl sps ${sessions_per_second}" >/dev/null 2>&1 || true
    fi

    verbose "FreeSWITCH sessions-per-second set to ${sessions_per_second}"
}

# Set the default FreeSWITCH sound prompt language in vars.xml.
configure_freeswitch_sound_defaults() {
    local conf_dir=""
    local vars_xml=""
    local default_lang="${FSPBX_DEFAULT_SOUND_LANGUAGE:-$(get_env_value "$INSTALLER_STATE_FILE" FSPBX_DEFAULT_SOUND_LANGUAGE)}"
    default_lang="${default_lang:-en}"

    conf_dir="$(freeswitch_conf_dir)"
    vars_xml="${conf_dir}/vars.xml"

    if [ ! -f "$vars_xml" ]; then
        return 0
    fi

    local dialect="us"
    local voice="callie"
    local sound_path="en/us/callie"

    case "$default_lang" in
        es)
            dialect="ar"
            voice="mario"
            sound_path="es/ar/mario"
            ;;
        fr)
            dialect="ca"
            voice="june"
            sound_path="fr/ca/june"
            ;;
        *)
            dialect="us"
            voice="callie"
            sound_path="en/us/callie"
            ;;
    esac

    verbose "Setting default FreeSWITCH sound prompt language to $default_lang ($voice)"

    sed -i 's#<X-PRE-PROCESS cmd="set" data="default_language=[^"]*"/>#<X-PRE-PROCESS cmd="set" data="default_language='"$default_lang"'"/>#' "$vars_xml" 2>/dev/null || true
    sed -i 's#<X-PRE-PROCESS cmd="set" data="default_dialect=[^"]*"/>#<X-PRE-PROCESS cmd="set" data="default_dialect='"$dialect"'"/>#' "$vars_xml" 2>/dev/null || true
    sed -i 's#<X-PRE-PROCESS cmd="set" data="default_voice=[^"]*"/>#<X-PRE-PROCESS cmd="set" data="default_voice='"$voice"'"/>#' "$vars_xml" 2>/dev/null || true
    sed -i 's#<X-PRE-PROCESS cmd="set" data="sound_prefix=[^"]*"/>#<X-PRE-PROCESS cmd="set" data="sound_prefix=$${sounds_dir}/'"$sound_path"'"/>#' "$vars_xml" 2>/dev/null || true
}

# Install the FreeSWITCH hiredis module, including a small compatibility repair
# for package versions whose required hiredis library is no longer in apt.
install_freeswitch_hiredis_package() {
    local tmp_dir=""
    local freeswitch_version=""
    local hiredis_package=""
    local compatibility_package=""
    local package_architecture=""

    if apt_get_optional_with_lock_wait install -y freeswitch-mod-hiredis; then
        return 0
    fi

    warning "freeswitch-mod-hiredis could not be installed directly; trying the Debian trixie hiredis compatibility path"

    apt_get_with_lock_wait install -y libhiredis1.1.0
    package_architecture="$(dpkg --print-architecture)"

    freeswitch_version="$(dpkg-query -W -f='${Version}' freeswitch 2>/dev/null || true)"
    if [ -z "$freeswitch_version" ]; then
        freeswitch_version="$(apt-cache policy freeswitch | awk '/Candidate:/ {print $2}')"
    fi

    if [ -z "$freeswitch_version" ] || [ "$freeswitch_version" = "(none)" ]; then
        error "Unable to determine the installed FreeSWITCH version for mod_hiredis"
        exit 1
    fi

    tmp_dir="$(mktemp -d)"
    (
        set -e
        cd "$tmp_dir"
        apt_get_with_lock_wait download "freeswitch-mod-hiredis=${freeswitch_version}"
        hiredis_package="$(find "$tmp_dir" -maxdepth 1 -name 'freeswitch-mod-hiredis_*.deb' -print -quit)"

        if [ -z "$hiredis_package" ]; then
            error "Unable to download freeswitch-mod-hiredis ${freeswitch_version}"
            exit 1
        fi

        dpkg --ignore-depends=libhiredis0.10,libhiredis0.13,libhiredis0.14 -i "$hiredis_package"

        mkdir -p "$tmp_dir/libhiredis0.14/DEBIAN"
        cat > "$tmp_dir/libhiredis0.14/DEBIAN/control" <<DEB_CONTROL
Package: libhiredis0.14
Version: 1.2.0-6+b3+pbx1
Section: libs
Priority: optional
Architecture: ${package_architecture}
Depends: libhiredis1.1.0
Maintainer: FreeSwitchPBX Installer <support@localhost>
Description: Local compatibility package for FreeSWITCH mod_hiredis on Debian trixie
 SignalWire's trixie freeswitch-mod-hiredis package can still declare older
 libhiredis0.x alternatives even though the module binary links to Debian's
 libhiredis1.1.0 package. This local package satisfies that stale dependency
 while keeping the real shared library supplied by libhiredis1.1.0.
DEB_CONTROL
        chmod 0755 "$tmp_dir/libhiredis0.14/DEBIAN"
        compatibility_package="$tmp_dir/libhiredis0.14_1.2.0-6+b3+pbx1_${package_architecture}.deb"
        dpkg-deb --build "$tmp_dir/libhiredis0.14" "$compatibility_package"
        dpkg -i "$compatibility_package"
    ) || {
        rm -rf "$tmp_dir"
        error "Unable to install freeswitch-mod-hiredis with the Debian trixie compatibility path"
        exit 1
    }

    rm -rf "$tmp_dir"
    apt_get_with_lock_wait check || {
        error "Package dependency check failed after installing freeswitch-mod-hiredis"
        exit 1
    }
}

# Tell systemd to start FreeSWITCH after the local web and data services it
# needs for TallPBX's dynamic XML configuration.
configure_freeswitch_systemd_dependencies() {
    local drop_in_dir="/etc/systemd/system/freeswitch.service.d"
    local drop_in_file="${drop_in_dir}/10-tallpbx-dependencies.conf"

    if ! command -v systemctl >/dev/null 2>&1; then
        return 0
    fi

    verbose "Configuring FreeSWITCH startup dependencies"
    mkdir -p "$drop_in_dir"
    cat > "$drop_in_file" <<'SYSTEMD'
[Unit]
# TallPBX serves FreeSWITCH XML through Laravel, so FreeSWITCH should wait until
# the local web, PHP, database, and cache services are started during boot.
Wants=network-online.target nginx.service php8.5-fpm.service mariadb.service redis-server.service
After=network-online.target nginx.service php8.5-fpm.service mariadb.service redis-server.service

[Service]
# FreeSWITCH creates voicemail and recording subdirectories itself; the web
# application must be able to move those files out during archival, so keep
# group-write on everything FreeSWITCH creates (dirs 2775, files 0664) via the
# shared tallpbx-media group instead of the 0750/0644 default umask.
UMask=0002
SYSTEMD

    chmod 0644 "$drop_in_file"
}

# Create the media directories with a shared group so the web application and
# FreeSWITCH can store recordings without making deployed source writable.
configure_tallpbx_media_root() {
    local media_root="$TALLPBX_MEDIA_ROOT"

    if [ -z "$media_root" ]; then
        media_root="/var/lib/tallpbx/media"
    fi

    getent group tallpbx-media >/dev/null 2>&1 || groupadd --system tallpbx-media

    for service_user in www-data freeswitch; do
        if getent passwd "$service_user" >/dev/null 2>&1; then
            usermod -aG tallpbx-media "$service_user"
        fi
    done

    # FreeSWITCH drops supplementary groups when it switches user at startup,
    # so the shared media group must be its primary runtime group. The unit
    # loads this file after its own defaults (EnvironmentFile=-/etc/default/freeswitch),
    # so these values win without editing the packaged unit.
    printf 'USER=freeswitch\nGROUP=tallpbx-media\n' > /etc/default/freeswitch

    # Create the top-level roots and core shared subdirectories with setgid mode
    # (2775) and owned by www-data:tallpbx-media so both the web application
    # and FreeSWITCH can create per-tenant runtime and spool subdirectories.
    install -d -m 2775 -o www-data -g tallpbx-media \
        "$media_root" \
        "$media_root/store" \
        "$media_root/store/runtime" \
        "$media_root/store/archive" \
        "$media_root/spool"

    # Ensure the parent directory allows directory traversal by the service users.
    chmod 755 "$(dirname "$media_root")" 2>/dev/null || true

    # Reconcile ownership and directory permissions across existing media directories
    # on upgrade or re-runs so root-created directories never lock out www-data.
    chown -R www-data:tallpbx-media "$media_root" 2>/dev/null || true
    find "$media_root" -type d -exec chmod 2775 {} + 2>/dev/null || true
}

# Write FreeSWITCH's XML-curl connection so dialplans and directory data come
# from TallPBX instead of static XML files on disk.
configure_dynamic_xml() {
    local app_dir="/var/www/tallpbx"
    local env_file="${app_dir}/.env"
    local conf_dir=""
    local modules_conf=""
    local xml_curl_conf=""
    local app_url=""
    local xml_path="/api/v1/xml-handler"
    local xml_token=""
    local gateway_url=""

    conf_dir="$(freeswitch_conf_dir)"
    modules_conf="${conf_dir}/autoload_configs/modules.conf.xml"
    xml_curl_conf="${conf_dir}/autoload_configs/xml_curl.conf.xml"

    verbose "Enabling required FreeSWITCH modules"
    ensure_freeswitch_module_enabled "$modules_conf" "mod_xml_curl" "<!-- XML Interfaces -->"
    ensure_freeswitch_module_enabled "$modules_conf" "mod_sofia" "<!-- Endpoints -->"
    ensure_freeswitch_module_enabled "$modules_conf" "mod_callcenter" "<!-- Applications -->"
    ensure_freeswitch_module_enabled "$modules_conf" "mod_dptools" "<!-- Applications -->"
    ensure_freeswitch_module_enabled "$modules_conf" "mod_voicemail" "<!-- Applications -->"
    ensure_freeswitch_module_enabled "$modules_conf" "mod_hiredis" "<!-- Applications -->"
    ensure_freeswitch_module_enabled "$modules_conf" "mod_local_stream" "<!-- File Format Interfaces -->"
    ensure_freeswitch_module_enabled "$modules_conf" "mod_sndfile" "<!-- File Format Interfaces -->"
    disable_freeswitch_module "$modules_conf" "mod_redis"
    disable_freeswitch_module "$modules_conf" "mod_memcache"

    # The packaged stock directory (default.xml and default/1000-1019.xml)
    # shadows the app-managed directory: FreeSWITCH resolves local users first,
    # so REGISTER auth for those usernames would fall back to stock passwords.
    # Move the tree aside so every lookup goes through mod_xml_curl. The .stock
    # guard keeps re-runs idempotent and preserves the original for recovery.
    if [ -d "$conf_dir/directory" ] && [ ! -e "$conf_dir/directory.stock" ]; then
        mv "$conf_dir/directory" "$conf_dir/directory.stock"
    fi

    if [ ! -f "$env_file" ]; then
        warning "Laravel .env not found; xml_curl.conf.xml will be configured after the TALL step"
        load_required_freeswitch_modules
        return 0
    fi

    app_url="$(grep -E '^APP_URL=' "$env_file" | tail -1 | cut -d= -f2- | sed 's/^"//; s/"$//')"
    xml_path="$(grep -E '^FREESWITCH_XML_HANDLER_PATH=' "$env_file" | tail -1 | cut -d= -f2- | sed 's/^"//; s/"$//')"
    xml_token="$(grep -E '^FREESWITCH_XML_HANDLER_TOKEN=' "$env_file" | tail -1 | cut -d= -f2- | sed 's/^"//; s/"$//')"

    if [ -z "$app_url" ]; then
        app_url="http://127.0.0.1"
    fi

    if [ -z "$xml_path" ]; then
        xml_path="/api/v1/xml-handler"
    fi

    if [ -z "$xml_token" ]; then
        warning "FREESWITCH_XML_HANDLER_TOKEN is empty; skipping xml_curl.conf.xml generation"
        load_required_freeswitch_modules
        return 0
    fi

    gateway_url="${app_url%/}${xml_path}?token=${xml_token}"

    verbose "Configuring FreeSWITCH mod_xml_curl"
    mkdir -p "${conf_dir}/autoload_configs"
    cat > "$xml_curl_conf" <<XML
<configuration name="xml_curl.conf" description="cURL XML Gateway">
  <bindings>
    <binding name="tallpbx">
      <param name="gateway-url" value="${gateway_url}" bindings="directory|dialplan|configuration"/>
      <param name="timeout" value="5"/>
    </binding>
  </bindings>
</configuration>
XML

    chown freeswitch:freeswitch "$modules_conf" "$xml_curl_conf" 2>/dev/null || true
    chmod 0640 "$xml_curl_conf" 2>/dev/null || true

    load_required_freeswitch_modules
}

if [ "${1:-}" = "--configure-only" ]; then
    configure_tallpbx_media_root
    configure_freeswitch_systemd_dependencies
    configure_freeswitch_switch_defaults
    configure_freeswitch_sound_defaults
    configure_dynamic_xml
    systemctl daemon-reload 2>/dev/null || true
    exit 0
fi

# Stop and remove the package-based FreeSWITCH installation before switching to
# a source build. This intentionally removes only FreeSWITCH program packages.
uninstall_freeswitch_packages() {
    systemctl stop freeswitch 2>/dev/null || true
    apt_get_with_lock_wait remove -y freeswitch-meta-vanilla freeswitch freeswitch-mod-av \
        freeswitch-mod-callcenter freeswitch-mod-dptools freeswitch-mod-hiredis \
        freeswitch-mod-local-stream freeswitch-mod-opus freeswitch-mod-png \
        freeswitch-mod-rtc freeswitch-mod-sndfile freeswitch-mod-sofia \
        freeswitch-mod-signalwire freeswitch-mod-verto freeswitch-mod-xml-curl \
        freeswitch-sounds-en-us-callie freeswitch-sounds-es-ar-mario freeswitch-sounds-fr-ca-june \
        freeswitch-mod-say-es freeswitch-mod-say-fr 2>/dev/null || true
    rm -f /etc/apt/auth.conf.d/freeswitch.conf /etc/apt/sources.list.d/freeswitch.list \
        /usr/share/keyrings/signalwire-freeswitch-repo.gpg
}

# Stop and remove the source-based FreeSWITCH files before switching to packages.
uninstall_freeswitch_source() {
    local source_directory="/usr/src/freeswitch"

    systemctl stop freeswitch 2>/dev/null || true
    if [ -f "${source_directory}/Makefile" ]; then
        make -C "$source_directory" uninstall || true
    fi
    rm -rf "$source_directory"
    rm -f /lib/systemd/system/freeswitch.service
}

# Report whether this server has the source-build files needed for a rebuild.
source_build_is_installed() {
    [ -x /usr/bin/freeswitch ] && [ -f /usr/src/freeswitch/Makefile ]
}

# Report whether apt currently owns the FreeSWITCH server package.
freeswitch_packages_are_installed() {
    dpkg-query -W -f='${db:Status-Status}' freeswitch 2>/dev/null | grep -Fxq installed
}

# Install the compiler, libraries, and headers required to build FreeSWITCH.
install_freeswitch_source_dependencies() {
    apt_get_with_lock_wait update
    apt_get_with_lock_wait install -y autoconf automake build-essential cmake curl flac g++ git \
        libavformat-dev libswscale-dev libcurl4-openssl-dev libedit-dev libgdbm-dev libjpeg-dev \
        libldns-dev liblua5.2-dev libmemcached-dev libmp3lame-dev libmpg123-dev \
        libncurses-dev libopus-dev libpcre2-dev libperl-dev libpq-dev libshout3-dev \
        libsndfile1-dev libspeex-dev libspeexdsp-dev libsqlite3-dev libssl-dev \
        libtiff-dev libtool libtool-bin libuv1-dev libvpx-dev libhiredis-dev make \
        nasm pkg-config sox swig uuid-dev wget yasm
}

# Build the few telephony libraries that must come from source before FreeSWITCH.
build_freeswitch_source_dependencies() {
    local build_jobs

    build_jobs="$(getconf _NPROCESSORS_ONLN)"

    # A previous interrupted install can leave the clone behind without
    # installing LibKS. Check the installed library instead of treating the
    # source directory as proof of a successful build.
    if ! pkg-config --exists 'libks2 >= 2.0.11'; then
        rm -rf /usr/src/libks
        git clone https://github.com/signalwire/libks.git /usr/src/libks
        (
            cd /usr/src/libks
            # LibKS runs its Git tag lookup from the current directory. Build
            # in source as FusionPBX does so that lookup runs in the clone.
            cmake .
            make -j "$build_jobs"
            make install
        )
    fi

    export C_INCLUDE_PATH="/usr/include/libks${C_INCLUDE_PATH:+:${C_INCLUDE_PATH}}"

    if [ ! -d /usr/src/sofia-sip ]; then
        git clone https://github.com/freeswitch/sofia-sip.git /usr/src/sofia-sip
        (
            cd /usr/src/sofia-sip
            sh autogen.sh
            ./configure --enable-debug
            make -j "$build_jobs"
            make install
        )
    fi

    if [ ! -d /usr/src/spandsp ]; then
        git clone https://github.com/freeswitch/spandsp.git /usr/src/spandsp
        (
            cd /usr/src/spandsp
            sh autogen.sh
            ./configure --enable-debug
            make -j "$build_jobs"
            make install
        )
    fi

    ldconfig
}

# Turn off source-build modules TallPBX does not use so they cannot add legacy
# Redis or Memcache behavior to the running PBX.
disable_tallpbx_source_modules() {
    local modules_file="$1"
    local module

    # These optional modules require LibKS. TallPBX does not use them, and
    # building LibKS from an unpinned upstream checkout makes no-token source
    # installs fragile. This follows FusionPBX's source-build policy.
    for module in endpoints/mod_verto applications/mod_signalwire; do
        sed -i "s|^${module}|#${module}|" "$modules_file"
    done
}

# Turn on source-build modules required for SIP, media, queues, voicemail, and
# TallPBX's XML-curl and hiredis integration.
enable_tallpbx_source_modules() {
    local modules_file="$1"
    local module

    # Keep source builds functionally aligned with the package profile. These
    # entries are intentionally the FreeSWITCH source-tree module paths.
    for module in applications/mod_callcenter applications/mod_dptools applications/mod_voicemail \
        applications/mod_hiredis endpoints/mod_sofia formats/mod_av \
        formats/mod_local_stream formats/mod_sndfile xml_int/mod_xml_curl \
        say/mod_say_es say/mod_say_fr; do
        sed -i "s|^#${module}|${module}|" "$modules_file"
    done
}

# Create the systemd unit and writable runtime directories for a source build.
install_freeswitch_source_service() {
    getent group freeswitch >/dev/null 2>&1 || groupadd --system freeswitch
    getent passwd freeswitch >/dev/null 2>&1 || useradd --system --gid freeswitch \
        --home-dir /var/lib/freeswitch --shell /usr/sbin/nologin freeswitch
    # make install creates these state directories as root. FreeSWITCH runs as
    # the dedicated user, so it must own its SQLite, recording, and cache paths.
    install -d -o freeswitch -g freeswitch \
        /var/lib/freeswitch/db \
        /var/lib/freeswitch/recordings \
        /var/lib/freeswitch/storage/voicemail \
        /var/log/freeswitch \
        /var/cache/freeswitch \
        /run/freeswitch
    chown -R freeswitch:freeswitch /var/lib/freeswitch /var/log/freeswitch /var/cache/freeswitch

    cat > /lib/systemd/system/freeswitch.service <<'SYSTEMD'
[Unit]
Description=FreeSWITCH
After=network.target local-fs.target

[Service]
Type=forking
User=freeswitch
Group=freeswitch
RuntimeDirectory=freeswitch
ExecStart=/usr/bin/freeswitch -ncwait
ExecStop=/usr/bin/fs_cli -x 'fsctl shutdown'
Restart=on-failure
LimitCORE=infinity

[Install]
WantedBy=multi-user.target
SYSTEMD
}

freeswitch_install_method="${FSPBX_FREESWITCH_INSTALL_METHOD:-}"
previous_freeswitch_install_method="${FSPBX_PREVIOUS_FREESWITCH_INSTALL_METHOD:-}"
INSTALLER_STATE_FILE="${PBX_INSTALLER_STATE_FILE:-/etc/pbx/installer.env}"
if [ -z "$freeswitch_install_method" ]; then
    if [ "$switch_source" = "true" ]; then
        freeswitch_install_method=source
    elif [ "$switch_package" = "true" ]; then
        freeswitch_install_method=packages
    fi
fi

# ------------------------------------------------------------------
# Option 1: Build from source code
# ------------------------------------------------------------------
# This downloads the FreeSWITCH source from GitHub and compiles it.
# It takes longer but gives you more control. No SignalWire token
# is needed for this method.
if [ "$freeswitch_install_method" = "source" ]; then
    # Never create a source service after a failed configure, compile, or
    # install. The package branch retains its existing error handling.
    set -e

    if [ "$previous_freeswitch_install_method" = "packages" ] || freeswitch_packages_are_installed; then
        verbose "Removing the existing FreeSWITCH package installation before building from source"
        uninstall_freeswitch_packages
    fi

    # The main installer collects this answer during preflight. Keep the
    # interactive fallback for administrators who run this resource directly.
    recompile_source="${FSPBX_RECOMPILE_SOURCE:-true}"
    if [ -z "${FSPBX_RECOMPILE_SOURCE:-}" ] && source_build_is_installed; then
        if [ "${FSPBX_INSTALLER_EXECUTION:-false}" = true ]; then
            error "The main installer did not collect the source rebuild choice. Re-run the installer."
            exit 1
        elif [ -t 0 ]; then
            read -rp 'Recompile FreeSWITCH from source? [y/N] ' recompile_choice
            case "${recompile_choice,,}" in
                y|yes) ;;
                *) recompile_source=false ;;
            esac
        fi
    fi

    if [ "$recompile_source" = false ]; then
        verbose "Keeping the existing FreeSWITCH source build"
    else
    verbose "Installing FreeSWITCH build dependencies"
    install_freeswitch_source_dependencies
    build_freeswitch_source_dependencies

    verbose "Cloning FreeSWITCH source ($switch_branch branch)"
    fs_clone="/usr/src/freeswitch"
    if [ -d "$fs_clone" ]; then
        rm -rf "$fs_clone"
    fi
    git clone https://github.com/signalwire/freeswitch.git "$fs_clone"
    cd "$fs_clone"

    case "$switch_branch" in
        stable)
            git checkout "$(git tag -l 'v1.*' | sort -V | tail -1)"
            ;;
        master)
            git checkout master
            ;;
        *)
            git checkout "$switch_branch"
            ;;
    esac

    ./bootstrap.sh -j
    # bootstrap.sh creates modules.conf from build/modules.conf.in. Configure
    # the generated manifest afterwards, matching FusionPBX's source flow.
    enable_tallpbx_source_modules modules.conf
    disable_tallpbx_source_modules modules.conf
    ./configure -C --enable-portable-binary --disable-dependency-tracking \
        --prefix=/usr --localstatedir=/var --sysconfdir=/etc --with-openssl \
        --enable-core-pgsql-support
    make -j "$(nproc)"
    make install
    make cd-sounds-install cd-moh-install

    sound_languages="${FSPBX_SOUND_LANGUAGES:-$(get_env_value "$INSTALLER_STATE_FILE" FSPBX_SOUND_LANGUAGES)}"
    sound_languages="${sound_languages:-en}"

    if [[ "$sound_languages" == *"es"* ]]; then
        verbose "Downloading Spanish sound prompts for source build"
        mkdir -p /usr/share/freeswitch/sounds/es/ar/mario
        curl -fsSL https://files.freeswitch.org/releases/sounds/freeswitch-sounds-es-ar-mario-8000-1.0.51.tar.gz 2>/dev/null \
            | tar -xz -C /usr/share/freeswitch/sounds/es/ar/mario 2>/dev/null || true
        chown -R freeswitch:freeswitch /usr/share/freeswitch/sounds/es 2>/dev/null || true
        ensure_freeswitch_module_enabled "$modules_conf" "mod_say_es" "<!-- Languages -->"
    fi

    if [[ "$sound_languages" == *"fr"* ]]; then
        verbose "Downloading French sound prompts for source build"
        mkdir -p /usr/share/freeswitch/sounds/fr/ca/june
        curl -fsSL https://files.freeswitch.org/releases/sounds/freeswitch-sounds-fr-ca-june-8000-1.0.51.tar.gz 2>/dev/null \
            | tar -xz -C /usr/share/freeswitch/sounds/fr/ca/june 2>/dev/null || true
        chown -R freeswitch:freeswitch /usr/share/freeswitch/sounds/fr 2>/dev/null || true
        ensure_freeswitch_module_enabled "$modules_conf" "mod_say_fr" "<!-- Languages -->"
    fi

    install_freeswitch_source_service

    verbose "FreeSWITCH source build complete"
    fi

# ------------------------------------------------------------------
# Option 2: Install from apt packages (default)
# ------------------------------------------------------------------
# This downloads pre-built FreeSWITCH packages from SignalWire's
# package repository. It's faster and doesn't need compilation tools.
# A SignalWire Personal Access Token is required for authentication.
elif [ "$freeswitch_install_method" = "packages" ]; then
    if [ "$previous_freeswitch_install_method" = "source" ] || source_build_is_installed; then
        verbose "Removing the existing FreeSWITCH source installation before installing packages"
        uninstall_freeswitch_source
    fi
    # Reuse durable installer state. The existing APT auth file provides a
    # migration path for servers installed before secure state was introduced.
    INSTALLER_STATE_FILE="${PBX_INSTALLER_STATE_FILE:-/etc/pbx/installer.env}"
    # The main installer gathers this secret before work begins. A direct run
    # still accepts the configured, saved, or interactively entered token.
    switch_token=$(resolve_signalwire_token \
        "${FSPBX_SWITCH_TOKEN:-$switch_token}" \
        "$INSTALLER_STATE_FILE" \
        /etc/apt/auth.conf.d/freeswitch.conf)

    switch_token=$(printf '%s' "$switch_token" | sed -e 's/^[[:space:]"'"'"']*//' -e 's/[[:space:]"'"'"']*$//')

    # Only a direct interactive run may ask here. The main installer always
    # supplies the token during its preflight questionnaire.
    if [ -z "$switch_token" ]; then
        if [ "${FSPBX_INSTALLER_EXECUTION:-false}" = true ]; then
            error "The main installer did not collect the SignalWire token. Re-run the installer."
            exit 1
        fi

        echo ""
        warning "A SignalWire Personal Access Token is required."
        warning "Create one at https://signalwire.com -> Personal Access Tokens"
        echo ""
        read -rsp "$(verbose 'Enter your Personal Access Token: ')" switch_token
        echo ""
        switch_token=$(printf '%s' "$switch_token" | sed -e 's/^[[:space:]"'"'"']*//' -e 's/[[:space:]"'"'"']*$//')
        if [ -z "$switch_token" ]; then
            error "A token is required to install FreeSWITCH packages. Aborting."
            exit 1
        fi

    else
        verbose "Reusing the existing SignalWire Personal Access Token"
    fi

    set_secure_env_value "$INSTALLER_STATE_FILE" SWITCH_TOKEN "$switch_token"

    verbose "Installing FreeSWITCH prerequisites"
    apt_get_with_lock_wait update
    apt_get_with_lock_wait install -y curl memcached haveged apt-transport-https \
        gnupg2 wget lsb-release sox

    # Remove any leftover files from previous failed runs so we start fresh
    rm -f /usr/share/keyrings/signalwire-freeswitch-repo.gpg
    rm -f /etc/apt/auth.conf.d/freeswitch.conf
    rm -f /etc/apt/sources.list.d/freeswitch.list

    verbose "Adding the SignalWire FreeSWITCH package repository"

    # Debian trixie doesn't have FreeSWITCH packages in the normal "release"
    # repository, so we use the "unstable" one instead (per SignalWire support).
    if [ "$(lsb_release -sc)" = "trixie" ]; then
        switch_repo="debian-unstable"
        warning "Debian trixie detected: using SignalWire debian-unstable repo"
    else
        switch_repo="debian-release"
    fi

    # Download the GPG key that apt uses to verify package authenticity.
    # If the token is wrong, this download will fail (0-byte file).
    wget --http-user=signalwire --http-password="$switch_token" \
        -O /usr/share/keyrings/signalwire-freeswitch-repo.gpg \
        "https://freeswitch.signalwire.com/repo/deb/${switch_repo}/signalwire-freeswitch-repo.gpg"

    # If the keyring file is empty, the token was rejected
    if [ ! -s /usr/share/keyrings/signalwire-freeswitch-repo.gpg ]; then
        error "SignalWire authentication failed. The saved Personal Access Token may be invalid."
        error "Update switch_token in config.sh or run the installer again to enter a new token."
        # Clear invalid durable state so the default configuration prompts once.
        set_secure_env_value "$INSTALLER_STATE_FILE" SWITCH_TOKEN ""
        exit 1
    fi

    # Save the token so apt can use it to download packages
    mkdir -p /etc/apt/auth.conf.d
    echo "machine freeswitch.signalwire.com login signalwire password $switch_token" \
        > /etc/apt/auth.conf.d/freeswitch.conf
    chmod 600 /etc/apt/auth.conf.d/freeswitch.conf

    # Add the FreeSWITCH repository to apt's sources list
    echo "deb [signed-by=/usr/share/keyrings/signalwire-freeswitch-repo.gpg] https://freeswitch.signalwire.com/repo/deb/${switch_repo}/ $(lsb_release -sc) main" \
        > /etc/apt/sources.list.d/freeswitch.list

    apt_get_with_lock_wait update

    # Install FreeSWITCH, common modules used by the default vanilla
    # configuration, mod_xml_curl for dynamic app-provided XML, and
    # the US English sound files.
    verbose "Installing FreeSWITCH"
    apt_get_with_lock_wait install -y freeswitch-meta-vanilla \
        freeswitch-mod-av \
        freeswitch-mod-callcenter \
        freeswitch-mod-dptools \
        freeswitch-mod-voicemail \
        freeswitch-mod-local-stream \
        freeswitch-mod-opus \
        freeswitch-mod-png \
        freeswitch-mod-rtc \
        freeswitch-mod-sndfile \
        freeswitch-mod-sofia \
        freeswitch-mod-signalwire \
        freeswitch-mod-verto \
        freeswitch-mod-xml-curl \
        freeswitch-sounds-en-us-callie

    install_freeswitch_hiredis_package

    sound_languages="${FSPBX_SOUND_LANGUAGES:-$(get_env_value "$INSTALLER_STATE_FILE" FSPBX_SOUND_LANGUAGES)}"
    sound_languages="${sound_languages:-en}"

    if [[ "$sound_languages" == *"es"* ]]; then
        verbose "Installing Spanish sound prompts and grammar module"
        apt_get_optional_with_lock_wait install -y freeswitch-sounds-es-ar-mario freeswitch-mod-say-es || true
        ensure_freeswitch_module_enabled "$modules_conf" "mod_say_es" "<!-- Languages -->"
    fi

    if [[ "$sound_languages" == *"fr"* ]]; then
        verbose "Installing French sound prompts and grammar module"
        apt_get_optional_with_lock_wait install -y freeswitch-sounds-fr-ca-june freeswitch-mod-say-fr || true
        ensure_freeswitch_module_enabled "$modules_conf" "mod_say_fr" "<!-- Languages -->"
    fi

    # Music-on-hold is a separate package that may not be available in all repos.
    # If it's not found, we skip it instead of failing the whole install.
    if apt_get_optional_with_lock_wait install -y freeswitch-sounds-music; then
        verbose "Preserving music-on-hold"
        # The music package includes default hold music. We save it,
        # remove the package (to free up disk), then copy the music back.
        mkdir -p /usr/share/freeswitch/sounds/temp
        mv /usr/share/freeswitch/sounds/music/*000 /usr/share/freeswitch/sounds/temp 2>/dev/null || true
        mv /usr/share/freeswitch/sounds/music/default/*000 /usr/share/freeswitch/sounds/temp 2>/dev/null || true
        apt_get_with_lock_wait remove -y freeswitch-sounds-music
        mkdir -p /usr/share/freeswitch/sounds/music/default
        mv /usr/share/freeswitch/sounds/temp/* /usr/share/freeswitch/sounds/music/default/ 2>/dev/null || true
        rm -rf /usr/share/freeswitch/sounds/temp
    else
        warning "freeswitch-sounds-music not available in this repo; music-on-hold will not be installed"
    fi

    verbose "FreeSWITCH package install complete"

else
    error "No FreeSWITCH installation method was selected."
    exit 1
fi

# ------------------------------------------------------------------
# Post-install tasks (done for both install methods)
# ------------------------------------------------------------------
configure_tallpbx_media_root

# Make sure the systemd service file exists before trying to enable it
if [ -f /lib/systemd/system/freeswitch.service ]; then
    configure_freeswitch_systemd_dependencies

    # Tell systemd to reload config, enable FreeSWITCH on boot, and start it now
    systemctl daemon-reload
    systemctl enable freeswitch
    systemctl unmask freeswitch.service 2>/dev/null || true
    systemctl start freeswitch
fi

configure_freeswitch_switch_defaults
configure_freeswitch_sound_defaults
configure_dynamic_xml
set_secure_env_value "$INSTALLER_STATE_FILE" FSPBX_FREESWITCH_INSTALLED_METHOD "$freeswitch_install_method"

verbose "FreeSWITCH installation complete"
