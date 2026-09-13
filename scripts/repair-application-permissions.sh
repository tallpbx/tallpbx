#!/usr/bin/env bash
# Stop immediately on an error, an unset variable, or a failed command in a
# pipeline. A partial permission repair can leave the application unusable.
set -euo pipefail

# Work from the application root even when this script is launched from a
# different directory.
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# Restore PHP-FPM's read-only access to Laravel's secrets before trying to
# boot Artisan. This is safe on every re-run: it never changes a secret, and
# it repairs the exact root-owned mode PHP-FPM needs when an interrupted
# deployment or manual root operation left .env readable only by root.
repair_environment_file_access() {
    local environment_file="$ROOT_DIR/.env"

    # A new deployment has no environment file until the installer creates it.
    # Leave that case alone so this recovery script remains safe before setup.
    [ -e "$environment_file" ] || return

    chown root:www-data "$environment_file"
    chmod 640 "$environment_file"
}

# Restore executable bits recorded by Git after a full repair has normalized
# source files. This is deliberately available before the Artisan fast path,
# because the PHP repair also normalizes source modes before returning.
restore_tracked_modes() {
    # Only Git records which project files are intended to be runnable. This
    # avoids making regular source files executable while preserving helpers.
    while IFS=$'\t' read -r metadata path; do
        mode="${metadata%% *}"

        if [ "$mode" = "100755" ]; then
            chmod 755 "$ROOT_DIR/$path"
        else
            chmod 644 "$ROOT_DIR/$path"
        fi
    done < <(git -C "$ROOT_DIR" ls-files -s)
}

# Perform the minimal bootstrap-safe repair first. Artisan itself reads .env,
# so it cannot recover an application whose PHP-FPM worker lacks this access.
repair_environment_file_access

# Prefer the unified PHP repair service whenever Laravel can boot. The shell
# implementation below remains an emergency fallback for broken deployments.
if [ -f "$ROOT_DIR/artisan" ]; then
    if (cd "$ROOT_DIR" && php artisan permissions:repair --scope=full); then
        # The PHP repair preserves these modes as well, but repeat the small
        # Git-authoritative pass here so successful recovery never returns
        # with an installer helper unexpectedly non-executable.
        restore_tracked_modes
        exit 0
    fi

    echo "Laravel permission repair failed; using the standalone shell fallback." >&2
fi

# Make application source readable by PHP-FPM without allowing the web server
# user to edit the source code.
repair_source_tree() {
    local path="$1"

    # Some optional paths may not exist on every installation.
    [ -e "$path" ] || return

    # Root owns deployed source. The www-data group can read it so PHP-FPM and
    # Nginx can serve the application files.
    chown -R root:www-data "$path"

    # Directories must be searchable to access files inside them. Source files
    # are readable, but are not generally executable.
    find "$path" -type d -exec chmod 755 {} +
    find "$path" -type f -exec chmod 644 {} +
}

# Make Composer-installed PHP packages readable by PHP-FPM while preserving the
# executable package entry points Composer creates under vendor/bin, and allow
# the www-data group to install or update packages when managing updates.
repair_vendor_tree() {
    local path="$1"

    # Vendor is absent before the first Composer install.
    [ -e "$path" ] || return

    # Composer may run as root or as www-data during web updates. Root owns the
    # packages, while the www-data group has write access to update dependencies.
    chown -R root:www-data "$path"

    # Setgid 2775 ensures newly created directories retain the www-data group.
    # PHP-FPM needs to traverse package directories and read package files.
    # Restore execute permission only to files that Composer already marked as
    # executable, such as vendor/bin/pint and vendor/bin/pest.
    find "$path" -type d -exec chmod 2775 {} +
    find "$path" -type f -perm /111 -exec chmod 755 {} +
    find "$path" -type f ! -perm /111 -exec chmod 664 {} +
}

# Make Laravel's writable folders safely writable by the PHP-FPM user.
repair_runtime_tree() {
    local path="$1"

    # Skip a runtime directory if it has not been created yet.
    [ -e "$path" ] || return

    # Laravel, queues, and PHP-FPM write cache, log, session, and uploaded
    # files here, so www-data owns these folders and their contents.
    chown -R www-data:www-data "$path"

    # The leading 2 sets the setgid bit: new files and folders inherit the
    # www-data group. This prevents mixed ownership after maintenance commands.
    find "$path" -type d -exec chmod 2775 {} +
    find "$path" -type f -exec chmod 664 {} +
}

