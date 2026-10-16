#!/usr/bin/env bash
# Teamverwaltung (team-roles) fuer Pterodactyl 2.0 - Installer
# von floiofficial
#
# Aufruf:  sudo bash install.sh [PANEL_VERZEICHNIS] [--yes] [--web-user www-data]
#                               [--owner EMAIL[,EMAIL2]] [--others administrator|moderator|friend|user|owner]
# Beispiel: sudo bash install.sh /var/www/pterodactyl --owner ich@example.com
#
# --owner   E-Mail des Panel-Kontos, das Owner wird (mehrere mit Komma). Ohne --owner fragt der Installer;
#           leere Eingabe (bzw. --yes ohne --owner) = alle bisherigen Admins bleiben Owner (altes Verhalten).
# --others  Was die uebrigen bisherigen Admins werden (Standard: administrator).
#
# Was passiert: Sicherung der aktuellen Admin-Konten -> Extension installieren und aktivieren.
# Der Panel-Core wird NICHT veraendert. Rueckgaengig: sudo bash uninstall.sh
set -Eeuo pipefail   # -E: der ERR-Trap (automatisches Zurueckrollen) gilt auch in Funktionen

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
        --web-user) shift; WEB_USER="${1:?--web-user braucht einen Namen}" ;;
        --owner) shift; OWNER_EMAILS="${OWNER_EMAILS:+$OWNER_EMAILS,}${1:?--owner braucht eine E-Mail-Adresse}" ;;
        --others) shift; OTHERS_SLUG="${1:?--others braucht eine Rolle}" ;;
        -h|--help) sed -n '2,14p' "$0"; exit 0 ;;
        -*) echo "Unbekannte Option: $1" >&2; exit 2 ;;
        *) PANEL_DIR="$1" ;;
    esac
    shift
done

say()  { printf '\033[1;36m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m!!\033[0m  %s\n' "$*"; }
die()  { printf '\033[1;31mFEHLER:\033[0m %s\n' "$*" >&2; exit 1; }
# Eingaben vom Terminal lesen (auch bei "curl ... | bash"); TEAMROLES_STDIN=1 erzwingt stdin (Tests)
ask() {   # $1 = Frage, $2 = Variablenname
    local __ans=""
    if [ -r /dev/tty ] && [ -z "${TEAMROLES_STDIN:-}" ]; then read -r -p "$1" __ans < /dev/tty || true
    else read -r -p "$1" __ans || true; fi
    printf -v "$2" '%s' "$__ans"
}
artisan() { (cd "$PANEL_DIR" && php artisan "$@"); }
tool()    { php "$SCRIPT_DIR/tools/team-tool.php" "$PANEL_DIR" "$@"; }

# Mit set -E feuert der ERR-Trap auch in der Unter-Shell von artisan() UND danach im Hauptprozess.
# Die Sperre (atomares rmdir) sorgt dafuer, dass das Zurueckrollen genau einmal laeuft.
ROLLBACK_LOCK="$(mktemp -d)"
trap 'rmdir "$ROLLBACK_LOCK" 2>/dev/null || true' EXIT

on_error() {
    local code=$?
    rmdir "$ROLLBACK_LOCK" 2>/dev/null || exit "$code"
    warn "Installation fehlgeschlagen (Code $code)."
    if [ "$STARTED" -eq 1 ]; then
        # Nur zurueckrollen, wenn wirklich etwas da ist (Extension registriert oder Tabellen angelegt).
        local listed status_out
        listed="$(artisan p:extension:list 2>/dev/null || true)"
        status_out="$(tool status 2>/dev/null || true)"
        if grep -q 'team-roles' <<<"$listed" || ! grep -q 'Tabellen nicht vorhanden' <<<"$status_out"; then
            warn "Mache alles wieder rueckgaengig ..."
            bash "$SCRIPT_DIR/uninstall.sh" "$PANEL_DIR" --yes --web-user "$WEB_USER" || \
                warn "Automatisches Zurueckrollen nicht vollstaendig - bitte 'bash uninstall.sh' manuell ausfuehren."
        else
            warn "Es wurde nichts installiert - es gibt nichts zurueckzurollen. Am Panel wurde nichts veraendert."
        fi
    fi
    exit "$code"
}
trap on_error ERR

