#!/usr/bin/env bash
# Team management (team-roles) for Pterodactyl 2.0 - diagnosis ("why do I not see it in the panel?")
# by floiofficial
#
# Usage:  sudo bash diagnose.sh [PANEL_DIRECTORY]
#
# READ-ONLY: changes nothing. Prints what the panel itself says about the extension (enabled? error?
# doctor check?), whether the built files are in place and whether the database tables exist.
# If you ask for help, send the complete output of this script.
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PANEL_DIR="${1:-/var/www/pterodactyl}"
ID="team-roles"

say()  { printf '\033[1;36m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m!!\033[0m  %s\n' "$*"; }
good() { printf '\033[1;32m OK\033[0m  %s\n' "$*"; }
artisan() { (cd "$PANEL_DIR" && php artisan "$@"); }

[ -f "$PANEL_DIR/artisan" ] || { echo "ERROR: No panel at $PANEL_DIR (artisan missing). Pass the path as an argument." >&2; exit 1; }

say "1/6 Panel and extensions switch"
artisan --version 2>&1 | head -n 2 || true
if grep -Eqi '^PTERODACTYL_EXTENSIONS_ENABLED=(false|0)' "$PANEL_DIR/.env" 2>/dev/null; then
    warn "PTERODACTYL_EXTENSIONS_ENABLED is off in .env - ALL extensions are disabled! Remove that line (or set it to true)."
else
    good "Extensions are not switched off globally."
fi

say "2/6 What the panel says (p:extension:list)"
LIST="$(artisan p:extension:list 2>&1 || true)"
printf '%s\n' "$LIST"
if ! grep -q "$ID" <<<"$LIST"; then
    warn "'$ID' is NOT installed. Run the installer: sudo bash setup.sh -> 1) Install"
elif grep -i "$ID" <<<"$LIST" | grep -Eqi 'disabled|inactive|no\b'; then
    warn "The extension line above looks DISABLED. Enable it: php artisan p:extension:enable $ID   (then reload the browser)"
fi

say "3/6 Panel check of the installed extension (p:extension:doctor)"
if artisan list 2>/dev/null | grep -q 'p:extension:doctor'; then
    artisan p:extension:doctor "$PANEL_DIR/extensions/$ID" 2>&1 || artisan p:extension:doctor "$ID" 2>&1 || warn "doctor reported problems (see above)."
else
    warn "This panel has no p:extension:doctor command."
fi

say "4/6 Files"
EXT="$PANEL_DIR/extensions/$ID"
if [ -f "$EXT/extension.json" ]; then
    good "extension.json: version $(grep -m1 '"version"' "$EXT/extension.json" | sed 's/[^0-9.]//g')"
else
    warn "$EXT/extension.json is missing."
fi
if [ -s "$EXT/dist/client.js" ]; then good "dist/client.js present ($(wc -c <"$EXT/dist/client.js") bytes)"; else warn "$EXT/dist/client.js is missing or empty."; fi
if [ -d "$PANEL_DIR/public/assets/extensions" ]; then
    ls -la "$PANEL_DIR/public/assets/extensions" 2>/dev/null | grep -i "$ID" || warn "No published assets for $ID in public/assets/extensions (extension not enabled, or enable failed)."
else
    warn "$PANEL_DIR/public/assets/extensions does not exist."
fi
OWNER="$(stat -c %U "$PANEL_DIR/public/assets/extensions" 2>/dev/null || echo '?')"
echo "owner of public/assets/extensions: $OWNER (should be your web server user, e.g. www-data)"

say "5/6 Database and permission check"
if [ -f "$SCRIPT_DIR/tools/team-tool.php" ]; then
    php "$SCRIPT_DIR/tools/team-tool.php" "$PANEL_DIR" check 2>&1 | tail -n 20 || true
else
    warn "tools/team-tool.php not found next to this script."
fi

say "6/6 Latest log lines about the extension"
LOG="$(ls -t "$PANEL_DIR"/storage/logs/*.log 2>/dev/null | head -n 1 || true)"
if [ -n "$LOG" ]; then
    grep -iE 'team-roles|TeamRoles|ExtensionLoadFailed' "$LOG" | tail -n 15 | cut -c1-300 || true
    echo "(log file: $LOG)"
else
    echo "No log file found."
fi

echo
echo "In the BROWSER: reload the panel with Ctrl+F5, open the developer tools (F12) -> Console and"
echo "look for red lines mentioning 'team-roles' / 'extension'. Admin -> Extensions also shows load errors."
echo "(by floiofficial)"
