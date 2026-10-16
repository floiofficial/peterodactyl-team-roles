# Team management for Pterodactyl 2.0

**by floiofficial** · [Deutsch](README.de.md)

Roles **Owner · Administrator · Moderator · Friend · User / Customer** with configurable rights, plus a
built-in **support ticket system** with panel notifications.

* The Owner always has everything. For Administrator, Moderator and Friend you tick what they may do.
* Every signed-in user can open a **support ticket** (describe the problem, pick a priority).
* You decide per role who may **see all tickets**, **reply**, **change status/priority**, **delete** and who
  gets a **notification** in the panel.
* **No panel code is modified.** It is a normal Pterodactyl 2.0 **extension** (`team-roles`) and can be
  removed at any time.
* Everything is **English by default**. The panel language (English / German) is followed automatically,
  and every user can switch the language on the pages themselves.

## 🚀 Install – one command (SSH, as root)

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/floiofficial/peterodactyl-team-roles/main/setup.sh)
```

A menu appears: **1) Install · 2) Uninstall · 3) Update · 0) Exit**. The installer asks for the path to the
panel and **which e-mail address becomes Owner**. Please read "Read this first" below before you run it
(test panel, database backup).

---

## ⚠️ Read this first

1. **Pterodactyl 2.0 is still in development/beta.** The package was built from the official extension docs
   and the panel code, but it was **not tried against a real 2.0 panel by me**. Install it on a **test panel
   (or a copy) first**, not directly on production. See "What was tested" below.
2. **Take a database backup first.** The extension creates 5 tables of its own (roles, members, tickets,
   ticket messages, read markers) and changes `users.root_admin` (see "How it works").

---

## Where to find things in the panel

| What | Where |
|---|---|
| Roles & rights, members | **Admin → "Team management"** (own category in the admin sidebar, like Users or Locations) – Owner only |
| Role of one person | **Admin → Users → [user] → "Role" tab** – Owner only |
| All tickets (staff) | **Admin → "Support tickets"** (own category in the admin sidebar) |
| Support tickets (create, read, reply) | **"Support" and "+ New ticket" in the top navigation** of the dashboard (everyone, also customers) → `/account/support`; also in the account menu |
| Notifications | The **"Support (n)"** link in the top navigation turns blue with the count + a banner on the dashboard / admin overview |

## Support tickets

**Everyone** (also a plain User / Customer) can:

* create a ticket: **subject**, **"What is the problem?"** field and a **priority** (Low · Normal · High · Urgent),
* see **their own** tickets, write follow-up messages, close and re-open them,
* get a notification (panel + **e-mail**) when the team replies.

**Staff** (Owner, Administrator, Moderator, Friend – whatever you configure) can additionally, per role:

| Right | Meaning |
|---|---|
| See all tickets | Opens the **"All tickets"** tab (filters by status/priority/search, sorted by priority or activity) |
| Reply to tickets | Write answers; the ticket becomes "In progress" on the first team answer |
| Change status & priority | Open · In progress · Resolved · Closed, and the priority |
| Delete tickets | Permanently delete a ticket with all its messages |
| Get notified | Panel notification **and e-mail** for new tickets and replies of other people (**only together with "See all tickets"**) |

Set these under **Admin → Team management → Roles & rights → "Support tickets"** (checkboxes at the
bottom of each role). Defaults: Administrator all five; Moderator and Friend: see, reply, notified; User / Customer:
none (their own tickets always work).

Rules worth knowing:

* Own tickets are **always** visible to their author. Tickets someone may not see answer with "not found".
* **Ticket rights never give admin access.** A role that only has ticket rights (e.g. Friend) does **not**
  get into the admin area.
* The Owner always may everything.
* Spam protection: max. 10 open tickets per user, rate limits on creating tickets/messages.
* The notification counter refreshes every 60 seconds while the browser tab is visible.
* **E-mails** use the panel's own mail settings. They go to the roles with "Get notified" on a new
  ticket or a customer reply, and to the customer when the team replies; one separate mail per person with a short excerpt
  and a link. If mail is not configured or sending fails, tickets work as usual (the failure is only logged).

## Install (manual)

```bash
# on the server, in the unpacked folder
sudo bash install.sh /var/www/pterodactyl
```

The installer checks everything, shows the current admins, asks for confirmation, backs up the admin
accounts to `backups/` and installs + enables the extension. **If anything fails it rolls back automatically.**

**Choosing the Owner:** the installer asks *"Who should become Owner?"* – enter the **e-mail address** the
account has in the panel (several: comma-separated). If the address is unknown it asks again. Then you choose
what the **remaining current admins** become: Administrator (default), Moderator, User (no admin access any
more) or Owner. **Empty input** = all current admins stay Owner (previous behaviour, nothing changes).
You can change roles at any time later under Admin → Team management.

Without questions: `sudo bash install.sh /var/www/pterodactyl --owner me@example.com --others administrator --yes`

Options: `--yes`, `--owner EMAIL[,EMAIL2]`, `--others administrator|moderator|friend|user|owner`,
`--web-user www-data`. Docker: run the commands inside the container and pass `/app` as the panel path
(`/app/extensions` must be a volume).

Then reload the page in the panel. Panels reporting version `2.0.0-dev` work too (the manifest accepts
`>=2.0.0-dev <3.0`).

### Default rights

| Role | Admin access | Default |
|---|---|---|
| Owner | everything | not editable, always all rights |
| Administrator | yes | almost everything; settings view-only, no API keys; all ticket rights |
| Moderator | yes | **view only** (users, servers, nodes, locations, eggs, mounts, DB hosts, tags, activity) + view server access; tickets: see, reply, notified |
| Friend | no | no admin rights; tickets: see, reply, notified (editable) |
| User / Customer | no | no rights (can still create and read their own tickets) |

As soon as a role has **at least one right outside "Support tickets"** it gets access to the admin area –
but only within the ticked rights.

### What only the Owner may do (cannot be granted)

* Install/manage **extensions** (that would be code execution on your server)
* **Team management** itself, assigning roles, changing rights
* There must always be **at least one Owner**

### Protection against privilege escalation

* A non-Owner can neither set nor change the admin flag (`root_admin`).
* Users of **equal or higher rank** cannot be edited/deleted (a Moderator cannot touch an Administrator or Owner).
* The client API (console, files, backups of other people's servers) is limited for Moderators/Administrators to the
  right **"Access to other users' servers"** – otherwise every admin could enter every server in the core.
  Own and shared (subuser) servers always stay reachable.

---

## How it works (and what you need to know)

Pterodactyl's core only knows "admin yes/no" (`users.root_admin`). For a Moderator to reach the admin area at
all, the extension sets `root_admin = 1` for **all roles with admin rights** – and **then restricts them again
via middleware** (`admin-api`, `application-api`, `client-api`). The role is the source of truth; `root_admin`
is derived from it. Ticket pages use the normal client API (any signed-in user) and are checked per request.

Consequences:

* **The admin checkbox in the normal user form is overridden by the role.** Assign roles only in Team
  management or in the "Role" tab.
* **If the extension is disabled without demoting first, Moderators would suddenly be full admins.**
  That is why `uninstall.sh` **demotes first and removes afterwards** (and aborts if that fails).
* **`PTERODACTYL_EXTENSIONS_ENABLED=false` in `.env` also switches the restriction off!** Run
  `php tools/team-tool.php /var/www/pterodactyl demote-staff` first.
* If the extension throws while loading, the panel skips it. The protection is therefore attached first thing in
  `boot()`, and the Team management page shows **"Permission check is active"** or a red warning if it is not attached
  to all API groups. Take a quick look after panel updates.
* Unclear cases are **blocked** (unknown admin areas, target user not clearly identifiable, database errors).
  Pages without a right show a 403 error – extensions cannot hide the menu itself.

---

## One-command installer (over SSH)

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/floiofficial/peterodactyl-team-roles/main/setup.sh)
```

