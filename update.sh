#!/usr/bin/env bash
# Team management (team-roles) for Pterodactyl 2.0 - update from GitHub
# by floiofficial
#
# Usage:
#   sudo bash update.sh [PANEL_DIRECTORY] [--repo OWNER/REPO] [--version vX.Y.Z]
#                       [--yes] [--force] [--rollback] [--ext-dir DIR] [--web-user www-data]
#
# First time:  sudo bash update.sh /var/www/pterodactyl --repo floiofficial/peterodactyl-team-roles
#              (the repo is remembered in .update-repo, afterwards "sudo bash update.sh" is enough)
# Private repo: first  export GITHUB_TOKEN=...  (token with read access to Contents)
# Back to the previous version:  sudo bash update.sh --rollback
#
# Safety:
#   - Only releases with a matching SHA-256 checksum are installed.
#   - Before the update the installed version is copied to backups/.
#   - Updating uses "p:extension:install" over the existing version (the documented update path).
#     "p:extension:remove" is NEVER used - so the permission check stays active during the update.
#     If the installation fails, the panel restores the old version itself.
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PANEL_DIR="/var/www/pterodactyl"
EXT_DIR=""
WEB_USER="www-data"
REPO="${TEAMROLES_REPO:-}"
API_BASE="${TEAMROLES_API_BASE:-https://api.github.com}"
WANT_VERSION=""
ASSUME_YES=0
FORCE=0
ROLLBACK=0

while [ $# -gt 0 ]; do
    case "$1" in
        --repo) shift; REPO="${1:?--repo needs OWNER/REPO}" ;;
        --version) shift; WANT_VERSION="${1:?--version needs e.g. v1.1.0}" ;;
        --ext-dir) shift; EXT_DIR="${1:?--ext-dir needs a directory}" ;;
        --web-user) shift; WEB_USER="${1:?--web-user needs a name}" ;;
        --yes|-y) ASSUME_YES=1 ;;
        --force) FORCE=1 ;;
        --rollback) ROLLBACK=1 ;;
        -h|--help) sed -n '2,19p' "$0"; exit 0 ;;
        -*) echo "Unknown option: $1" >&2; exit 2 ;;
        *) PANEL_DIR="$1" ;;
    esac
    shift
done
[ -n "$EXT_DIR" ] || EXT_DIR="$PANEL_DIR/extensions"

say()  { printf '\033[1;36m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m!!\033[0m  %s\n' "$*"; }
die()  { printf '\033[1;31mERROR:\033[0m %s\n' "$*" >&2; exit 1; }
artisan() { (cd "$PANEL_DIR" && php artisan "$@"); }
tool()    { php "$SCRIPT_DIR/tools/team-tool.php" "$PANEL_DIR" "$@"; }

confirm() {
    [ "$ASSUME_YES" -eq 1 ] && return 0
    read -r -p "$1 [y/N] " reply
    case "$reply" in y|Y|yes|Yes|j|J|ja|Ja) return 0 ;; *) echo "Aborted. Nothing was changed."; exit 0 ;; esac
}

manifest_version() {   # $1 = path to extension.json
    php -r '$m = json_decode(file_get_contents($argv[1]), true); echo is_array($m) ? ($m["version"] ?? "") : "";' "$1"
}
manifest_id() {
    php -r '$m = json_decode(file_get_contents($argv[1]), true); echo is_array($m) ? ($m["id"] ?? "") : "";' "$1"
}

finish_install() {   # $1 = expected version
    if [ "$(id -u)" -eq 0 ] && id "$WEB_USER" >/dev/null 2>&1; then
        for p in extensions public/assets/extensions storage bootstrap/cache; do
            [ -e "$PANEL_DIR/$p" ] && chown -R "$WEB_USER:$WEB_USER" "$PANEL_DIR/$p" || true
        done
    else
        warn "Please check the file permissions: chown -R www-data:www-data $PANEL_DIR/extensions $PANEL_DIR/public/assets/extensions"
    fi
    artisan queue:restart >/dev/null 2>&1 || true
    artisan p:extension:list || true
    local now
    now="$(manifest_version "$EXT_DIR/team-roles/extension.json" 2>/dev/null || true)"
    if [ "$now" = "$1" ]; then
        say "Installed version is now $now."
    else
        warn "Expected version $1, installed is '$now'. Please check 'p:extension:list' and the team management."
    fi
}

# ------------------------------------------------------------------ basic checks
command -v php >/dev/null || die "php not found."
[ -f "$PANEL_DIR/artisan" ] || die "No panel at $PANEL_DIR (artisan missing). Pass the path as an argument."
INSTALLED_MANIFEST="$EXT_DIR/team-roles/extension.json"
[ -f "$INSTALLED_MANIFEST" ] || die "Team management is not installed here (missing: $INSTALLED_MANIFEST). Run install.sh first (or pass --ext-dir)."
INSTALLED_VERSION="$(manifest_version "$INSTALLED_MANIFEST")"
[ -n "$INSTALLED_VERSION" ] || die "Installed version not readable ($INSTALLED_MANIFEST)."

