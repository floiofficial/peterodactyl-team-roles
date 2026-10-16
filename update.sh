#!/usr/bin/env bash
# Teamverwaltung (team-roles) fuer Pterodactyl 2.0 - Update aus GitHub
# von floiofficial
#
# Aufruf:
#   sudo bash update.sh [PANEL_VERZEICHNIS] [--repo OWNER/REPO] [--version vX.Y.Z]
#                       [--yes] [--force] [--rollback] [--ext-dir DIR] [--web-user www-data]
#
# Erstes Mal:  sudo bash update.sh /var/www/pterodactyl --repo DEIN-NAME/pterodactyl-team-roles
#              (das Repo wird in .update-repo gemerkt, danach reicht "sudo bash update.sh")
# Privates Repo: vorher  export GITHUB_TOKEN=...  (Token mit Lesezugriff auf Contents)
# Zurueck zur vorherigen Version:  sudo bash update.sh --rollback
#
# Sicherheit:
#   - Es werden nur Releases mit passender SHA-256-Pruefsumme installiert.
#   - Vor dem Update wird die installierte Version nach backups/ kopiert.
#   - Aktualisiert wird per "p:extension:install" ueber die vorhandene Version (der dokumentierte
#     Update-Weg). Es wird NIE "p:extension:remove" benutzt - die Rechtepruefung bleibt also waehrend
#     des Updates aktiv. Schlaegt die Installation fehl, stellt das Panel die alte Version selbst wieder her.
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
        --repo) shift; REPO="${1:?--repo braucht OWNER/REPO}" ;;
        --version) shift; WANT_VERSION="${1:?--version braucht z. B. v1.1.0}" ;;
        --ext-dir) shift; EXT_DIR="${1:?--ext-dir braucht ein Verzeichnis}" ;;
        --web-user) shift; WEB_USER="${1:?--web-user braucht einen Namen}" ;;
        --yes|-y) ASSUME_YES=1 ;;
        --force) FORCE=1 ;;
        --rollback) ROLLBACK=1 ;;
        -h|--help) sed -n '2,19p' "$0"; exit 0 ;;
        -*) echo "Unbekannte Option: $1" >&2; exit 2 ;;
        *) PANEL_DIR="$1" ;;
    esac
    shift
done
[ -n "$EXT_DIR" ] || EXT_DIR="$PANEL_DIR/extensions"

say()  { printf '\033[1;36m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m!!\033[0m  %s\n' "$*"; }
die()  { printf '\033[1;31mFEHLER:\033[0m %s\n' "$*" >&2; exit 1; }
artisan() { (cd "$PANEL_DIR" && php artisan "$@"); }
tool()    { php "$SCRIPT_DIR/tools/team-tool.php" "$PANEL_DIR" "$@"; }

confirm() {
    [ "$ASSUME_YES" -eq 1 ] && return 0
    read -r -p "$1 [j/N] " reply
    case "$reply" in j|J|y|Y|ja|Ja) return 0 ;; *) echo "Abgebrochen. Es wurde nichts veraendert."; exit 0 ;; esac
}

manifest_version() {   # $1 = Pfad zur extension.json
    php -r '$m = json_decode(file_get_contents($argv[1]), true); echo is_array($m) ? ($m["version"] ?? "") : "";' "$1"
}
manifest_id() {
    php -r '$m = json_decode(file_get_contents($argv[1]), true); echo is_array($m) ? ($m["id"] ?? "") : "";' "$1"
}

finish_install() {   # $1 = erwartete Version
    if [ "$(id -u)" -eq 0 ] && id "$WEB_USER" >/dev/null 2>&1; then
        for p in extensions public/assets/extensions storage bootstrap/cache; do
            [ -e "$PANEL_DIR/$p" ] && chown -R "$WEB_USER:$WEB_USER" "$PANEL_DIR/$p"
        done
    else
        warn "Dateirechte bitte pruefen: chown -R www-data:www-data $PANEL_DIR/extensions $PANEL_DIR/public/assets/extensions"
    fi
    artisan queue:restart >/dev/null 2>&1 || true
    artisan p:extension:list || true
    local now
    now="$(manifest_version "$EXT_DIR/team-roles/extension.json" 2>/dev/null || true)"
    if [ "$now" = "$1" ]; then
        say "Installierte Version ist jetzt $now."
    else
        warn "Erwartet wurde Version $1, installiert ist '$now'. Bitte 'p:extension:list' und die Teamverwaltung pruefen."
    fi
}

