# Teamverwaltung für Pterodactyl 2.0

**von floiofficial**

Rollen **Owner · Administrator · Moderator · Freund · Benutzer/Kunde** mit einstellbaren Rechten.
Der Owner hat immer alles. Bei Administrator und Moderator (und optional Freund) wählst du per
Häkchen-Matrix, was die Person darf.

Es wird **kein Panel-Code verändert**. Das Ganze ist eine normale Pterodactyl-2.0-**Extension**
(`team-roles`) und lässt sich jederzeit wieder entfernen.

---

## ⚠️ Zuerst lesen

1. **Pterodactyl 2.0 ist noch Beta** (aktuell `v2.0.0-beta.2`). Das Paket wurde nach der offiziellen
   Extension-Doku und dem Panel-Code (`bootstrap/app.php`) gebaut, aber **nicht gegen ein echtes 2.0-Panel
   ausprobiert**. Installiere es **zuerst auf einem Test-Panel** (oder einer Kopie), nicht direkt auf dem
   Live-System. Siehe „Was geprüft wurde“ unten.
2. **Mach vorher ein Datenbank-Backup.** Die Extension legt nur zwei eigene Tabellen an, ändert aber
   `users.root_admin` (siehe „Wie es funktioniert“).

---

## Installieren

```bash
# auf dem Server, im entpackten Ordner
sudo bash install.sh /var/www/pterodactyl
```

Der Installer prüft alles, zeigt die aktuellen Admins, fragt nach Bestätigung, sichert die Admin-Konten
nach `backups/` und installiert + aktiviert die Extension. **Schlägt etwas fehl, rollt er automatisch
zurück.**

> **Alle bisherigen Admins werden zu Owner** — für sie ändert sich also nichts. Danach stufst du in der
> Teamverwaltung herab, wen du willst.

Optionen: `--yes` (ohne Rückfrage), `--web-user www-data`. Docker: Befehle im Container ausführen und
`/app` als Panel-Pfad angeben (`/app/extensions` muss ein Volume sein).

Danach im Panel die Seite neu laden.

## Benutzen

* **Admin → Teamverwaltung**
  * **Rollen & Rechte:** Rolle wählen → Häkchen setzen → *Rechte speichern*. Pro Bereich (Benutzer, Server,
    Nodes, …) gibt es *Ansehen / Erstellen·Aktionen / Bearbeiten / Löschen*.
  * **Mitglieder:** Suche, Filter, Rolle pro Person per Auswahlfeld ändern.
* **Admin → Users → [Benutzer] → Reiter „Rolle“:** Rolle für genau diese Person einstellen
  (User/Kunde, Freund, Moderator, Administrator, Owner). Im Kopf der Benutzerseite steht ein Rollen-Chip.

### Standard-Rechte

| Rolle | Admin-Zugang | Standard |
|---|---|---|
| Owner | alles | nicht einstellbar, immer alle Rechte |
| Administrator | ja | fast alles; Einstellungen nur ansehen, keine API-Schlüssel |
| Moderator | ja | nur **Ansehen** (Benutzer, Server, Nodes, Locations, Eggs, Mounts, DB-Hosts, Tags, Aktivität) + Server-Zugriff ansehen |
| Freund | nein | keine Rechte (kannst du ändern) |
| Benutzer / Kunde | nein | keine Rechte |

Sobald eine Rolle **mindestens ein Recht** hat, bekommt sie Zugang zum Admin-Bereich — aber nur im Rahmen
der Häkchen.

### Was nur der Owner darf (nicht vergebbar)

* **Extensions** installieren/verwalten (das wäre Code-Ausführung auf deinem Server)
* **Teamverwaltung** selbst, Rollen vergeben, Rechte ändern
* Es muss immer **mindestens ein Owner** bleiben

### Schutz gegen Rechte-Erschleichung

* Admin-Status (`root_admin`) kann ein Nicht-Owner weder setzen noch ändern.
* Benutzer mit **gleichem oder höherem Rang** kann man nicht bearbeiten/löschen (ein Moderator kann
  keinen Administrator oder Owner anfassen).
