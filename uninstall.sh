#!/usr/bin/env bash
# Team management (team-roles) for Pterodactyl 2.0 - uninstaller
# by floiofficial
#
# Usage:   sudo bash uninstall.sh [PANEL_DIRECTORY] [--yes] [--keep-data] [--web-user www-data]
#   --keep-data   keep the ext_team_roles_* tables (e.g. for an update)
#
# IMPORTANT - the order is intentional:
#   1. FIRST all non-Owners lose admin status (otherwise moderators/administrators would suddenly be
#      FULL admins after the extension is removed, because the restriction disappears).
#   2. Then the extension is disabled and removed.
#   3. Then its own tables are dropped.
# Owners stay admins. The panel core was never modified, so there is nothing to revert there.
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PANEL_DIR="/var/www/pterodactyl"
WEB_USER="www-data"
ASSUME_YES=0
KEEP_DATA=0

while [ $# -gt 0 ]; do
    case "$1" in
        --yes|-y) ASSUME_YES=1 ;;
        --keep-data) KEEP_DATA=1 ;;
        --web-user) shift; WEB_USER="${1:?--web-user needs a name}" ;;
        -h|--help) sed -n '2,14p' "$0"; exit 0 ;;
        -*) echo "Unknown option: $1" >&2; exit 2 ;;
        *) PANEL_DIR="$1" ;;
    esac
    shift
done

say()  { printf '\033[1;36m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m!!\033[0m  %s\n' "$*"; }
die()  { printf '\033[1;31mERROR:\033[0m %s\n' "$*" >&2; exit 1; }
artisan() { (cd "$PANEL_DIR" && php artisan "$@"); }
tool()    { php "$SCRIPT_DIR/tools/team-tool.php" "$PANEL_DIR" "$@"; }

command -v php >/dev/null || die "php not found."
[ -f "$PANEL_DIR/artisan" ] || die "No panel at $PANEL_DIR (artisan missing). Pass the path as an argument."

say "This is what removal does:"
tool demote-staff --dry-run || die "Could not check the accounts - aborting so that nobody accidentally becomes a full admin."
echo
if [ "$ASSUME_YES" -ne 1 ]; then
    read -r -p "Remove team management now? [y/N] " reply
    case "$reply" in y|Y|yes|Yes|j|J|ja|Ja) ;; *) echo "Aborted. Nothing was changed."; exit 0 ;; esac
fi

# 1. Safety first: demote non-Owners. If that fails, nothing else is removed.
say "1/4 Demoting all non-Owners"
tool demote-staff || die "Demotion failed - the extension stays installed so the restrictions keep applying."

# 2. Disable and remove the extension (only if it is registered)
say "2/4 Disabling and removing the extension"
LISTED="$(artisan p:extension:list 2>/dev/null || true)"
if grep -q 'team-roles' <<<"$LISTED"; then
    artisan p:extension:disable team-roles || warn "disable reported an error (maybe already disabled)."
    # "p:extension:remove" asks for confirmation (yes/no, default no): use --force/--yes depending on the panel version, otherwise answer "yes".
    REMOVE_HELP="$(artisan p:extension:remove --help 2>&1 || true)"
    if grep -q -- '--force' <<<"$REMOVE_HELP"; then
        artisan p:extension:remove team-roles --force || warn "remove reported an error."
    elif grep -q -- '--yes' <<<"$REMOVE_HELP"; then
        artisan p:extension:remove team-roles --yes || warn "remove reported an error."
    else
        printf 'yes\n' | artisan p:extension:remove team-roles || warn "remove reported an error."
    fi
    if grep -q 'team-roles' <<<"$(artisan p:extension:list 2>/dev/null || true)"; then
        warn "The extension is still listed. Please run by hand: php artisan p:extension:remove team-roles"
    fi
else
    say "Extension is not (or no longer) registered - nothing to remove."
fi

# 3. Drop own tables
if [ "$KEEP_DATA" -eq 1 ]; then
    say "3/4 Keeping the tables (--keep-data)"
else
    say "3/4 Dropping own tables (ext_team_roles_*)"
    tool drop-tables || warn "Tables could not be dropped - later run: php tools/team-tool.php $PANEL_DIR drop-tables"
fi

# 4. Clean up
say "4/4 Clearing caches, restarting the queue"
artisan optimize:clear >/dev/null 2>&1 || true
artisan queue:restart  >/dev/null 2>&1 || true
if [ "$(id -u)" -eq 0 ] && id "$WEB_USER" >/dev/null 2>&1; then
    for p in extensions public/assets/extensions storage bootstrap/cache; do
        [ -e "$PANEL_DIR/$p" ] && chown -R "$WEB_USER:$WEB_USER" "$PANEL_DIR/$p" || true
    done
fi

echo
say "Result:"
tool status || true
echo
say "Done. The panel is as before (moderators/administrators are normal users, Owners are admins)."
say "Reload the page in your browser. Anyone who should be an admin again: Admin -> Users -> edit the user."
echo "(by floiofficial)"