# ------------------------------------------------------------------------- Rollback
if [ "$ROLLBACK" -eq 1 ]; then
    LAST="$(ls -1d "$SCRIPT_DIR"/backups/extension-*/ 2>/dev/null | sort | tail -n 1 || true)"
    [ -n "$LAST" ] && [ -f "${LAST}team-roles/extension.json" ] || die "No backup of an earlier version found in $SCRIPT_DIR/backups."
    OLD_VERSION="$(manifest_version "${LAST}team-roles/extension.json")"
    say "Rollback: installed $INSTALLED_VERSION -> back to $OLD_VERSION (from $LAST)"
    warn "Database migrations of the newer version are not reverted."
    confirm "Roll back?"
    artisan p:extension:install "${LAST}team-roles"
    finish_install "$OLD_VERSION"
    say "Done. Reload the page in your browser."
    exit 0
fi

# ---------------------------------------------------------------------- GitHub details
command -v curl >/dev/null  || die "curl not found."
command -v unzip >/dev/null || die "unzip not found."
command -v sha256sum >/dev/null || die "sha256sum not found."

if [ -z "$REPO" ] && [ -f "$SCRIPT_DIR/.update-repo" ]; then
    REPO="$(tr -d '[:space:]' < "$SCRIPT_DIR/.update-repo")"
fi
[ -n "$REPO" ] || die "No repo given. The first time: bash update.sh --repo floiofficial/peterodactyl-team-roles"
[[ "$REPO" =~ ^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$ ]] || die "Repo '$REPO' is invalid (expected OWNER/REPO)."
if [ -n "$WANT_VERSION" ]; then
    [[ "$WANT_VERSION" =~ ^v[0-9]+(\.[0-9]+){2}([-+][0-9A-Za-z.-]+)?$ ]] || die "Version '$WANT_VERSION' is invalid (expected e.g. v1.1.0)."
fi

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

AUTH_ARGS=()
if [ -n "${GITHUB_TOKEN:-}" ]; then
    # Token via header file instead of the command line (otherwise visible in "ps")
    ( umask 077; printf 'Authorization: Bearer %s\n' "$GITHUB_TOKEN" > "$WORK/auth" )
    AUTH_ARGS=(-H "@$WORK/auth")
fi

gh_get() {   # $1 = URL, $2 = target file, $3 = Accept
    curl -fsSL --max-time 120 -H "Accept: $3" -H "X-GitHub-Api-Version: 2022-11-28" "${AUTH_ARGS[@]}" "$1" -o "$2"
}

say "Asking GitHub for the version ($REPO)"
if [ -n "$WANT_VERSION" ]; then
    RELEASE_URL="$API_BASE/repos/$REPO/releases/tags/$WANT_VERSION"
else
    RELEASE_URL="$API_BASE/repos/$REPO/releases/latest"
fi
gh_get "$RELEASE_URL" "$WORK/release.json" "application/vnd.github+json" \
    || die "Release could not be loaded (do repo/version exist? Private repo: set GITHUB_TOKEN)."