# Repair the version-controlled source trees.
for source_path in \
    "$ROOT_DIR/app" \
    "$ROOT_DIR/app-modules" \
    "$ROOT_DIR/bootstrap" \
    "$ROOT_DIR/config" \
    "$ROOT_DIR/database" \
    "$ROOT_DIR/docs" \
    "$ROOT_DIR/lang" \
    "$ROOT_DIR/public" \
    "$ROOT_DIR/resources" \
    "$ROOT_DIR/routes" \
    "$ROOT_DIR/scripts" \
    "$ROOT_DIR/tests"; do
    repair_source_tree "$source_path"
done

# Repair Git repository access so both root and the web server user can
# safely inspect and update version control metadata.
if [ -d "$ROOT_DIR/.git" ]; then
    chown -R root:www-data "$ROOT_DIR/.git"
    find "$ROOT_DIR/.git" -type d -exec chmod 2775 {} +
    find "$ROOT_DIR/.git" -type f -exec chmod 664 {} +
    git -C "$ROOT_DIR" config core.sharedRepository group 2>/dev/null || true
fi

# Trust the deployment path system-wide so Git commands never abort with
# safe.directory ownership errors when invoked by different system users.
if ! git config --system --get-all safe.directory 2>/dev/null | grep -Fxq "$ROOT_DIR"; then
    git config --system --add safe.directory "$ROOT_DIR" 2>/dev/null || true
fi

# Ensure web user SSH keys/config (for private Git repos) are secure and accessible.
if [ -d "/var/www/.ssh" ]; then
    chown -R www-data:www-data /var/www/.ssh
    chmod 700 /var/www/.ssh
    for key_file in /var/www/.ssh/id_* /var/www/.ssh/config; do
        [ -e "$key_file" ] && chmod 600 "$key_file"
    done
    [ -e "/var/www/.ssh/known_hosts" ] && chmod 644 /var/www/.ssh/known_hosts
fi

# Ensure web user cache directory (for Composer package cache) is accessible.
if [ -d "/var/www/.cache" ]; then
    chown -R www-data:www-data /var/www/.cache
    find /var/www/.cache -type d -exec chmod 2775 {} +
    find /var/www/.cache -type f -exec chmod 664 {} +
fi

# Repair Composer dependencies separately because vendor/bin contains package
# executables that must retain their executable mode.
repair_vendor_tree "$ROOT_DIR/vendor"

# Apply the same read-only source policy to important files at the repository
# root that are not covered by the source-tree loop above.
for source_file in \
    "$ROOT_DIR/artisan" \
    "$ROOT_DIR/composer.json" \
    "$ROOT_DIR/package.json" \
    "$ROOT_DIR/vite.config.js"; do
    [ -e "$source_file" ] && chown root:www-data "$source_file" && chmod 644 "$source_file"
done

# Dependency lockfiles must be writable by the www-data group so web updates can
# run composer install or npm commands that touch or update the locks.
for lock_file in \
    "$ROOT_DIR/composer.lock" \
    "$ROOT_DIR/package-lock.json"; do
    [ -e "$lock_file" ] && chown root:www-data "$lock_file" && chmod 664 "$lock_file"
done

# Apply the same environment-file policy if this script had to use the shell
# fallback. Keeping this call here prevents future fallback edits from
# accidentally omitting the secret-file reconciliation step.
repair_environment_file_access

# These are the only application areas Laravel must write to at runtime.
# They contain logs, cached files, compiled views, sessions, queues, and media.
repair_runtime_tree "$ROOT_DIR/storage"
repair_runtime_tree "$ROOT_DIR/bootstrap/cache"

# Apply the matching policy to generated cache and frontend build files. That
# helper also handles paths that may be created after this script starts.
bash "$ROOT_DIR/scripts/fix-generated-permissions.sh"

# The generated-file helper can also touch tracked runtime placeholders. Run
# this last so the worktree ends with exactly the executable modes in Git.
restore_tracked_modes