* Die Client-API (Konsole, Dateien, Backups fremder Server) ist für Moderatoren/Administratoren auf das
  Recht **„Zugriff auf fremde Server“** begrenzt — sonst dürfte jeder Admin im Core auf jeden Server.
  Eigene und geteilte Server (Subuser) bleiben immer erreichbar.

---

## Wie es funktioniert (und was du wissen musst)

Pterodactyl kennt im Core nur „Admin ja/nein“ (`users.root_admin`). Damit ein Moderator überhaupt in den
Admin-Bereich kommt, setzt die Extension für **alle Rollen mit Rechten** `root_admin = 1` — und
**schränkt sie dann per Middleware wieder ein** (`admin-api`, `application-api`, `client-api`). Die Rolle
ist die Quelle der Wahrheit; `root_admin` wird daraus abgeleitet.

Folgen:

* **Der Admin-Haken im normalen Benutzerformular wird von der Rolle überschrieben.** Rollen vergibst du
  nur in der Teamverwaltung bzw. im Reiter „Rolle“.
* **Wird die Extension deaktiviert, ohne herabzustufen, wären Moderatoren plötzlich Voll-Admins.**
  Deshalb stuft `uninstall.sh` **zuerst herab und entfernt erst dann** (und bricht ab, falls das scheitert).
* **`PTERODACTYL_EXTENSIONS_ENABLED=false` in der `.env` schaltet auch die Einschränkung ab!**
  Vorher `php tools/team-tool.php /var/www/pterodactyl demote-staff` ausführen.
* Wirft die Extension beim Laden einen Fehler, überspringt das Panel sie. Die Absicherung wird deshalb
  als allererstes im `boot()` eingehängt, und die Teamverwaltung zeigt oben **„Rechteprüfung ist aktiv“**
  bzw. eine rote Warnung, falls sie nicht in allen drei API-Gruppen hängt. Schau da nach Updates des Panels kurz rein.
* Unklare Fälle werden **gesperrt** (z. B. unbekannte Admin-Bereiche, nicht eindeutig erkennbarer
  Zielbenutzer, Datenbankfehler). Seiten ohne Recht zeigen im Panel einen 403-Fehler — das Menü selbst
  lässt sich von Extensions nicht ausblenden.

---

## Ein-Befehl-Installer (per SSH)

Sobald das Repo auf GitHub liegt und ein Release existiert, reicht auf dem Server **ein Befehl** (als root):

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/floiofficial/pterodactyl-team-roles/main/setup.sh)
```

Es erscheint ein Menü:

```
  1) Installieren
  2) Deinstallieren
  3) Aktualisieren
  0) Beenden
