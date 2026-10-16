#!/usr/bin/env bash
# Teamverwaltung (team-roles) fuer Pterodactyl 2.0 - Menue: Installieren / Deinstallieren / Aktualisieren
# von floiofficial
#
# Ein Befehl per SSH (als root):
#   bash <(curl -fsSL https://raw.githubusercontent.com/floiofficial/peterodactyl-team-roles/main/setup.sh)
#
# Das Skript laedt das neueste GitHub-Release, prueft die SHA-256-Pruefsumme und legt es unter
# /opt/floiofficial-teamverwaltung ab. Danach laufen install.sh / uninstall.sh / update.sh von dort.
# Deinstallieren funktioniert auch ohne Internet, sobald die Dateien einmal dort liegen.
set -Eeuo pipefail

REPO="${TEAMROLES_REPO:-floiofficial/peterodactyl-team-roles}"
API_BASE="${TEAMROLES_API_BASE:-https://api.github.com}"
HOME_DIR="${TEAMROLES_HOME:-/opt/floiofficial-teamverwaltung}"
PANEL_DIR="${PANEL_DIR:-/var/www/pterodactyl}"

WORK_TO_CLEAN=""
trap '[ -n "$WORK_TO_CLEAN" ] && rm -rf "$WORK_TO_CLEAN"; true' EXIT

say()  { printf '\033[1;36m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m!!\033[0m  %s\n' "$*"; }
die()  { printf '\033[1;31mFEHLER:\033[0m %s\n' "$*" >&2; exit 1; }

# Eingaben immer vom Terminal lesen (funktioniert auch bei "curl ... | bash")
ask() {   # $1 = Frage, $2 = Variablenname
    local reply=""
    if [ -r /dev/tty ] && [ -z "${TEAMROLES_STDIN:-}" ]; then read -r -p "$1" reply < /dev/tty || true
    else read -r -p "$1" reply || true; fi
    printf -v "$2" '%s' "$reply"
}

[ "$(id -u)" -eq 0 ] || [ -n "${TEAMROLES_ALLOW_NONROOT:-}" ] || die "Bitte als root ausfuehren (sudo -i, dann den Befehl erneut)."

