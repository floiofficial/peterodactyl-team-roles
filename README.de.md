# Teamverwaltung (Team management) für Pterodactyl 2.0

**von floiofficial** · [English](README.md) (Standard)

Rollen **Owner · Administrator · Moderator · Freund · Benutzer/Kunde** mit einstellbaren Rechten, dazu ein
eingebautes **Support-Ticket-System** mit Benachrichtigung im Panel.

* Der Owner hat immer alles. Bei Administrator, Moderator und Freund wählst du per Häkchen, was die Person darf.
* Jeder angemeldete Benutzer kann ein **Support-Ticket** erstellen (Problem beschreiben, Priorität wählen).
* Du bestimmst pro Rolle, wer **alle Tickets sehen**, **antworten**, **Status/Priorität ändern**, **löschen** darf
  und wer eine **Benachrichtigung** im Panel bekommt.
* Es wird **kein Panel-Code verändert**. Das Ganze ist eine normale Pterodactyl-2.0-**Extension**
  (`team-roles`) und lässt sich jederzeit wieder entfernen.
* **Standardsprache ist Englisch.** Die Panel-Sprache (Englisch / Deutsch) wird automatisch übernommen, und jeder
  kann die Sprache auf den Seiten selbst umschalten.

## 🚀 Installieren – ein Befehl (SSH, als root)

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/floiofficial/peterodactyl-team-roles/main/setup.sh)
```

Danach erscheint ein Menü: **1) Install · 2) Uninstall · 3) Update · 0) Exit** (die Skripte sind englisch).
Mehr dazu weiter unten bei „Ein-Befehl-Installer“. Vorher bitte die Hinweise unter „Zuerst lesen“ beachten
(Test-Panel, Datenbank-Backup). Der Installer fragt dich, **welche E-Mail Owner wird**.

---

## ⚠️ Zuerst lesen

1. **Pterodactyl 2.0 ist noch Beta** (aktuell `v2.0.0-beta.2`). Das Paket wurde nach der offiziellen
   Extension-Doku und dem Panel-Code (`bootstrap/app.php`) gebaut, aber **nicht gegen ein echtes 2.0-Panel
   ausprobiert**. Installiere es **zuerst auf einem Test-Panel** (oder einer Kopie), nicht direkt auf dem
   Live-System. Siehe „Was geprüft wurde“ unten.
2. **Mach vorher ein Datenbank-Backup.** Die Extension legt 5 eigene Tabellen an (Rollen, Mitglieder, Tickets, Ticket-Nachrichten, Gelesen-Markierungen) und ändert
   `users.root_admin` (siehe „Wie es funktioniert“).

---

## Wo finde ich was im Panel?

| Was | Wo |
|---|---|
| Rollen & Rechte, Mitglieder | **Admin → „Team management“** (eigene Kategorie in der Admin-Seitenleiste, wie Users oder Locations) – nur Owner |
| Rolle einer Person | **Admin → Users → [Benutzer] → Reiter „Role“** – nur Owner |
| Alle Tickets (Team) | **Admin → „Support tickets“** (eigene Kategorie in der Admin-Seitenleiste) |
| Support-Tickets (erstellen, lesen, antworten) | **„Support“ und „+ New ticket“ in der oberen Navigation** des Dashboards (jeder, auch Kunden) → `/account/support`; auch im Konto-Menü |
| Benachrichtigung | Der **„Support (n)“**-Link in der oberen Navigation wird blau mit Zähler + Hinweisbalken auf Dashboard / Admin-Übersicht |

## Support-Tickets

**Jeder** (auch ein einfacher Benutzer/Kunde) kann:

* ein Ticket erstellen: **Betreff**, Feld **„Was ist das Problem?“** und eine **Priorität** (Niedrig · Normal · Hoch · Dringend),
* **seine eigenen** Tickets sehen, weiterschreiben, schließen und wieder öffnen,
* eine Benachrichtigung (Panel + **E-Mail**) bekommen, wenn das Team antwortet – die E-Mail kann **jeder Benutzer selbst an- oder ausschalten** (Häkchen unten auf der Support-Seite, Standard: an).

**Team** (Owner, Administrator, Moderator, Freund – je nachdem, was du einstellst) kann zusätzlich, pro Rolle:

| Recht | Bedeutung |
|---|---|
| Alle Tickets sehen | Öffnet den Reiter **„All tickets“** (Filter nach Status/Priorität/Suche, sortiert nach Priorität oder Aktivität) |
| Auf Tickets antworten | Antworten schreiben; beim ersten Team-Beitrag wird das Ticket „In Bearbeitung“ |
| Status & Priorität ändern | Offen · In Bearbeitung · Gelöst · Geschlossen sowie die Priorität |
| Tickets löschen | Ticket samt Nachrichten endgültig löschen |
| Benachrichtigt werden | Panel-Benachrichtigung **und E-Mail** bei neuen Tickets und Antworten anderer (**nur zusammen mit „Alle Tickets sehen“**) |

Einstellen unter **Admin → Team management → Roles & rights → „Support tickets“** (Häkchen unten bei jeder
Rolle). Standard: Administrator alle fünf; Moderator und Freund: sehen, antworten, benachrichtigt; Benutzer/Kunde: keine
(eigene Tickets gehen immer).

Gut zu wissen:

* Eigene Tickets sieht der Autor **immer**. Tickets, die jemand nicht sehen darf, antworten mit „nicht gefunden“.
* **Ticket-Rechte geben nie Admin-Zugang.** Eine Rolle mit nur Ticket-Rechten (z. B. Freund) kommt **nicht** in den
  Admin-Bereich.
* Der Owner darf immer alles.
* Spam-Schutz: max. 10 offene Tickets pro Benutzer, Begrenzung für neue Tickets/Nachrichten.
* Der Benachrichtigungszähler aktualisiert sich alle 60 Sekunden, solange der Browser-Tab sichtbar ist.
* **E-Mails** nutzen die Mail-Einstellungen des Panels. Sie gehen bei einem neuen Ticket oder einer
  Kunden-Antwort an die Rollen mit „Benachrichtigt werden“ und an den Kunden, wenn das Team antwortet; je Person eine eigene
  Mail mit kurzem Auszug und Link. Ist Mail nicht eingerichtet oder schlägt der Versand fehl, funktionieren Tickets normal
  (der Fehler wird nur protokolliert). Jeder (Kunden und Team) kann E-Mails für sich auf der Support-Seite ausschalten;
  die Panel-Benachrichtigung bleibt.

## Im Panel nicht sichtbar? Diagnose

```bash
sudo bash setup.sh        # -> 4) Diagnose      (oder: sudo bash diagnose.sh /var/www/pterodactyl)
```

Nur lesend. Zeigt, was das Panel sagt (`p:extension:list`, `p:extension:doctor`), ob die gebauten Dateien und Assets
vorhanden sind und ob die Datenbank-Tabellen existieren. Danach das Panel mit **Strg+F5** neu laden und in der
Browser-Konsole (F12) nach roten Zeilen zur Erweiterung schauen.

## Installieren (manuell)

```bash
# auf dem Server, im entpackten Ordner
sudo bash install.sh /var/www/pterodactyl
```

Der Installer prüft alles, zeigt die aktuellen Admins, fragt nach Bestätigung, sichert die Admin-Konten
nach `backups/` und installiert + aktiviert die Extension. **Schlägt etwas fehl, rollt er automatisch
zurück.**

**Owner auswählen:** Der Installer fragt: *„Wer soll Owner werden?“* — gib die **E-Mail-Adresse** ein, die das
Konto im Panel hat (mehrere mit Komma). Stimmt die Adresse nicht, fragt er erneut. Danach wählst du, was die
**übrigen bisherigen Admins** werden: Administrator (Standard), Moderator, Benutzer (kein Admin-Zugang mehr)
oder Owner. **Leere Eingabe** = alle bisherigen Admins bleiben Owner (altes Verhalten, nichts ändert sich).
Später änderst du Rollen jederzeit unter Admin → Team management.

Ohne Rückfragen: `sudo bash install.sh /var/www/pterodactyl --owner ich@example.com --others administrator --yes`

Optionen: `--yes` (ohne Rückfrage), `--owner EMAIL[,EMAIL2]`, `--others administrator|moderator|friend|user|owner`,
`--web-user www-data`. Docker: Befehle im Container ausführen und
`/app` als Panel-Pfad angeben (`/app/extensions` muss ein Volume sein).

Danach im Panel die Seite neu laden. Panels mit Version `2.0.0-dev` funktionieren ebenfalls (das Manifest erlaubt
`>=2.0.0-dev <3.0`).

## Rollen benutzen

* **Admin → Users → Reiter „Team management“**
  * **Rollen & Rechte:** Rolle wählen → Häkchen setzen → *Rechte speichern*. Pro Bereich (Benutzer, Server,
    Nodes, …) gibt es *Ansehen / Erstellen·Aktionen / Bearbeiten / Löschen*.
  * **Mitglieder:** Suche, Filter, Rolle pro Person per Auswahlfeld ändern.
* **Admin → Users → [Benutzer] → Reiter „Rolle“:** Rolle für genau diese Person einstellen
  (User/Kunde, Freund, Moderator, Administrator, Owner). Im Kopf der Benutzerseite steht ein Rollen-Chip.

### Standard-Rechte

| Rolle | Admin-Zugang | Standard |
|---|---|---|
| Owner | alles | nicht einstellbar, immer alle Rechte |
| Administrator | ja | fast alles; Einstellungen nur ansehen, keine API-Schlüssel; alle Ticket-Rechte |
| Moderator | ja | nur **Ansehen** (Benutzer, Server, Nodes, Locations, Eggs, Mounts, DB-Hosts, Tags, Aktivität) + Server-Zugriff ansehen; Tickets: sehen, antworten, benachrichtigt |
| Freund | nein | kein Admin-Recht; Tickets: sehen, antworten, benachrichtigt (änderbar) |
| Benutzer / Kunde | nein | keine Rechte (eigene Tickets gehen trotzdem) |

Sobald eine Rolle **mindestens ein Recht außerhalb von „Support tickets“** hat, bekommt sie Zugang zum Admin-Bereich —
aber nur im Rahmen der Häkchen.

### Was nur der Owner darf (nicht vergebbar)

* **Extensions** installieren/verwalten (das wäre Code-Ausführung auf deinem Server)
* **Team management** selbst, Rollen vergeben, Rechte ändern
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
  nur unter „Team management“ bzw. im Reiter „Role“.
* **Wird die Extension deaktiviert, ohne herabzustufen, wären Moderatoren plötzlich Voll-Admins.**
  Deshalb stuft `uninstall.sh` **zuerst herab und entfernt erst dann** (und bricht ab, falls das scheitert).
* **`PTERODACTYL_EXTENSIONS_ENABLED=false` in der `.env` schaltet auch die Einschränkung ab!**
  Vorher `php tools/team-tool.php /var/www/pterodactyl demote-staff` ausführen.
* Wirft die Extension beim Laden einen Fehler, überspringt das Panel sie. Die Absicherung wird deshalb
  als allererstes im `boot()` eingehängt, und die Team-management-Seite zeigt oben **„Permission check is active“** (bzw. „Rechteprüfung ist aktiv“ auf Deutsch)
  bzw. eine rote Warnung, falls sie nicht in allen drei API-Gruppen hängt. Schau da nach Updates des Panels kurz rein.
* Unklare Fälle werden **gesperrt** (z. B. unbekannte Admin-Bereiche, nicht eindeutig erkennbarer
  Zielbenutzer, Datenbankfehler). Seiten ohne Recht zeigen im Panel einen 403-Fehler — das Menü selbst
  lässt sich von Extensions nicht ausblenden.

---

## Ein-Befehl-Installer (per SSH)

Sobald das Repo auf GitHub liegt und ein Release existiert, reicht auf dem Server **ein Befehl** (als root):

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/floiofficial/peterodactyl-team-roles/main/setup.sh)
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
git add . && git commit -m "Team management"
git remote add origin https://github.com/floiofficial/peterodactyl-team-roles.git
git push -u origin main
```