mapfile -t REL < <(php -r '
    $r = json_decode(file_get_contents($argv[1]), true);
    if (!is_array($r) || empty($r["tag_name"])) { fwrite(STDERR, "GitHub response unreadable\n"); exit(1); }
    $zip = ""; $sha = "";
    foreach (($r["assets"] ?? []) as $a) {
        if (preg_match("/^floiofficial-teamverwaltung-[0-9][0-9A-Za-z.+-]*\.zip$/", (string) $a["name"])) { $zip = (string) $a["url"]; }
        if (preg_match("/^floiofficial-teamverwaltung-[0-9][0-9A-Za-z.+-]*\.zip\.sha256$/", (string) $a["name"])) { $sha = (string) $a["url"]; }
    }
    echo $r["tag_name"], "\n", $zip, "\n", $sha, "\n";
' "$WORK/release.json") || die "Release response could not be parsed."
TAG="${REL[0]:-}"; ZIP_URL="${REL[1]:-}"; SHA_URL="${REL[2]:-}"

[[ "$TAG" =~ ^v[0-9]+(\.[0-9]+){2}([-+][0-9A-Za-z.-]+)?$ ]] || die "Unexpected release name '$TAG'."
REMOTE_VERSION="${TAG#v}"
[ -n "$ZIP_URL" ] || die "Release $TAG contains no zip (floiofficial-teamverwaltung-*.zip)."
[ -n "$SHA_URL" ] || die "Release $TAG contains no checksum (.sha256) - not installing for safety reasons."
case "$ZIP_URL$SHA_URL" in *"$API_BASE"/*"$API_BASE"/*) ;; *) die "Download URLs do not point to $API_BASE - aborting." ;; esac

# ------------------------------------------------------------------ version comparison
if [ "$REMOTE_VERSION" = "$INSTALLED_VERSION" ] && [ "$FORCE" -ne 1 ]; then
    say "Already up to date (version $INSTALLED_VERSION)."
    exit 0
fi
NEWEST="$(printf '%s\n%s\n' "$INSTALLED_VERSION" "$REMOTE_VERSION" | sort -V | tail -n 1)"
if [ "$NEWEST" = "$INSTALLED_VERSION" ] && [ "$REMOTE_VERSION" != "$INSTALLED_VERSION" ] && [ "$FORCE" -ne 1 ]; then
    die "The version on GitHub ($REMOTE_VERSION) is older than the installed one ($INSTALLED_VERSION). Use --force to install anyway."
fi

# ---------------------------------------------------------------- download + verify
say "Downloading $TAG"
gh_get "$ZIP_URL" "$WORK/package.zip" "application/octet-stream" || die "Zip could not be downloaded."
gh_get "$SHA_URL" "$WORK/package.sha256" "application/octet-stream" || die "Checksum could not be downloaded."

EXPECTED="$(awk 'NR==1{print tolower($1)}' "$WORK/package.sha256")"
ACTUAL="$(sha256sum "$WORK/package.zip" | awk '{print $1}')"
[[ "$EXPECTED" =~ ^[0-9a-f]{64}$ ]] || die "Checksum file is invalid."
[ "$EXPECTED" = "$ACTUAL" ] || die "CHECKSUM MISMATCH (expected $EXPECTED, got $ACTUAL). Nothing installed."
say "Checksum OK ($ACTUAL)"

if unzip -Z1 "$WORK/package.zip" | grep -Eq '(^/|(^|/)\.\.(/|$))'; then
    die "The zip contains unsafe paths - aborting."
fi
mkdir "$WORK/new"
unzip -q "$WORK/package.zip" -d "$WORK/new"
PKG="$WORK/new/floiofficial-teamverwaltung"
NEW_EXT="$PKG/extension/team-roles"
[ -f "$NEW_EXT/extension.json" ] && [ -f "$NEW_EXT/dist/client.js" ] && [ -f "$NEW_EXT/src/TeamRolesProvider.php" ] \
    || die "The package is incomplete (extension/team-roles is missing or incomplete)."
[ "$(manifest_id "$NEW_EXT/extension.json")" = "team-roles" ] || die "The package is not a team-roles extension."
[ "$(manifest_version "$NEW_EXT/extension.json")" = "$REMOTE_VERSION" ] \
    || die "Version in the package ($(manifest_version "$NEW_EXT/extension.json")) does not match the release tag ($TAG)."

# --------------------------------------------------------------------- confirm + backup
echo
say "Update: $INSTALLED_VERSION  ->  $REMOTE_VERSION"
say "Changes: https://github.com/$REPO/releases/tag/$TAG"
warn "Tip: take a database backup first. New migrations run automatically when the extension is enabled."
confirm "Update now?"

STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="$SCRIPT_DIR/backups/extension-$INSTALLED_VERSION-$STAMP"
mkdir -p "$BACKUP"
cp -a "$EXT_DIR/team-roles" "$BACKUP/team-roles"
say "Old version backed up: $BACKUP"
tool snapshot "$SCRIPT_DIR/backups/admins-before-update-$STAMP.csv" || warn "Admin backup not possible - continuing."

# --------------------------------------------------------------------------- install
say "Installing $REMOTE_VERSION (the permission check stays active)"
if ! artisan p:extension:install "$NEW_EXT"; then
    warn "Installation failed. The panel restores the previous version itself."
    artisan p:extension:list || true
    warn "Check with: php artisan p:extension:list   -   If needed: bash update.sh --rollback"
    exit 1
fi
finish_install "$REMOTE_VERSION"

# ------------------------------------------------------- update local package files
say "Updating local scripts and files"
for item in extension tools tests; do
    rm -rf "${SCRIPT_DIR:?}/$item"
    cp -a "$PKG/$item" "$SCRIPT_DIR/$item"
done
for file in install.sh uninstall.sh diagnose.sh README.md README.de.md; do
    [ -f "$PKG/$file" ] && cp -f "$PKG/$file" "$SCRIPT_DIR/$file" || true
done
printf '%s\n' "$REPO" > "$SCRIPT_DIR/.update-repo"

echo
say "Done. Reload the page with Ctrl+F5 and look in the admin sidebar for \"Team management\" and \"Support tickets\". Not there? Run: sudo bash $SCRIPT_DIR/diagnose.sh"
say "Back to the old version: sudo bash $SCRIPT_DIR/update.sh --rollback"
echo "(by floiofficial)"

# Replace this script itself last and atomically (the running bash keeps the old file), then exit immediately.
if [ -f "$PKG/update.sh" ] && ! cmp -s "$PKG/update.sh" "$SCRIPT_DIR/update.sh"; then
    cp -f "$PKG/update.sh" "$SCRIPT_DIR/.update.sh.new" && chmod +x "$SCRIPT_DIR/.update.sh.new" \
        && mv -f "$SCRIPT_DIR/.update.sh.new" "$SCRIPT_DIR/update.sh"
fi
exit 0