# ---------------------------------------------------------------- Pruefungen
say "Pruefe Umgebung ($PANEL_DIR)"
command -v php >/dev/null || die "php nicht gefunden."
[ -f "$PANEL_DIR/artisan" ] || die "Kein Panel unter $PANEL_DIR (artisan fehlt). Pfad als Argument angeben."
[ -d "$SCRIPT_DIR/extension/team-roles" ] || die "Ordner extension/team-roles fehlt neben install.sh."

COMMANDS="$(artisan list 2>/dev/null || true)"
grep -q 'p:extension:install' <<<"$COMMANDS" \
    || die "Dieses Panel kennt keine Extensions (p:extension:install fehlt). Teamverwaltung braucht Pterodactyl 2.0 (Beta)."

INSTALLED="$(artisan p:extension:list 2>/dev/null || true)"
if grep -q 'team-roles' <<<"$INSTALLED"; then
    die "'team-roles' ist bereits installiert. Zum Aktualisieren erst 'bash uninstall.sh --keep-data' ausfuehren."
fi

tool check

# ------------------------------------------------------------ Hinweis + Bestaetigung
say "Aktuelle Admins:"
tool status
echo
case "$OTHERS_SLUG" in ""|owner|administrator|moderator|friend|user) ;; *) die "--others '$OTHERS_SLUG' ist ungueltig (administrator|moderator|friend|user|owner)." ;; esac

# ------------------------------------------------------------ Owner auswaehlen
OTHERS_COUNT=0
resolve_owners() {   # $1 = E-Mail-Liste; setzt RESOLVE_OUT/OTHERS_COUNT, Rueckgabe 0 = alle gefunden
    local raw
    if ! raw="$(tool resolve-owners "$1" 2>&1)"; then RESOLVE_OUT="$raw"; return 1; fi
    OTHERS_COUNT="$(sed -n 's/^OTHERS=//p' <<<"$raw")"
    RESOLVE_OUT="$(sed '/^OTHERS=/d' <<<"$raw")"
    return 0
}

if [ -n "$OWNER_EMAILS" ]; then
    resolve_owners "$OWNER_EMAILS" || die "Owner nicht gefunden: $RESOLVE_OUT"
elif [ "$ASSUME_YES" -ne 1 ]; then
    echo "Wer soll Owner werden? Gib die E-Mail-Adresse ein, die das Konto im Panel hat"
    echo "(mehrere mit Komma). Nur Owner duerfen alles. Leer lassen = ALLE bisherigen Admins bleiben Owner."
    while true; do
        ask "Owner-E-Mail: " OWNER_EMAILS
        [ -z "$OWNER_EMAILS" ] && break
        if resolve_owners "$OWNER_EMAILS"; then break; fi
        warn "$RESOLVE_OUT"
        warn "Bitte erneut eingeben (oder leer lassen)."
    done
fi

if [ -n "$OWNER_EMAILS" ]; then
    say "Diese Konten werden Owner:"
    echo "$RESOLVE_OUT"
    if [ "${OTHERS_COUNT:-0}" -gt 0 ] && [ -z "$OTHERS_SLUG" ]; then
        if [ "$ASSUME_YES" -eq 1 ]; then
            OTHERS_SLUG="administrator"
        else
            echo
            echo "Es gibt noch $OTHERS_COUNT weitere(n) bisherige(n) Admin(s). Was sollen sie werden?"
            echo "  1) Administrator (Standard - Admin-Zugang, aber ohne API-Schluessel/Einstellungen aendern)"
            echo "  2) Moderator (nur ansehen)"
            echo "  3) Benutzer (kein Admin-Zugang mehr)"
            echo "  4) Owner (wie bisher)"
            ask "Auswahl [1-4, Enter = 1]: " sel
            case "${sel:-1}" in 2) OTHERS_SLUG=moderator ;; 3) OTHERS_SLUG=user ;; 4) OTHERS_SLUG=owner ;; *) OTHERS_SLUG=administrator ;; esac
        fi
    fi
    [ -n "$OTHERS_SLUG" ] || OTHERS_SLUG="administrator"