fetch_release() {   # laedt + prueft das neueste Release nach $HOME_DIR
    for c in curl unzip sha256sum php; do command -v "$c" >/dev/null || die "'$c' fehlt. Installieren mit: apt install -y $c"; done
    [[ "$REPO" =~ ^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$ ]] || die "Repo '$REPO' ist ungueltig."
    local work; work="$(mktemp -d)"
    WORK_TO_CLEAN="$work"
    local auth=()
    if [ -n "${GITHUB_TOKEN:-}" ]; then
        ( umask 077; printf 'Authorization: Bearer %s\n' "$GITHUB_TOKEN" > "$work/auth" )
        auth=(-H "@$work/auth")
    fi
    get() { curl -fsSL --max-time 120 -H "Accept: $3" "${auth[@]}" "$1" -o "$2"; }

    say "Suche das neueste Release ($REPO)"
    get "$API_BASE/repos/$REPO/releases/latest" "$work/rel.json" "application/vnd.github+json" \
        || die "Kein Release gefunden. Gibt es das Repo $REPO und ein Release (Tag v1.0.0)? Privates Repo: GITHUB_TOKEN setzen."
    mapfile -t REL < <(php -r '
        $r = json_decode(file_get_contents($argv[1]), true);
        if (!is_array($r) || empty($r["tag_name"])) { exit(1); }
        $zip = ""; $sha = "";
        foreach (($r["assets"] ?? []) as $a) {
            if (preg_match("/^floiofficial-teamverwaltung-[0-9][0-9A-Za-z.+-]*\.zip$/", (string) $a["name"])) { $zip = (string) $a["url"]; }
            if (preg_match("/^floiofficial-teamverwaltung-[0-9][0-9A-Za-z.+-]*\.zip\.sha256$/", (string) $a["name"])) { $sha = (string) $a["url"]; }
        }
        echo $r["tag_name"], "\n", $zip, "\n", $sha, "\n";' "$work/rel.json") || die "Release-Antwort nicht lesbar."
    local tag="${REL[0]:-}" zip_url="${REL[1]:-}" sha_url="${REL[2]:-}"
    [[ "$tag" =~ ^v[0-9]+(\.[0-9]+){2}([-+][0-9A-Za-z.-]+)?$ ]] || die "Unerwarteter Release-Name '$tag'."
    [ -n "$zip_url" ] && [ -n "$sha_url" ] || die "Das Release $tag hat kein Zip oder keine Pruefsumme - nichts installiert."

    say "Lade $tag"
    get "$zip_url" "$work/pkg.zip" "application/octet-stream" || die "Download fehlgeschlagen."
    get "$sha_url" "$work/pkg.sha256" "application/octet-stream" || die "Pruefsumme konnte nicht geladen werden."
    local expected actual
    expected="$(awk 'NR==1{print tolower($1)}' "$work/pkg.sha256")"
    actual="$(sha256sum "$work/pkg.zip" | awk '{print $1}')"
    [[ "$expected" =~ ^[0-9a-f]{64}$ ]] || die "Pruefsummen-Datei ungueltig."
    [ "$expected" = "$actual" ] || die "PRUEFSUMME STIMMT NICHT - Abbruch, nichts installiert."
    say "Pruefsumme OK"
    if unzip -Z1 "$work/pkg.zip" | grep -Eq '(^/|(^|/)\.\.(/|$))'; then die "Zip enthaelt unsichere Pfade - Abbruch."; fi

    mkdir "$work/x"; unzip -q "$work/pkg.zip" -d "$work/x"
    local pkg="$work/x/floiofficial-teamverwaltung"
    [ -f "$pkg/install.sh" ] && [ -f "$pkg/uninstall.sh" ] && [ -f "$pkg/extension/team-roles/extension.json" ] || die "Paket unvollstaendig."

    mkdir -p "$HOME_DIR/backups"          # backups/ bleibt bei Aktualisierungen erhalten
    for item in extension tools tests .github; do rm -rf "${HOME_DIR:?}/$item"; [ -e "$pkg/$item" ] && cp -a "$pkg/$item" "$HOME_DIR/$item"; done
    for f in install.sh uninstall.sh update.sh setup.sh README.md; do [ -f "$pkg/$f" ] && cp -f "$pkg/$f" "$HOME_DIR/$f"; done
    chmod +x "$HOME_DIR"/*.sh
    printf '%s\n' "$REPO" > "$HOME_DIR/.update-repo"
    say "Dateien liegen in $HOME_DIR"
}

ask_panel() {
    local p=""
    ask "Pfad zum Panel [$PANEL_DIR]: " p
    [ -n "$p" ] && PANEL_DIR="$p"
    [ -f "$PANEL_DIR/artisan" ] || die "Unter $PANEL_DIR liegt kein Pterodactyl-Panel (artisan fehlt)."
}

do_install() {
    ask_panel
    fetch_release
    say "Starte die Installation"
    bash "$HOME_DIR/install.sh" "$PANEL_DIR"
}

do_uninstall() {
    ask_panel
    if [ ! -f "$HOME_DIR/uninstall.sh" ]; then
        warn "Keine lokalen Dateien unter $HOME_DIR - lade das Paket, um zu deinstallieren."
        fetch_release
    fi
    say "Starte die Deinstallation (stuft zuerst alle Nicht-Owner herab, dann wird entfernt)"
    bash "$HOME_DIR/uninstall.sh" "$PANEL_DIR"
}

do_update() {
    ask_panel
    if [ ! -f "$HOME_DIR/update.sh" ]; then fetch_release; fi
    bash "$HOME_DIR/update.sh" "$PANEL_DIR" --repo "$REPO"
}

choice="${1:-}"
case "$choice" in install|1) choice=1 ;; uninstall|2) choice=2 ;; update|3) choice=3 ;; "") ;; *) die "Unbekannte Option '$choice' (install | uninstall | update)." ;; esac

if [ -z "$choice" ]; then
    echo
    echo "  Teamverwaltung fuer Pterodactyl 2.0  -  von floiofficial"
    echo "  --------------------------------------------------------"
    echo "   1) Installieren"
    echo "   2) Deinstallieren"
    echo "   3) Aktualisieren"
    echo "   0) Beenden"
    echo
    ask "Auswahl [0-3]: " choice
fi

case "$choice" in
    1) do_install ;;
    2) do_uninstall ;;
    3) do_update ;;
    0|"") echo "Beendet. Es wurde nichts veraendert." ;;
    *) die "Ungueltige Auswahl '$choice'." ;;
esac