Die `.gitignore` hält `backups/` heraus — dort stehen **Benutzernamen und E-Mail-Adressen deiner Admins**.
Prüfe vor dem ersten Push mit `git status`, dass nichts aus `backups/` dabei ist. Besonders wichtig bei
einem **öffentlichen** Repo. Ein **privates** Repo ist für dieses Projekt die bessere Wahl.

**2. Neue Version veröffentlichen:**

1. `"version"` in `extension/team-roles/extension.json` erhöhen (z. B. `1.2.0`), committen, pushen
2. `git tag v1.2.0 && git push origin v1.2.0` — **oder ohne Terminal:** GitHub → *Actions* → **„Check and release“** →
   *Run workflow* (Branch `main`). Die Action legt den Tag `v<Version>` dann selbst an.
3. Die Action „Check and release“ prüft den Code und legt das Release an. Der Tag **muss** zur Version
   im Manifest passen (sonst bricht sie ab).

**3. Auf dem Server aktualisieren:**

```bash
# erstes Mal (merkt sich das Repo in .update-repo)
sudo bash update.sh /var/www/pterodactyl --repo floiofficial/peterodactyl-team-roles
# danach reicht
sudo bash update.sh /var/www/pterodactyl
# privates Repo: vorher einen Token mit Lesezugriff auf „Contents“ setzen
export GITHUB_TOKEN=...
# zurück zur vorherigen Version
sudo bash update.sh /var/www/pterodactyl --rollback
```