# ------------------------------------------------------------------ Grundpruefungen
command -v php >/dev/null || die "php nicht gefunden."
[ -f "$PANEL_DIR/artisan" ] || die "Kein Panel unter $PANEL_DIR (artisan fehlt). Pfad als Argument angeben."
INSTALLED_MANIFEST="$EXT_DIR/team-roles/extension.json"
[ -f "$INSTALLED_MANIFEST" ] || die "Teamverwaltung ist hier nicht installiert (fehlt: $INSTALLED_MANIFEST). Erst install.sh ausfuehren (oder --ext-dir angeben)."
INSTALLED_VERSION="$(manifest_version "$INSTALLED_MANIFEST")"
[ -n "$INSTALLED_VERSION" ] || die "Installierte Version nicht lesbar ($INSTALLED_MANIFEST)."

# ------------------------------------------------------------------------- Rollback
if [ "$ROLLBACK" -eq 1 ]; then
    LAST="$(ls -1d "$SCRIPT_DIR"/backups/extension-*/ 2>/dev/null | sort | tail -n 1 || true)"
    [ -n "$LAST" ] && [ -f "${LAST}team-roles/extension.json" ] || die "Keine Sicherung einer frueheren Version in $SCRIPT_DIR/backups gefunden."
    OLD_VERSION="$(manifest_version "${LAST}team-roles/extension.json")"
    say "Rollback: installiert $INSTALLED_VERSION -> zurueck auf $OLD_VERSION (aus $LAST)"
    warn "Datenbank-Migrationen der neueren Version werden nicht zurueckgedreht."
    confirm "Zurueckrollen?"
    artisan p:extension:install "${LAST}team-roles"
    finish_install "$OLD_VERSION"
    say "Fertig. Seite im Browser neu laden."
    exit 0
fi

# ---------------------------------------------------------------------- GitHub-Angaben
command -v curl >/dev/null  || die "curl nicht gefunden."
command -v unzip >/dev/null || die "unzip nicht gefunden."
command -v sha256sum >/dev/null || die "sha256sum nicht gefunden."

if [ -z "$REPO" ] && [ -f "$SCRIPT_DIR/.update-repo" ]; then
    REPO="$(tr -d '[:space:]' < "$SCRIPT_DIR/.update-repo")"
fi
[ -n "$REPO" ] || die "Kein Repo angegeben. Beim ersten Mal: bash update.sh --repo DEIN-NAME/pterodactyl-team-roles"
[[ "$REPO" =~ ^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$ ]] || die "Repo '$REPO' ist ungueltig (erwartet OWNER/REPO)."
if [ -n "$WANT_VERSION" ]; then
    [[ "$WANT_VERSION" =~ ^v[0-9]+(\.[0-9]+){2}([-+][0-9A-Za-z.-]+)?$ ]] || die "Version '$WANT_VERSION' ungueltig (erwartet z. B. v1.1.0)."
fi

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

AUTH_ARGS=()
if [ -n "${GITHUB_TOKEN:-}" ]; then
    # Token per Header-Datei statt auf der Kommandozeile (sonst in "ps" sichtbar)
    ( umask 077; printf 'Authorization: Bearer %s\n' "$GITHUB_TOKEN" > "$WORK/auth" )
    AUTH_ARGS=(-H "@$WORK/auth")
fi

gh_get() {   # $1 = URL, $2 = Zieldatei, $3 = Accept
    curl -fsSL --max-time 120 -H "Accept: $3" -H "X-GitHub-Api-Version: 2022-11-28" "${AUTH_ARGS[@]}" "$1" -o "$2"
}