```
  1) Install
  2) Uninstall
  3) Update
  0) Exit
```

Direct call without the menu: `... setup.sh) install`, `... setup.sh) uninstall`, `... setup.sh) update`.

`setup.sh` asks for the panel path, downloads the latest release, verifies the SHA-256 checksum and stores the
files in `/opt/floiofficial-teamverwaltung`. `install.sh` / `uninstall.sh` / `update.sh` then run from there.
**Uninstalling also works without internet** once the files are there.

Requirements: `curl`, `unzip`, `php` (present on the panel server). The command above needs a **public** repo
(the code contains no secrets; `backups/` is excluded). For a private repo set `GITHUB_TOKEN`.

> Anyone using `curl | bash` runs code from the internet. Read `setup.sh` first – it is short.

---

## GitHub (releases + update by script)

You maintain the code in a GitHub repo. A **GitHub Action** builds a release (zip + SHA-256 checksum).
On the server `update.sh` fetches the newest release.

**Publish a new version:**

1. Raise `"version"` in `extension/team-roles/extension.json` (e.g. `1.2.0`), commit, push.
2. `git tag v1.2.0 && git push origin v1.2.0` – **or without a terminal:** GitHub → *Actions* → **"Check and release"** →
   *Run workflow* (branch `main`). The action then creates the tag `v<version>` itself.
3. The action checks the code and creates the release. The tag **must** match the manifest version.

**Update on the server:**

```bash
# first time (remembers the repo in .update-repo)
sudo bash update.sh /var/www/pterodactyl --repo floiofficial/peterodactyl-team-roles
# afterwards this is enough
sudo bash update.sh /var/www/pterodactyl
# back to the previous version
sudo bash update.sh /var/www/pterodactyl --rollback
```

Options: `--version v1.2.0`, `--yes`, `--force`, `--ext-dir`, `--web-user`. Private repo: `export GITHUB_TOKEN=...`
(read access to "Contents").