Optionen: `--version v1.2.0` (bestimmte Version), `--yes`, `--force` (gleiche/ältere Version erlauben),
`--ext-dir`, `--web-user`.

Was das Skript sicherstellt:

* Installiert wird nur, wenn die **SHA-256-Prüfsumme** stimmt, das Zip keine unsicheren Pfade enthält und
  die Version im Paket zum Release-Tag passt.
* Vorher wird die installierte Version nach `backups/` gesichert (für `--rollback`).
* Aktualisiert wird per `p:extension:install` über die vorhandene Version. **Es wird nie `p:extension:remove`
  benutzt** — sonst fiele die Rechteprüfung weg und Moderatoren wären kurzzeitig Voll-Admins. Schlägt die
  Installation fehl, stellt das Panel die alte Version selbst wieder her.
* Datenbank-Migrationen einer neuen Version laufen automatisch (Version 1.1.0 legt die Ticket-Tabellen an, Version 1.2.0 die Tabelle für die E-Mail-Einstellung). Führt das
  Panel die neue Migration beim Update nicht aus, legt die Extension die Ticket-Tabellen bei der ersten Nutzung selbst an.
  Ein Rollback dreht sie **nicht** zurück — mach vor einem Update ein Datenbank-Backup.

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
3. Eigene Tabellen löschen, auch die Tickets (`--keep-data` behält sie, z. B. für ein Update)
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

