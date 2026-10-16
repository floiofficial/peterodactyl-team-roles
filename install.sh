#!/usr/bin/env bash
# Team management (team-roles) for Pterodactyl 2.0 - installer
# by floiofficial
#
# Usage:   sudo bash install.sh [PANEL_DIRECTORY] [--yes] [--web-user www-data]
#                               [--owner EMAIL[,EMAIL2]] [--others administrator|moderator|friend|user|owner]
# Example: sudo bash install.sh /var/www/pterodactyl --owner me@example.com
#
# --owner   E-mail of the panel account that becomes Owner (several: comma-separated). Without --owner the
#           installer asks; empty input (or --yes without --owner) = all current admins stay Owner (old behaviour).
# --others  What the remaining current admins become (default: administrator).
#
# What happens: back up the current admin accounts -> install and enable the extension.
# The panel core is NOT modified. Undo with: sudo bash uninstall.sh
set -Eeuo pipefail   # -E: the ERR trap (automatic rollback) also applies inside functions

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PANEL_DIR="/var/www/pterodactyl"
WEB_USER="www-data"
ASSUME_YES=0
STARTED=0
OWNER_EMAILS=""
OTHERS_SLUG=""

while [ $# -gt 0 ]; do
    case "$1" in
        --yes|-y) ASSUME_YES=1 ;;
        --web-user) shift; WEB_USER="${1:?--web-user needs a name}" ;;
        --owner) shift; OWNER_EMAILS="${OWNER_EMAILS:+$OWNER_EMAILS,}${1:?--owner needs an e-mail address}" ;;
        --others) shift; OTHERS_SLUG="${1:?--others needs a role}" ;;
        -h|--help) sed -n '2,14p' "$0"; exit 0 ;;
        -*) echo "Unknown option: $1" >&2; exit 2 ;;
        *) PANEL_DIR="$1" ;;
    esac
    shift
done

say()  { printf '\033[1;36m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m!!\033[0m  %s\n' "$*"; }
die()  { printf '\033[1;31mERROR:\033[0m %s\n' "$*" >&2; exit 1; }
# Read input from the terminal (also with "curl ... | bash"); TEAMROLES_STDIN=1 forces stdin (tests)
ask() {   # $1 = question, $2 = variable name
    local __ans=""
    if [ -r /dev/tty ] && [ -z "${TEAMROLES_STDIN:-}" ]; then read -r -p "$1" __ans < /dev/tty || true
    else read -r -p "$1" __ans || true; fi
    printf -v "$2" '%s' "$__ans"
}
artisan() { (cd "$PANEL_DIR" && php artisan "$@"); }
tool()    { php "$SCRIPT_DIR/tools/team-tool.php" "$PANEL_DIR" "$@"; }

# With set -E the ERR trap fires in the subshell of artisan() AND afterwards in the main process.
# The lock (atomic rmdir) makes sure the rollback runs exactly once.
ROLLBACK_LOCK="$(mktemp -d)"
trap 'rmdir "$ROLLBACK_LOCK" 2>/dev/null || true' EXIT

on_error() {
    local code=$?
    rmdir "$ROLLBACK_LOCK" 2>/dev/null || exit "$code"
    warn "Installation failed (code $code)."
    if [ "$STARTED" -eq 1 ]; then
        # Only roll back if something is actually there (extension registered or tables created).
        local listed status_out
        listed="$(artisan p:extension:list 2>/dev/null || true)"
        status_out="$(tool status 2>/dev/null || true)"
        if grep -q 'team-roles' <<<"$listed" || ! grep -q 'tables not present' <<<"$status_out"; then
            warn "Undoing everything ..."
            bash "$SCRIPT_DIR/uninstall.sh" "$PANEL_DIR" --yes --web-user "$WEB_USER" || \
                warn "Automatic rollback incomplete - please run 'bash uninstall.sh' manually."
        else
            warn "Nothing was installed - there is nothing to roll back. The panel was not changed."
        fi
    fi
    exit "$code"
}
trap on_error ERR

# ---------------------------------------------------------------- checks
say "Checking environment ($PANEL_DIR)"
command -v php >/dev/null || die "php not found."
[ -f "$PANEL_DIR/artisan" ] || die "No panel at $PANEL_DIR (artisan missing). Pass the path as an argument."
[ -d "$SCRIPT_DIR/extension/team-roles" ] || die "Folder extension/team-roles is missing next to install.sh."

COMMANDS="$(artisan list 2>/dev/null || true)"
grep -q 'p:extension:install' <<<"$COMMANDS" \
    || die "This panel does not know extensions (p:extension:install is missing). Team management needs Pterodactyl 2.0."

INSTALLED="$(artisan p:extension:list 2>/dev/null || true)"
if grep -q 'team-roles' <<<"$INSTALLED"; then
    die "'team-roles' is already installed. To update, use update.sh (or run 'bash uninstall.sh --keep-data' first)."
fi

tool check

# ------------------------------------------------------------ notice + confirmation
say "Current admins:"
tool status
echo
case "$OTHERS_SLUG" in ""|owner|administrator|moderator|friend|user) ;; *) die "--others '$OTHERS_SLUG' is invalid (administrator|moderator|friend|user|owner)." ;; esac