What the script guarantees:

* It only installs when the **SHA-256 checksum** matches, the zip has no unsafe paths and the package version
  matches the release tag.
* The installed version is backed up to `backups/` first (for `--rollback`).
* Updating uses `p:extension:install` over the existing version. **`p:extension:remove` is never used** – otherwise
  the permission check would disappear and Moderators would briefly be full admins.
* Database migrations of a new version run automatically (version 1.1.0 adds the ticket tables). If the panel does not run
  the new migration during an update, the extension creates the ticket tables itself on first use. A rollback does
  **not** revert them – take a database backup before updating.

---

## Remove

```bash
sudo bash uninstall.sh /var/www/pterodactyl
```

Order (intentional!):

1. All non-Owners lose admin status (Owners stay admins) – if this fails, **nothing is removed**
2. Disable and remove the extension
3. Drop its own tables, including the tickets (`--keep-data` keeps them, e.g. for an update)
4. Clear caches, restart the queue

Afterwards the panel is as before. Anyone who should be an admin again: Admin → Users → edit.

**By hand, without the script:**

```bash
cd /var/www/pterodactyl
php /path/to/tools/team-tool.php /var/www/pterodactyl demote-staff   # FIRST
php artisan p:extension:disable team-roles
php artisan p:extension:remove team-roles
php /path/to/tools/team-tool.php /var/www/pterodactyl drop-tables
php artisan optimize:clear && php artisan queue:restart
```

**Emergency** (team management misbehaves, Moderators must leave immediately):

```bash
php tools/team-tool.php /var/www/pterodactyl demote-staff
```

---

## What was tested – and what was not

**Tested (in the development environment):**

* PHP syntax of all files, shell scripts, JavaScript, `extension.json`
* **Permission logic:** 46 checks (`tests/policy_test.php`)
* **Ticket rules** (who may see/reply/manage, validation, status, "unread"): 66 checks (`tests/ticket_rules_test.php`)
* **Ticket database logic** on an SQLite stand-in for Laravel's query builder: 39 checks (`tests/ticket_service_test.php`)
* **Ticket e-mails** (recipients, texts, header-injection safety, mail failures never break tickets): 28 checks (`tests/ticket_mail_test.php`) – with a fake mailer, **no real e-mail was sent**
* **User interface** with a simulated React/SDK and a fake backend: 78 checks (`tests/ui/run.mjs`) – customer creates a
  ticket with priority, team replies, notifications, language switch EN/DE, rights editor, matching of manifest
  and registered screens/slots
* **Installer/uninstaller/update/setup** with a fake panel and a mock GitHub API

**Not tested:**

* **No run against a real Pterodactyl 2.0 panel.** The development environment had none. The SQLite stand-in is *not*
  real Laravel, so the first real run may show small differences.
* `tests/service_test.php` (role logic against SQLite via Laravel) was **not executed** (Packagist was blocked).
* The GitHub Action has not run for this version yet.
* How the **"Support (n)" pill and banners** look in the 2.0 navigation depends on the panel theme.

**Assumptions about the 2.0 panel** (from the docs + `bootstrap/app.php`):

| Assumption | If wrong |
|---|---|
| Column `users.root_admin` exists | The migration aborts **before any change** with a clear message |
| Admin API under `/api/admin/<area>` with areas like `users`, `servers`, `nodes`, … | Different areas fall under "Other admin areas" (read-only) / are blocked for writes → too strict rather than too open; adjust `src/Support/PermissionMap.php` |
| `routes/client.php` is reachable by every signed-in user at `/api/client/extensions/team-roles` | Tickets would not load; the admin pages still work |
| Account screens (`area: account`) appear in the account menu; admin screens with `nav` appear in the admin sidebar | Tickets stay reachable at `/account/support` (also via the top navigation link) |
| The slot `nav.items.after` is the top navigation where the "Support" / "+ New ticket" links appear | The links do not show up there; tickets are still reachable via the account menu and `/account/support` |
| Screens get the user id as `params.id` or `resource` | The "Role" tab says the user ID could not be determined; Team management still works |

If something does not match on your test panel it is usually one line in `PermissionMap.php` or `client.js` –
**tell me** and send the error message.

---

## Folder

```
setup.sh                        Menu: install / uninstall / update (one-command installer)
install.sh · uninstall.sh        Installer / uninstaller
update.sh                        Update from GitHub releases (with checksum and rollback)
.github/workflows/release.yml    Checks + automatic release
tools/team-tool.php              Check, back up, demote, drop tables (also for emergencies)
extension/team-roles/            the extension itself (PHP backend, UI in dist/client.js)
tests/                           Permission, ticket and UI tests (run without a panel)
backups/                         Backup of the admin accounts before installing (never committed)
```

Note: `dist/client.js` is hand-written (one ES module, uses React and the SDK of the panel at runtime) –
**no `npm install`/build needed**.

*Team management · by floiofficial*