**Notfall** (Team management spinnt, Moderatoren sollen sofort raus):

```bash
php tools/team-tool.php /var/www/pterodactyl demote-staff
```

Der Owner kann außerdem jederzeit per Konsole einen neuen Admin anlegen (`php artisan p:user:make`);
ein Konsolen-Admin ohne Rolle wird automatisch Owner.

---

## Was geprüft wurde — und was nicht

**Geprüft (in der Entwicklungsumgebung):**

* PHP-Syntax aller Dateien, Shell-Skripte, JavaScript, `extension.json`
* **Rechte-Logik:** 46 Prüfungen (`tests/policy_test.php`) — Moderator darf nur lesen, Extensions/Team management
  immer Owner-only, Rang-Regel, Admin-Flag-Schutz, Client-API-Regeln, Ticket-Rechte geben nie Admin-Zugang
* **Ticket-Regeln** (wer sehen/antworten/verwalten darf, Validierung, Status, „ungelesen“): 66 Prüfungen (`tests/ticket_rules_test.php`)
* **Ticket-Datenbanklogik** gegen einen SQLite-Ersatz für den Laravel-Query-Builder: 39 Prüfungen (`tests/ticket_service_test.php`)
* **Ticket-E-Mails** (Empfänger, Texte, Schutz vor Header-Injection, Mailfehler brechen nie Tickets): 28 Prüfungen (`tests/ticket_mail_test.php`) — mit Fake-Mailer, **es wurde keine echte E-Mail verschickt**
* **Oberfläche** mit simuliertem React/SDK und Fake-Backend: 78 Prüfungen (`tests/ui/run.mjs`) — Kunde erstellt Ticket
  mit Priorität, Team antwortet, Benachrichtigungen, Sprachwechsel EN/DE, Rechte-Editor, Manifest ↔ Screens/Slots