```

Direktaufruf ohne Menü: `... setup.sh) install`, `... setup.sh) uninstall`, `... setup.sh) update`.

`setup.sh` fragt den Panel-Pfad ab, lädt das neueste Release, prüft die SHA-256-Prüfsumme und legt die
Dateien unter `/opt/floiofficial-teamverwaltung` ab. Danach laufen `install.sh` / `uninstall.sh` /
`update.sh` von dort. **Deinstallieren funktioniert auch ohne Internet**, sobald die Dateien einmal dort liegen.

Voraussetzungen: `curl`, `unzip`, `php` (ist auf dem Panel-Server vorhanden). Für den Befehl oben muss das
Repo **öffentlich** sein (der Code enthält keine Geheimnisse; `backups/` ist ausgeschlossen). Bei einem
privaten Repo: Release-Zip manuell auf den Server legen oder `GITHUB_TOKEN` setzen und `setup.sh` per
`git clone` bzw. Download mit Token holen.

> Wer `curl | bash` nutzt, führt Code aus dem Netz aus. Sieh dir `setup.sh` vorher an — es ist kurz.

---

## Mit GitHub verbinden (Releases + Update per Skript)

Ablauf: Du pflegst den Code in einem GitHub-Repo. Bei einem Versions-Tag baut eine **GitHub Action**
automatisch ein Release (Zip + SHA-256-Prüfsumme). Auf dem Server holt `update.sh` das neueste Release.

**1. Repo anlegen und hochladen** (einmalig, auf deinem Rechner im entpackten Ordner):

```bash
git init -b main        # im Zip ist kein .git-Ordner enthalten, daher einmalig anlegen
git add . && git commit -m "Teamverwaltung 1.0.0"
git remote add origin https://github.com/DEIN-NAME/pterodactyl-team-roles.git
git push -u origin main
```

Die `.gitignore` hält `backups/` heraus — dort stehen **Benutzernamen und E-Mail-Adressen deiner Admins**.
Prüfe vor dem ersten Push mit `git status`, dass nichts aus `backups/` dabei ist. Besonders wichtig bei
einem **öffentlichen** Repo. Ein **privates** Repo ist für dieses Projekt die bessere Wahl.

**2. Neue Version veröffentlichen:**

1. `"version"` in `extension/team-roles/extension.json` erhöhen (z. B. `1.1.0`), committen, pushen
2. `git tag v1.1.0 && git push origin v1.1.0`
3. Die Action „Pruefen und Release“ prüft den Code und legt das Release an. Der Tag **muss** zur Version
   im Manifest passen (sonst bricht sie ab).

**3. Auf dem Server aktualisieren:**

```bash
# erstes Mal (merkt sich das Repo in .update-repo)
sudo bash update.sh /var/www/pterodactyl --repo DEIN-NAME/pterodactyl-team-roles
# danach reicht
sudo bash update.sh /var/www/pterodactyl
# privates Repo: vorher einen Token mit Lesezugriff auf „Contents“ setzen
export GITHUB_TOKEN=...
# zurück zur vorherigen Version
sudo bash update.sh /var/www/pterodactyl --rollback
```

Optionen: `--version v1.1.0` (bestimmte Version), `--yes`, `--force` (gleiche/ältere Version erlauben),
`--ext-dir`, `--web-user`.

Was das Skript sicherstellt:

* Installiert wird nur, wenn die **SHA-256-Prüfsumme** stimmt, das Zip keine unsicheren Pfade enthält und
  die Version im Paket zum Release-Tag passt.
* Vorher wird die installierte Version nach `backups/` gesichert (für `--rollback`).
* Aktualisiert wird per `p:extension:install` über die vorhandene Version. **Es wird nie `p:extension:remove`
  benutzt** — sonst fiele die Rechteprüfung weg und Moderatoren wären kurzzeitig Voll-Admins. Schlägt die
  Installation fehl, stellt das Panel die alte Version selbst wieder her.
* Datenbank-Migrationen einer neuen Version laufen automatisch; ein Rollback dreht sie **nicht** zurück —
  mach vor einem Update ein Datenbank-Backup.

**Ehrlich dazu:** Die GitHub Action wurde **noch nie auf GitHub ausgeführt**, und `update.sh` wurde nur gegen
eine **simulierte** GitHub-API und ein Fake-Panel getestet (Update, aktuell, falsche Prüfsumme, Downgrade,
Installationsfehler, Rollback, Token). Probiere beides zuerst auf dem Test-Panel.

---

## Wieder entfernen

```bash
sudo bash uninstall.sh /var/www/pterodactyl
```

Reihenfolge (Absicht!):

1. Alle Nicht-Owner verlieren den Admin-Status (Owner bleiben Admin) — scheitert das, **wird nichts entfernt**
2. Extension deaktivieren und entfernen
3. Eigene Tabellen löschen (`--keep-data` behält sie, z. B. für ein Update)
4. Caches leeren, Queue neu starten

Danach ist das Panel wie vorher. Wer wieder Admin sein soll: Admin → Users → bearbeiten.

**Ohne Skript, per Hand:**

```bash
cd /var/www/pterodactyl
php /pfad/zu/tools/team-tool.php /var/www/pterodactyl demote-staff   # ZUERST
php artisan p:extension:disable team-roles
php artisan p:extension:remove team-roles
php /pfad/zu/tools/team-tool.php /var/www/pterodactyl drop-tables
php artisan optimize:clear && php artisan queue:restart
```

**Notfall** (Teamverwaltung spinnt, Moderatoren sollen sofort raus):

```bash
php tools/team-tool.php /var/www/pterodactyl demote-staff
```

Der Owner kann außerdem jederzeit per Konsole einen neuen Admin anlegen (`php artisan p:user:make`);
ein Konsolen-Admin ohne Rolle wird automatisch Owner.

---

## Was geprüft wurde — und was nicht

**Geprüft (in der Entwicklungsumgebung):**

* PHP-Syntax aller Dateien, Shell-Skripte, JavaScript, `extension.json`
* **Rechte-Logik:** 40 Prüfungen (`tests/policy_test.php`) — Moderator darf nur lesen, Extensions/Teamverwaltung
  immer Owner-only, Rang-Regel, Admin-Flag-Schutz, Client-API-Regeln
* **Oberfläche:** 28 Prüfungen mit simuliertem React/SDK (Registrierung passt zum Manifest, Matrix,
  Mitgliederliste, Rollen-Reiter, Fehlerfälle)
* **Installer/Deinstaller** mit Fake-Panel: Reihenfolge (erst herabstufen, dann entfernen), Abbruch bei
  Fehler, automatisches Zurückrollen (genau einmal), Abbruch durch „n“

**Nicht geprüft:**

* **Kein Lauf gegen ein echtes Pterodactyl-2.0-Panel.** Entwicklungsumgebung hatte kein Panel.
* `tests/service_test.php` (Datenbank-Logik gegen SQLite) wurde **nicht ausgeführt**, weil Packagist dort
  gesperrt war. Die Datei liegt bei, ist aber ungeprüft.

**Annahmen über das 2.0-Panel** (aus Doku + `bootstrap/app.php`, nicht aus dem restlichen Quellcode):

| Annahme | Falls falsch |
|---|---|
| Spalte `users.root_admin` existiert | Die Migration bricht **vor jeder Änderung** mit klarer Meldung ab |
| Admin-API liegt unter `/api/admin/<bereich>` mit Bereichen wie `users`, `servers`, `nodes`, `locations`, `eggs`, `mounts`, `database-hosts`, `tags`, `activity`, `settings`, `api-keys` | Abweichende Bereiche fallen unter „Sonstige Admin-Bereiche“ (nur Lesen, nur mit Recht) bzw. werden bei Schreibzugriff gesperrt → eher zu streng als zu offen; Zuordnung in `src/Support/PermissionMap.php` anpassen |
| Route-Parameter `server` ist in der `client-api`-Gruppe schon aufgelöst; Tabelle `subusers`, Spalte `owner_id` | Moderatoren bräuchten dann „Zugriff auf fremde Server“ auch für eigene Server (zu streng, nicht zu offen) |
| Benutzer-IDs in Admin-Routen sind Zahlen (oder werden als Modell aufgelöst) | Bearbeiten/Löschen als Nicht-Owner wird gesperrt (zu streng) |
| Screens bekommen die Benutzer-ID als `params.id` oder `resource` | Reiter „Rolle“ meldet „Benutzer-ID konnte nicht ermittelt werden“; die Teamverwaltung-Seite funktioniert trotzdem |

Wenn auf dem Test-Panel etwas davon nicht stimmt, ist es meist eine Zeile in `PermissionMap.php`
oder `client.js` — **sag Bescheid** und schick die Fehlermeldung.

---

## Ordner

```
setup.sh                        Menü: Installieren / Deinstallieren / Aktualisieren (Ein-Befehl-Installer)
install.sh · uninstall.sh        Installer / Deinstaller
update.sh                        Update aus GitHub-Releases (mit Prüfsumme und Rollback)
.github/workflows/release.yml    Prüfungen + automatisches Release bei Tag vX.Y.Z
tools/team-tool.php              Prüfen, Sichern, Herabstufen, Tabellen entfernen (auch für den Notfall)
extension/team-roles/            die eigentliche Extension (Backend PHP, Oberfläche dist/client.js)
tests/                           Rechte-Test (läuft ohne Panel) + Datenbank-Test (ungeprüft)
backups/                         Sicherung der Admin-Konten vor der Installation
```

Hinweis: `dist/client.js` ist von Hand geschrieben (ein ES-Modul, nutzt React und das SDK des Panels
zur Laufzeit) — **kein `npm install`/Build nötig**.

*Teamverwaltung · von floiofficial*
