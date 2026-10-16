#!/usr/bin/env bash
# Teamverwaltung (team-roles) fuer Pterodactyl 2.0 - Installer
# von floiofficial
#
# Aufruf:  sudo bash install.sh [PANEL_VERZEICHNIS] [--yes] [--web-user www-data]
# Beispiel: sudo bash install.sh /var/www/pterodactyl
#
# Was passiert: Sicherung der aktuellen Admin-Konten -> Extension installieren und aktivieren.
# Der Panel-Core wird NICHT veraendert. Rueckgaengig: sudo bash uninstall.sh
set -Eeuo pipefail   # -E: der ERR-Trap (automatisches Zurueckrollen) gilt auch in Funktionen

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PANEL_DIR="/var/www/pterodactyl"
WEB_USER="www-data"
ASSUME_YES=0
STARTED=0

while [ $# -gt 0 ]; do
    case "$1" in
        --yes|-y) ASSUME_YES=1 ;;
        --web-user) shift; WEB_USER="${1:?--web-user braucht einen Namen}" ;;
        -h|--help) sed -n '2,9p' "$0"; exit 0 ;;
        -*) echo "Unbekannte Option: $1" >&2; exit 2 ;;
        *) PANEL_DIR="$1" ;;
    esac
    shift
done

say()  { printf '\033[1;36m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m!!\033[0m  %s\n' "$*"; }
die()  { printf '\033[1;31mFEHLER:\033[0m %s\n' "$*" >&2; exit 1; }
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
        warn "Mache alles wieder rueckgaengig ..."
        bash "$SCRIPT_DIR/uninstall.sh" "$PANEL_DIR" --yes --web-user "$WEB_USER" || \
            warn "Automatisches Zurueckrollen nicht vollstaendig - bitte 'bash uninstall.sh' manuell ausfuehren."
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
warn "ALLE aktuellen Admins werden zu OWNER (= bisheriges Verhalten, nichts aendert sich fuer sie)."
warn "Danach stufst du sie in Admin -> Teamverwaltung bei Bedarf zu Administrator/Moderator herab."
warn "Moderator/Administrator bekommen intern Admin-Zugang; eingeschraenkt wird er durch diese Extension."
warn "Setzt du PTERODACTYL_EXTENSIONS_ENABLED=false, haengt die Einschraenkung NICHT mehr -> vorher 'php tools/team-tool.php $PANEL_DIR demote-staff'."
if [ "$ASSUME_YES" -ne 1 ]; then
    read -r -p "Fortfahren? [j/N] " reply
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

artisan queue:restart >/dev/null 2>&1 || true
artisan p:extension:list

say "Pruefe Ergebnis"
tool status

echo
say "Fertig. Oeffne im Panel: Admin -> Teamverwaltung (Seite einmal neu laden)."
say "Rolle pro Benutzer: Admin -> Users -> Benutzer -> Reiter 'Rolle'."
say "Rueckgaengig machen: sudo bash $SCRIPT_DIR/uninstall.sh $PANEL_DIR"
echo "(von floiofficial)"