* **Installer/Deinstaller** mit Fake-Panel: Reihenfolge (erst herabstufen, dann entfernen), Abbruch bei
  Fehler, automatisches Zurückrollen (genau einmal), Abbruch durch „n“

**Nicht geprüft:**

* **Kein Lauf gegen ein echtes Pterodactyl-2.0-Panel.** Entwicklungsumgebung hatte kein Panel. Der SQLite-Ersatz ist
  *nicht* echtes Laravel, beim ersten echten Lauf können kleine Unterschiede auftauchen.
* Die GitHub Action ist für diese Version noch nicht gelaufen.
* Wie die **„Support (n)“-Markierung und die Hinweisbalken** in der 2.0-Navigation aussehen, hängt vom Panel-Theme ab.
* `tests/service_test.php` (Datenbank-Logik gegen SQLite) wurde **nicht ausgeführt**, weil Packagist dort
  gesperrt war. Die Datei liegt bei, ist aber ungeprüft.

**Annahmen über das 2.0-Panel** (aus Doku + `bootstrap/app.php`, nicht aus dem restlichen Quellcode):

| Annahme | Falls falsch |
|---|---|
| Spalte `users.root_admin` existiert | Die Migration bricht **vor jeder Änderung** mit klarer Meldung ab |
| Admin-API liegt unter `/api/admin/<bereich>` mit Bereichen wie `users`, `servers`, `nodes`, `locations`, `eggs`, `mounts`, `database-hosts`, `tags`, `activity`, `settings`, `api-keys` | Abweichende Bereiche fallen unter „Sonstige Admin-Bereiche“ (nur Lesen, nur mit Recht) bzw. werden bei Schreibzugriff gesperrt → eher zu streng als zu offen; Zuordnung in `src/Support/PermissionMap.php` anpassen |
| Route-Parameter `server` ist in der `client-api`-Gruppe schon aufgelöst; Tabelle `subusers`, Spalte `owner_id` | Moderatoren bräuchten dann „Zugriff auf fremde Server“ auch für eigene Server (zu streng, nicht zu offen) |
| Benutzer-IDs in Admin-Routen sind Zahlen (oder werden als Modell aufgelöst) | Bearbeiten/Löschen als Nicht-Owner wird gesperrt (zu streng) |
| Screens bekommen die Benutzer-ID als `params.id` oder `resource` | Reiter „Role“ meldet „Benutzer-ID konnte nicht ermittelt werden“; die Team-management-Seite funktioniert trotzdem |

Wenn auf dem Test-Panel etwas davon nicht stimmt, ist es meist eine Zeile in `PermissionMap.php`
oder `client.js` — **sag Bescheid** und schick die Fehlermeldung.

---

## Ordner

```
setup.sh                        Menü: Installieren / Deinstallieren / Aktualisieren (Ein-Befehl-Installer)
install.sh · uninstall.sh        Installer / Deinstaller
update.sh                        Update aus GitHub-Releases (mit Prüfsumme und Rollback)
.github/workflows/release.yml    Prüfungen + automatisches Release
tools/team-tool.php              Prüfen, Sichern, Herabstufen, Tabellen entfernen (auch für den Notfall)
extension/team-roles/            die eigentliche Extension (Backend PHP, Oberfläche dist/client.js)
tests/                           Rechte-, Ticket- und Oberflächen-Tests (laufen ohne Panel)
backups/                         Sicherung der Admin-Konten vor der Installation
```

Hinweis: `dist/client.js` ist von Hand geschrieben (ein ES-Modul, nutzt React und das SDK des Panels
zur Laufzeit) — **kein `npm install`/Build nötig**.

*Team management · von floiofficial*