# ------------------------------------------------------------ choose the Owner
OTHERS_COUNT=0
resolve_owners() {   # $1 = e-mail list; sets RESOLVE_OUT/OTHERS_COUNT, returns 0 = all found
    local raw
    if ! raw="$(tool resolve-owners "$1" 2>&1)"; then RESOLVE_OUT="$raw"; return 1; fi
    OTHERS_COUNT="$(sed -n 's/^OTHERS=//p' <<<"$raw")"
    RESOLVE_OUT="$(sed '/^OTHERS=/d' <<<"$raw")"
    return 0
}

if [ -n "$OWNER_EMAILS" ]; then
    resolve_owners "$OWNER_EMAILS" || die "Owner not found: $RESOLVE_OUT"
elif [ "$ASSUME_YES" -ne 1 ]; then
    echo "Who should become Owner? Enter the e-mail address of the account in the panel"
    echo "(several: comma-separated). Only Owners may do everything. Leave empty = ALL current admins stay Owner."
    while true; do
        ask "Owner e-mail: " OWNER_EMAILS
        [ -z "$OWNER_EMAILS" ] && break
        if resolve_owners "$OWNER_EMAILS"; then break; fi
        warn "$RESOLVE_OUT"
        warn "Please enter it again (or leave empty)."
    done
fi

if [ -n "$OWNER_EMAILS" ]; then
    say "These accounts become Owner:"
    echo "$RESOLVE_OUT"
    if [ "${OTHERS_COUNT:-0}" -gt 0 ] && [ -z "$OTHERS_SLUG" ]; then
        if [ "$ASSUME_YES" -eq 1 ]; then
            OTHERS_SLUG="administrator"
        else
            echo
            echo "There are $OTHERS_COUNT more current admin(s). What should they become?"
            echo "  1) Administrator (default - admin access, but cannot change API keys/settings)"
            echo "  2) Moderator (view only)"
            echo "  3) User / Customer (no admin access any more)"
            echo "  4) Owner (as before)"
            ask "Choice [1-4, Enter = 1]: " sel
            case "${sel:-1}" in 2) OTHERS_SLUG=moderator ;; 3) OTHERS_SLUG=user ;; 4) OTHERS_SLUG=owner ;; *) OTHERS_SLUG=administrator ;; esac
        fi
    fi
    [ -n "$OTHERS_SLUG" ] || OTHERS_SLUG="administrator"
fi

echo
if [ -n "$OWNER_EMAILS" ]; then
    warn "Only the accounts above become Owner. The remaining current admins ($OTHERS_COUNT) become: $OTHERS_SLUG."
else
    warn "ALL current admins become OWNER (= previous behaviour, nothing changes for them)."
    warn "Afterwards you can demote them to Administrator/Moderator under Admin -> Users -> Team management."
fi
warn "Moderator/Administrator internally get admin access; this extension restricts it."
warn "If you set PTERODACTYL_EXTENSIONS_ENABLED=false the restriction is NOT attached any more -> run 'php tools/team-tool.php $PANEL_DIR demote-staff' first."
if [ "$ASSUME_YES" -ne 1 ]; then
    ask "Continue? [y/N] " reply
    case "$reply" in y|Y|yes|Yes|j|J|ja|Ja) ;; *) echo "Aborted. Nothing was changed."; exit 0 ;; esac
fi

# ------------------------------------------------------------------ backup
mkdir -p "$SCRIPT_DIR/backups"
BACKUP="$SCRIPT_DIR/backups/admins-before-install-$(date +%Y%m%d-%H%M%S).csv"
say "Backing up admin accounts to $BACKUP"
tool snapshot "$BACKUP"
warn "Recommendation: also take a database backup (mysqldump) - the extension creates 5 tables of its own (roles, members, tickets)."

# ---------------------------------------------------------------- installation
STARTED=1
say "Installing and enabling the extension"
artisan p:extension:install "$SCRIPT_DIR/extension/team-roles" --enable

if [ "$(id -u)" -eq 0 ] && id "$WEB_USER" >/dev/null 2>&1; then
    say "Setting file permissions for $WEB_USER"
    for p in extensions public/assets/extensions storage bootstrap/cache; do
        [ -e "$PANEL_DIR/$p" ] && chown -R "$WEB_USER:$WEB_USER" "$PANEL_DIR/$p" || true
    done
else
    warn "Not started as root or user '$WEB_USER' is missing - please set the file permissions yourself:"
    warn "chown -R www-data:www-data $PANEL_DIR/extensions $PANEL_DIR/public/assets/extensions $PANEL_DIR/storage $PANEL_DIR/bootstrap/cache"
fi

if [ -n "$OWNER_EMAILS" ]; then
    say "Setting the Owner and the roles of the other admins"
    tool set-owners "$OWNER_EMAILS" "--others=$OTHERS_SLUG"
fi

artisan queue:restart >/dev/null 2>&1 || true
artisan p:extension:list

say "Checking the result"
tool status

echo
say "Done. In the panel open: Admin -> \"Team management\" and \"Support tickets\" (reload the page once)."
say "Role per user: Admin -> Users -> user -> 'Role' tab. Customers find \"Support\" in the top navigation."
say "To undo: sudo bash $SCRIPT_DIR/uninstall.sh $PANEL_DIR"
echo "(by floiofficial)"