fi

echo
if [ -n "$OWNER_EMAILS" ]; then
    warn "Nur die oben genannten Konten werden Owner. Die uebrigen bisherigen Admins ($OTHERS_COUNT) werden: $OTHERS_SLUG."
else
    warn "ALLE aktuellen Admins werden zu OWNER (= bisheriges Verhalten, nichts aendert sich fuer sie)."
    warn "Danach stufst du sie in Admin -> Teamverwaltung bei Bedarf zu Administrator/Moderator herab."
fi
warn "Moderator/Administrator bekommen intern Admin-Zugang; eingeschraenkt wird er durch diese Extension."
warn "Setzt du PTERODACTYL_EXTENSIONS_ENABLED=false, haengt die Einschraenkung NICHT mehr -> vorher 'php tools/team-tool.php $PANEL_DIR demote-staff'."
if [ "$ASSUME_YES" -ne 1 ]; then
    ask "Fortfahren? [j/N] " reply
    case "$reply" in j|J|y|Y|ja|Ja) ;; *) echo "Abgebrochen. Es wurde nichts veraendert."; exit 0 ;; esac
fi

# ------------------------------------------------------------------ Sicherung
mkdir -p "$SCRIPT_DIR/backups"
BACKUP="$SCRIPT_DIR/backups/admins-vor-installation-$(date +%Y%m%d-%H%M%S).csv"
say "Sichere Admin-Konten nach $BACKUP"
tool snapshot "$BACKUP"
warn "Empfehlung: zusaetzlich ein Datenbank-Backup (mysqldump) - die Extension legt nur 2 eigene Tabellen an."

# ---------------------------------------------------------------- Installation
STARTED=1
say "Installiere und aktiviere Extension"
artisan p:extension:install "$SCRIPT_DIR/extension/team-roles" --enable

if [ "$(id -u)" -eq 0 ] && id "$WEB_USER" >/dev/null 2>&1; then
    say "Setze Dateirechte fuer $WEB_USER"
    for p in extensions public/assets/extensions storage bootstrap/cache; do
        [ -e "$PANEL_DIR/$p" ] && chown -R "$WEB_USER:$WEB_USER" "$PANEL_DIR/$p"
    done
else
    warn "Nicht als root gestartet bzw. Benutzer '$WEB_USER' fehlt - Dateirechte bitte selbst setzen:"
    warn "chown -R www-data:www-data $PANEL_DIR/extensions $PANEL_DIR/public/assets/extensions $PANEL_DIR/storage $PANEL_DIR/bootstrap/cache"
fi

if [ -n "$OWNER_EMAILS" ]; then
    say "Setze Owner und Rollen der uebrigen Admins"
    tool set-owners "$OWNER_EMAILS" "--others=$OTHERS_SLUG"
fi

artisan queue:restart >/dev/null 2>&1 || true
artisan p:extension:list

say "Pruefe Ergebnis"
tool status

echo
say "Fertig. Oeffne im Panel: Admin -> Teamverwaltung (Seite einmal neu laden)."
say "Rolle pro Benutzer: Admin -> Users -> Benutzer -> Reiter 'Rolle'."
say "Rueckgaengig machen: sudo bash $SCRIPT_DIR/uninstall.sh $PANEL_DIR"
echo "(von floiofficial)"