say "Frage GitHub nach der Version ab ($REPO)"
if [ -n "$WANT_VERSION" ]; then
    RELEASE_URL="$API_BASE/repos/$REPO/releases/tags/$WANT_VERSION"
else
    RELEASE_URL="$API_BASE/repos/$REPO/releases/latest"
fi
gh_get "$RELEASE_URL" "$WORK/release.json" "application/vnd.github+json" \
    || die "Release konnte nicht geladen werden (Repo/Version vorhanden? Privates Repo: GITHUB_TOKEN setzen)."

mapfile -t REL < <(php -r '
    $r = json_decode(file_get_contents($argv[1]), true);
    if (!is_array($r) || empty($r["tag_name"])) { fwrite(STDERR, "Antwort von GitHub unlesbar\n"); exit(1); }
    $zip = ""; $sha = "";
    foreach (($r["assets"] ?? []) as $a) {
        if (preg_match("/^floiofficial-teamverwaltung-[0-9][0-9A-Za-z.+-]*\.zip$/", (string) $a["name"])) { $zip = (string) $a["url"]; }
        if (preg_match("/^floiofficial-teamverwaltung-[0-9][0-9A-Za-z.+-]*\.zip\.sha256$/", (string) $a["name"])) { $sha = (string) $a["url"]; }
    }
    echo $r["tag_name"], "\n", $zip, "\n", $sha, "\n";
' "$WORK/release.json") || die "Release-Antwort nicht auswertbar."
TAG="${REL[0]:-}"; ZIP_URL="${REL[1]:-}"; SHA_URL="${REL[2]:-}"

[[ "$TAG" =~ ^v[0-9]+(\.[0-9]+){2}([-+][0-9A-Za-z.-]+)?$ ]] || die "Unerwarteter Release-Name '$TAG'."
REMOTE_VERSION="${TAG#v}"
[ -n "$ZIP_URL" ] || die "Das Release $TAG enthaelt kein Zip (floiofficial-teamverwaltung-*.zip)."
[ -n "$SHA_URL" ] || die "Das Release $TAG enthaelt keine Pruefsumme (.sha256) - aus Sicherheitsgruenden wird nicht installiert."
case "$ZIP_URL$SHA_URL" in *"$API_BASE"/*"$API_BASE"/*) ;; *) die "Download-Adressen zeigen nicht auf $API_BASE - Abbruch." ;; esac

# ------------------------------------------------------------------ Versionsvergleich
if [ "$REMOTE_VERSION" = "$INSTALLED_VERSION" ] && [ "$FORCE" -ne 1 ]; then
    say "Bereits aktuell (Version $INSTALLED_VERSION)."
    exit 0
fi
NEWEST="$(printf '%s\n%s\n' "$INSTALLED_VERSION" "$REMOTE_VERSION" | sort -V | tail -n 1)"
if [ "$NEWEST" = "$INSTALLED_VERSION" ] && [ "$REMOTE_VERSION" != "$INSTALLED_VERSION" ] && [ "$FORCE" -ne 1 ]; then
    die "Die Version auf GitHub ($REMOTE_VERSION) ist aelter als die installierte ($INSTALLED_VERSION). Mit --force trotzdem."
fi

# ---------------------------------------------------------------- Herunterladen + pruefen
say "Lade $TAG herunter"
gh_get "$ZIP_URL" "$WORK/package.zip" "application/octet-stream" || die "Zip konnte nicht geladen werden."
gh_get "$SHA_URL" "$WORK/package.sha256" "application/octet-stream" || die "Pruefsumme konnte nicht geladen werden."

EXPECTED="$(awk 'NR==1{print tolower($1)}' "$WORK/package.sha256")"
ACTUAL="$(sha256sum "$WORK/package.zip" | awk '{print $1}')"
[[ "$EXPECTED" =~ ^[0-9a-f]{64}$ ]] || die "Pruefsummen-Datei ist ungueltig."
[ "$EXPECTED" = "$ACTUAL" ] || die "PRUEFSUMME STIMMT NICHT (erwartet $EXPECTED, erhalten $ACTUAL). Nichts installiert."
say "Pruefsumme OK ($ACTUAL)"

if unzip -Z1 "$WORK/package.zip" | grep -Eq '(^/|(^|/)\.\.(/|$))'; then
    die "Das Zip enthaelt unsichere Pfade - Abbruch."
fi
mkdir "$WORK/new"
unzip -q "$WORK/package.zip" -d "$WORK/new"
PKG="$WORK/new/floiofficial-teamverwaltung"
NEW_EXT="$PKG/extension/team-roles"
[ -f "$NEW_EXT/extension.json" ] && [ -f "$NEW_EXT/dist/client.js" ] && [ -f "$NEW_EXT/src/TeamRolesProvider.php" ] \
    || die "Das Paket ist unvollstaendig (extension/team-roles fehlt oder ist unvollstaendig)."
[ "$(manifest_id "$NEW_EXT/extension.json")" = "team-roles" ] || die "Das Paket ist keine team-roles-Extension."
[ "$(manifest_version "$NEW_EXT/extension.json")" = "$REMOTE_VERSION" ] \
    || die "Version im Paket ($(manifest_version "$NEW_EXT/extension.json")) passt nicht zum Release-Tag ($TAG)."

# --------------------------------------------------------------------- Bestaetigen + Sicherung
echo
say "Update: $INSTALLED_VERSION  ->  $REMOTE_VERSION"
say "Aenderungen: https://github.com/$REPO/releases/tag/$TAG"
warn "Tipp: vorher ein Datenbank-Backup machen. Neue Migrationen laufen beim Aktivieren automatisch."
confirm "Jetzt aktualisieren?"

STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="$SCRIPT_DIR/backups/extension-$INSTALLED_VERSION-$STAMP"
mkdir -p "$BACKUP"
cp -a "$EXT_DIR/team-roles" "$BACKUP/team-roles"
say "Alte Version gesichert: $BACKUP"
tool snapshot "$SCRIPT_DIR/backups/admins-vor-update-$STAMP.csv" || warn "Admin-Sicherung nicht moeglich - mache ich weiter."

# --------------------------------------------------------------------------- Installieren
say "Installiere $REMOTE_VERSION (die Rechtepruefung bleibt dabei aktiv)"
if ! artisan p:extension:install "$NEW_EXT"; then
    warn "Installation fehlgeschlagen. Das Panel stellt die vorherige Version selbst wieder her."
    artisan p:extension:list || true
    warn "Pruefe mit: php artisan p:extension:list   -   Notfalls: bash update.sh --rollback"
    exit 1
fi
finish_install "$REMOTE_VERSION"

# ------------------------------------------------------- lokale Paketdateien aktualisieren
say "Aktualisiere lokale Skripte und Dateien"
for item in extension tools tests; do
    rm -rf "${SCRIPT_DIR:?}/$item"
    cp -a "$PKG/$item" "$SCRIPT_DIR/$item"
done
for file in install.sh uninstall.sh README.md; do
    [ -f "$PKG/$file" ] && cp -f "$PKG/$file" "$SCRIPT_DIR/$file"
done
printf '%s\n' "$REPO" > "$SCRIPT_DIR/.update-repo"

echo
say "Fertig. Seite im Browser neu laden und kurz in Admin -> Teamverwaltung nachsehen (\"Rechtepruefung ist aktiv\")."
say "Zurueck zur alten Version: sudo bash $SCRIPT_DIR/update.sh --rollback"
echo "(von floiofficial)"

# Dieses Skript selbst zuletzt und atomar ersetzen (laufende Bash behaelt die alte Datei), dann sofort beenden.
if [ -f "$PKG/update.sh" ] && ! cmp -s "$PKG/update.sh" "$SCRIPT_DIR/update.sh"; then
    cp -f "$PKG/update.sh" "$SCRIPT_DIR/.update.sh.new" && chmod +x "$SCRIPT_DIR/.update.sh.new" \
        && mv -f "$SCRIPT_DIR/.update.sh.new" "$SCRIPT_DIR/update.sh"
fi
exit 0
