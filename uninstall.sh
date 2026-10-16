#!/usr/bin/env bash
# Teamverwaltung (team-roles) fuer Pterodactyl 2.0 - Deinstaller
# von floiofficial
#
# Aufruf:  sudo bash uninstall.sh [PANEL_VERZEICHNIS] [--yes] [--keep-data] [--web-user www-data]
#   --keep-data   Tabellen ext_team_roles_* behalten (z.B. fuer ein Update)
#
# WICHTIG - die Reihenfolge ist Absicht:
#   1. ZUERST verlieren alle Nicht-Owner den Admin-Status (sonst waeren Moderatoren/Administratoren
#      nach dem Entfernen der Extension ploetzlich VOLLE Admins, weil die Einschraenkung wegfaellt).
#   2. Dann wird die Extension deaktiviert und entfernt.
#   3. Dann werden die eigenen Tabellen geloescht.
# Owner bleiben Admin. Der Panel-Core wurde nie veraendert, es gibt also nichts zurueckzubauen.
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
        --web-user) shift; WEB_USER="${1:?--web-user braucht einen Namen}" ;;
        -h|--help) sed -n '2,14p' "$0"; exit 0 ;;
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

command -v php >/dev/null || die "php nicht gefunden."
[ -f "$PANEL_DIR/artisan" ] || die "Kein Panel unter $PANEL_DIR (artisan fehlt). Pfad als Argument angeben."

say "Das passiert beim Entfernen:"
tool demote-staff --dry-run || die "Konnte die Konten nicht pruefen - Abbruch, damit niemand versehentlich Voll-Admin wird."
echo
if [ "$ASSUME_YES" -ne 1 ]; then
    read -r -p "Teamverwaltung jetzt entfernen? [j/N] " reply
    case "$reply" in j|J|y|Y|ja|Ja) ;; *) echo "Abgebrochen. Es wurde nichts veraendert."; exit 0 ;; esac
fi

# 1. Sicherheit zuerst: Nicht-Owner herabstufen. Scheitert das, wird NICHT weiter entfernt.
say "1/4 Stufe alle Nicht-Owner herab"
tool demote-staff || die "Herabstufen fehlgeschlagen - Extension bleibt installiert, damit die Einschraenkungen weiter gelten."

# 2. Extension deaktivieren und entfernen (nur wenn sie registriert ist)
say "2/4 Deaktiviere und entferne Extension"
LISTED="$(artisan p:extension:list 2>/dev/null || true)"
if grep -q 'team-roles' <<<"$LISTED"; then
    artisan p:extension:disable team-roles || warn "disable meldete einen Fehler (evtl. schon deaktiviert)."
    # "p:extension:remove" fragt nach (yes/no, Standard no): je nach Panel-Version --force/--yes nutzen, sonst "yes" antworten.
    REMOVE_HELP="$(artisan p:extension:remove --help 2>&1 || true)"
    if grep -q -- '--force' <<<"$REMOVE_HELP"; then
        artisan p:extension:remove team-roles --force || warn "remove meldete einen Fehler."
    elif grep -q -- '--yes' <<<"$REMOVE_HELP"; then
        artisan p:extension:remove team-roles --yes || warn "remove meldete einen Fehler."
    else
        printf 'yes\n' | artisan p:extension:remove team-roles || warn "remove meldete einen Fehler."
    fi
    if grep -q 'team-roles' <<<"$(artisan p:extension:list 2>/dev/null || true)"; then
        warn "Die Extension steht noch in der Liste. Bitte von Hand: php artisan p:extension:remove team-roles"
    fi
else
    say "Extension ist nicht (mehr) registriert - nichts zu entfernen."
fi

# 3. Eigene Tabellen loeschen
if [ "$KEEP_DATA" -eq 1 ]; then
    say "3/4 Tabellen bleiben erhalten (--keep-data)"
else
    say "3/4 Loesche eigene Tabellen (ext_team_roles_*)"
    tool drop-tables || warn "Tabellen konnten nicht geloescht werden - spaeter: php tools/team-tool.php $PANEL_DIR drop-tables"
fi

# 4. Aufraeumen
say "4/4 Caches leeren, Queue neu starten"
artisan optimize:clear >/dev/null 2>&1 || true
artisan queue:restart  >/dev/null 2>&1 || true
if [ "$(id -u)" -eq 0 ] && id "$WEB_USER" >/dev/null 2>&1; then
    for p in extensions public/assets/extensions storage bootstrap/cache; do
        [ -e "$PANEL_DIR/$p" ] && chown -R "$WEB_USER:$WEB_USER" "$PANEL_DIR/$p"
    done
fi

echo
say "Ergebnis:"
tool status || true
echo
say "Fertig. Das Panel ist wieder wie vorher (Moderatoren/Administratoren sind normale Benutzer, Owner sind Admins)."
say "Seite im Browser neu laden. Wer wieder Admin sein soll: Admin -> Users -> Benutzer bearbeiten."
echo "(von floiofficial)"
