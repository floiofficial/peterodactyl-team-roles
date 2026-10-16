// Team management (team-roles) - by floiofficial.
// Hand-written ES module: React and the SDK are provided by the panel at runtime (import map),
// so no build step is needed. Everything except `definePterodactylExtension` and `http` is
// optional: if the SDK does not offer a helper, a small built-in one is used instead.
import { createElement as h, useCallback, useEffect, useState, useSyncExternalStore } from 'react';
import * as sdk from '@pterodactyl/sdk';

const ADMIN_BASE = '/api/admin/extensions/team-roles';
const CLIENT_BASE = '/api/client/extensions/team-roles';
const LINE = 'color-mix(in srgb, currentColor 18%, transparent)';
const SOFT = 'color-mix(in srgb, currentColor 8%, transparent)';
const TICKET_KEYS = ['view', 'reply', 'manage', 'delete', 'notify'];
const SYSTEM_ROLES = ['owner', 'administrator', 'moderator', 'friend', 'user'];
const PRIORITIES = ['low', 'normal', 'high', 'urgent'];
const STATUSES = ['open', 'in_progress', 'resolved', 'closed'];

// ------------------------------------------------------------------ translations

const EN = {
    'lang.label': 'Language',
    'lang.auto': 'Automatic',
    'footer': 'Team management · by floiofficial',
    'loading': 'Loading…',
    'prev': 'Previous',
    'next': 'Next',
    'page': 'Page {p} of {n} · {total} {what}',
    'err.generic': 'Something went wrong.',
    'err.ownerOnly': 'Only the Owner may manage roles and rights.',


    'team.title': 'Team management',
    'health.ok': 'Permission check is active ({groups}). Owners: {owners}.',
    'health.bad': 'WARNING: the permission check is not attached to all API groups! Moderators/administrators would then have full access. Re-enable the extension or run tools/team-tool.php demote-staff.',
    'roles.title': 'Roles & rights',
    'roles.intro': '{owner} always has all rights and cannot be edited. Friend and User / Customer have no admin access by default. Extensions and this team management can only be used by the Owner.',
    'roles.none': 'There are no editable roles.',
    'roles.admin': 'With at least one right outside "Support tickets", "{role}" gets access to the admin area - but only within the checked rights.',
    'roles.noAdmin': '"{role}" has no admin rights and therefore no access to the admin area. Support ticket rights alone never grant admin access.',
    'roles.allowAll': 'Allow everything',
    'roles.viewOnly': 'View only',
    'roles.revokeAll': 'Revoke everything',
    'roles.save': 'Save rights',
    'roles.unsaved': 'Unsaved changes',
    'roles.saved': 'Rights for "{role}" saved.',
    'matrix.area': 'Area',
    'matrix.all': 'all',
    'matrix.none': 'none',
    'matrix.sensitive': 'sensitive',
    'act.view': 'View',
    'act.create': 'Create / actions',
    'act.edit': 'Edit',
    'act.delete': 'Delete',
    'tk.title': 'Support tickets',
    'tk.intro': 'Everyone can always create tickets and see their own. These rights decide what this role may do with OTHER people\'s tickets.',
    'tk.view': 'See all tickets',
    'tk.reply': 'Reply to tickets',
    'tk.manage': 'Change status & priority',
    'tk.delete': 'Delete tickets',
    'tk.notify': 'Get notified in the panel and by e-mail (new tickets & replies)',
    'tk.notifyNeedsView': 'Notifications only work together with "See all tickets".',

    'res.users.label': 'Users',
    'res.users.hint': 'Accounts of other users. Only users with a lower rank can be changed; only the Owner assigns roles.',
    'res.servers.label': 'Servers (management)',
    'res.servers.hint': 'Create, suspend, edit and delete servers.',
    'res.nodes.label': 'Nodes',
    'res.nodes.hint': 'Wings nodes and allocations.',
    'res.locations.label': 'Locations',
    'res.eggs.label': 'Eggs & nests',
    'res.eggs.hint': 'Including the egg catalog.',
    'res.mounts.label': 'Mounts',
    'res.database-hosts.label': 'Database hosts',
    'res.tags.label': 'Tags',
    'res.activity.label': 'Activity log',
    'res.activity.hint': 'Read-only.',
    'res.settings.label': 'Panel settings',
    'res.settings.hint': 'Mail, branding, security - grant with care.',
    'res.api-keys.label': 'API keys',
    'res.api-keys.hint': 'Keys only work within the role of their creator.',
    'res.server-access.label': 'Access to other users\' servers',
    'res.server-access.hint': 'Console, files, backups, databases etc. of servers the user does not own.',
    'res.other.label': 'Other admin areas',
    'res.other.hint': 'Admin pages not listed here, read-only.',

    'role.owner': 'Owner',
    'role.administrator': 'Administrator',
    'role.moderator': 'Moderator',
    'role.friend': 'Friend',
    'role.user': 'User / Customer',

    'members.title': 'Members',
    'members.search': 'Search username or email',
    'members.searchBtn': 'Search',
    'members.allRoles': 'All roles',
    'members.id': 'ID',
    'members.user': 'User',
    'members.email': 'Email',
    'members.role': 'Role',
    'members.none': 'No users found.',
    'members.users': 'users',
    'members.confirmOwner': 'Really make "{user}" an Owner? Owners have ALL rights.',
    'members.changed': '{user} is now {role}.',

    'urole.title': 'Role',
    'urole.of': 'Role of {user}',
    'urole.save': 'Save role',
    'urole.saved': 'Role saved.',
    'urole.current': 'Current: {role} · {rights}.',
    'urole.allRights': 'all rights',
    'urole.nRights': '{n} right(s)',
    'urole.hint': 'You set which rights a role has under "Team management". The admin checkbox in the normal user form is overridden by the role.',
    'urole.noId': 'Could not determine the user ID.',
    'urole.open': 'Open team management',
    'urole.confirmOwner': 'Really make this user an Owner? Owners have ALL rights.',
    'badge.role': 'Role: {role}',

    'sup.title': 'Support',
    'sup.mine': 'My tickets',
    'sup.all': 'All tickets',
    'sup.new': 'New ticket',
    'sup.subject': 'Subject',
    'sup.subjectPh': 'Short summary of the problem',
    'sup.desc': 'What is the problem?',
    'sup.descPh': 'Describe the problem in as much detail as you can…',
    'sup.priority': 'Priority',
    'sup.submit': 'Submit ticket',
    'sup.cancel': 'Cancel',
    'sup.created': 'Ticket #{id} created.',
    'sup.subjectErr': 'The subject must be between 3 and 150 characters.',
    'sup.descErr': 'Please describe the problem (10 to 5000 characters).',
    'sup.intro': 'Something not working? Open a ticket and our team will get back to you. You are notified in the panel and by e-mail when someone replies.',
    'sup.empty': 'No tickets yet.',
    'sup.emptyFiltered': 'No tickets match these filters.',
    'sup.search': 'Search subject',
    'sup.searchBtn': 'Search',
    'sup.sortActivity': 'Latest activity',
    'sup.sortPriority': 'Priority first',
    'sup.unread': 'New activity',
    'sup.col.subject': 'Subject',
    'sup.col.priority': 'Priority',
    'sup.col.status': 'Status',
    'sup.col.author': 'Author',
    'sup.col.updated': 'Updated',
    'sup.tickets': 'tickets',
    'sup.back': '← Back to tickets',
    'sup.by': 'by {user} · {date}',
    'sup.thread': 'Conversation',
    'sup.team': 'Team',
    'sup.author': 'Author',
    'sup.reply': 'Write a reply',
    'sup.replyPh': 'Your message…',
    'sup.send': 'Send reply',
    'sup.replied': 'Reply sent.',
    'sup.closedNote': 'This ticket is closed. Reopen it to write a message.',
    'sup.close': 'Close ticket',
    'sup.reopen': 'Reopen ticket',
    'sup.delete': 'Delete ticket',
    'sup.confirmDelete': 'Delete ticket #{id} permanently, including all messages?',
    'sup.deleted': 'Ticket deleted.',
    'sup.statusChanged': 'Status changed.',
    'sup.priorityChanged': 'Priority changed.',
    'sup.noAccess': 'You are not allowed to see all tickets.',
    'att.label': 'Attachments (optional)',
    'att.hint': 'Screenshots (PNG, JPG, GIF, WebP) or text files (.txt, .log). Up to {n} files, {mb} MB each.',
    'att.add': 'Add files',
    'att.remove': 'Remove',
    'att.tooMany': 'You can attach at most {n} files.',
    'att.tooBig': '"{name}" is larger than {mb} MB.',
    'att.title': 'Attachments',
    'att.failed': 'The file could not be downloaded.',
    'mail.label': 'E-mail me when there is a new message in a ticket',
    'mail.hint': 'Sent to the e-mail address of your account. You can change this at any time; the panel notification stays.',
    'mail.on': 'E-mail notifications are on.',
    'mail.off': 'E-mail notifications are off.',
    'prio.low': 'Low',
    'prio.normal': 'Normal',
    'prio.high': 'High',
    'prio.urgent': 'Urgent',
    'prio.low.hint': 'Low - no rush',
    'prio.normal.hint': 'Normal',
    'prio.high.hint': 'High - this blocks me',
    'prio.urgent.hint': 'Urgent - everything is down',
    'prio.all': 'All priorities',
    'status.open': 'Open',
    'status.in_progress': 'In progress',
    'status.resolved': 'Resolved',
    'status.closed': 'Closed',
    'status.active': 'Active (open + in progress)',
    'status.all': 'All statuses',

    'notif.bell': 'Support ({n})',
    'notif.mine': 'You have {n} ticket(s) with a new reply.',
    'notif.staff': '{n} support ticket(s) need attention (new tickets or replies).',
    'notif.open': 'Open support',
    'notif.toast': 'New support activity ({n})',
};

const DE = {
    'lang.label': 'Sprache',
    'lang.auto': 'Automatisch',
    'footer': 'Teamverwaltung · von floiofficial',
    'loading': 'Lädt…',
    'prev': 'Zurück',
    'next': 'Weiter',
    'page': 'Seite {p} von {n} · {total} {what}',
    'err.generic': 'Etwas ist schiefgelaufen.',
    'err.ownerOnly': 'Nur der Owner darf Rollen und Rechte verwalten.',


    'team.title': 'Teamverwaltung',
    'health.ok': 'Rechteprüfung ist aktiv ({groups}). Owner: {owners}.',
    'health.bad': 'ACHTUNG: Die Rechteprüfung hängt nicht in allen API-Gruppen! Moderatoren/Administratoren hätten dann vollen Zugriff. Bitte die Extension neu aktivieren oder tools/team-tool.php demote-staff ausführen.',
    'roles.title': 'Rollen & Rechte',
    'roles.intro': '{owner} hat immer alle Rechte und ist nicht einstellbar. Freund und Benutzer / Kunde haben standardmäßig keinen Admin-Zugang. Extensions und diese Teamverwaltung kann nur der Owner bedienen.',
    'roles.none': 'Keine bearbeitbaren Rollen vorhanden.',
    'roles.admin': 'Mit mindestens einem Recht außerhalb von „Support-Tickets“ bekommt „{role}“ Zugang zum Admin-Bereich - aber nur im Rahmen der angehakten Rechte.',
    'roles.noAdmin': '„{role}“ hat keine Admin-Rechte und damit keinen Zugang zum Admin-Bereich. Ticket-Rechte allein geben nie Admin-Zugang.',
    'roles.allowAll': 'Alles erlauben',
    'roles.viewOnly': 'Nur Ansehen',
    'roles.revokeAll': 'Alles entziehen',
    'roles.save': 'Rechte speichern',
    'roles.unsaved': 'Ungespeicherte Änderungen',
    'roles.saved': 'Rechte für „{role}“ gespeichert.',
    'matrix.area': 'Bereich',
    'matrix.all': 'alle',
    'matrix.none': 'keine',
    'matrix.sensitive': 'heikel',
    'act.view': 'Ansehen',
    'act.create': 'Erstellen / Aktionen',
    'act.edit': 'Bearbeiten',
    'act.delete': 'Löschen',
    'tk.title': 'Support-Tickets',
    'tk.intro': 'Jeder kann immer Tickets erstellen und seine eigenen sehen. Diese Rechte bestimmen, was diese Rolle mit den Tickets ANDERER darf.',
    'tk.view': 'Alle Tickets sehen',
    'tk.reply': 'Auf Tickets antworten',
    'tk.manage': 'Status & Priorität ändern',
    'tk.delete': 'Tickets löschen',
    'tk.notify': 'Im Panel und per E-Mail benachrichtigt werden (neue Tickets & Antworten)',
    'tk.notifyNeedsView': 'Benachrichtigungen funktionieren nur zusammen mit „Alle Tickets sehen“.',

    'res.users.label': 'Benutzer',
    'res.users.hint': 'Konten anderer Nutzer. Nur Nutzer mit niedrigerem Rang änderbar; Rollen vergibt nur der Owner.',
    'res.servers.label': 'Server (Verwaltung)',
    'res.servers.hint': 'Server anlegen, sperren, bearbeiten, löschen.',
    'res.nodes.label': 'Nodes',
    'res.nodes.hint': 'Wings-Nodes und Allocations.',
    'res.locations.label': 'Locations',
    'res.eggs.label': 'Eggs & Nests',
    'res.eggs.hint': 'Inklusive Egg-Katalog.',
    'res.mounts.label': 'Mounts',
    'res.database-hosts.label': 'Datenbank-Hosts',
    'res.tags.label': 'Tags',
    'res.activity.label': 'Aktivitätslog',
    'res.activity.hint': 'Nur lesbar.',
    'res.settings.label': 'Panel-Einstellungen',
    'res.settings.hint': 'Mail, Branding, Sicherheit - vorsichtig vergeben.',
    'res.api-keys.label': 'API-Schlüssel',
    'res.api-keys.hint': 'Schlüssel gelten nur im Rahmen der Rolle ihres Erstellers.',
    'res.server-access.label': 'Zugriff auf fremde Server',
    'res.server-access.hint': 'Konsole, Dateien, Backups, Datenbanken usw. von Servern, die dem Nutzer nicht gehören.',
    'res.other.label': 'Sonstige Admin-Bereiche',
    'res.other.hint': 'Nicht zugeordnete Admin-Seiten, nur lesbar.',

    'role.owner': 'Owner',
    'role.administrator': 'Administrator',
    'role.moderator': 'Moderator',
    'role.friend': 'Freund',
    'role.user': 'Benutzer / Kunde',

    'members.title': 'Mitglieder',
    'members.search': 'Benutzername oder E-Mail suchen',
    'members.searchBtn': 'Suchen',
    'members.allRoles': 'Alle Rollen',
    'members.id': 'ID',
    'members.user': 'Benutzer',
    'members.email': 'E-Mail',
    'members.role': 'Rolle',
    'members.none': 'Keine Benutzer gefunden.',
    'members.users': 'Benutzer',
    'members.confirmOwner': '„{user}“ wirklich zum Owner machen? Owner haben ALLE Rechte.',
    'members.changed': '{user} ist jetzt {role}.',

    'urole.title': 'Rolle',
    'urole.of': 'Rolle von {user}',
    'urole.save': 'Rolle speichern',
    'urole.saved': 'Rolle gespeichert.',
    'urole.current': 'Aktuell: {role} · {rights}.',
    'urole.allRights': 'alle Rechte',
    'urole.nRights': '{n} Recht(e)',
    'urole.hint': 'Welche Rechte eine Rolle hat, stellst du unter „Teamverwaltung“ ein. Der Admin-Haken im normalen Benutzerformular wird von der Rolle überschrieben.',
    'urole.noId': 'Benutzer-ID konnte nicht ermittelt werden.',
    'urole.open': 'Zur Teamverwaltung',
    'urole.confirmOwner': 'Diesen Benutzer wirklich zum Owner machen? Owner haben ALLE Rechte.',
    'badge.role': 'Rolle: {role}',

    'sup.title': 'Support',
    'sup.mine': 'Meine Tickets',
    'sup.all': 'Alle Tickets',
    'sup.new': 'Neues Ticket',
    'sup.subject': 'Betreff',
    'sup.subjectPh': 'Kurze Zusammenfassung des Problems',
    'sup.desc': 'Was ist das Problem?',
    'sup.descPh': 'Beschreibe das Problem so genau wie möglich…',
    'sup.priority': 'Priorität',
    'sup.submit': 'Ticket absenden',
    'sup.cancel': 'Abbrechen',
    'sup.created': 'Ticket #{id} erstellt.',
    'sup.subjectErr': 'Der Betreff muss zwischen 3 und 150 Zeichen lang sein.',
    'sup.descErr': 'Bitte beschreibe das Problem (10 bis 5000 Zeichen).',
    'sup.intro': 'Etwas funktioniert nicht? Erstelle ein Ticket, unser Team meldet sich. Du wirst im Panel und per E-Mail benachrichtigt, sobald jemand antwortet.',
    'sup.empty': 'Noch keine Tickets.',
    'sup.emptyFiltered': 'Keine Tickets für diese Filter.',
    'sup.search': 'Betreff suchen',
    'sup.searchBtn': 'Suchen',
    'sup.sortActivity': 'Letzte Aktivität',
    'sup.sortPriority': 'Priorität zuerst',
    'sup.unread': 'Neue Aktivität',
    'sup.col.subject': 'Betreff',
    'sup.col.priority': 'Priorität',
    'sup.col.status': 'Status',
    'sup.col.author': 'Autor',
    'sup.col.updated': 'Aktualisiert',
    'sup.tickets': 'Tickets',
    'sup.back': '← Zurück zu den Tickets',
    'sup.by': 'von {user} · {date}',
    'sup.thread': 'Unterhaltung',
    'sup.team': 'Team',
    'sup.author': 'Autor',
    'sup.reply': 'Antwort schreiben',
    'sup.replyPh': 'Deine Nachricht…',
    'sup.send': 'Antwort senden',
    'sup.replied': 'Antwort gesendet.',
    'sup.closedNote': 'Dieses Ticket ist geschlossen. Öffne es wieder, um eine Nachricht zu schreiben.',
    'sup.close': 'Ticket schließen',
    'sup.reopen': 'Ticket wieder öffnen',
    'sup.delete': 'Ticket löschen',
    'sup.confirmDelete': 'Ticket #{id} samt aller Nachrichten endgültig löschen?',
    'sup.deleted': 'Ticket gelöscht.',
    'sup.statusChanged': 'Status geändert.',
    'sup.priorityChanged': 'Priorität geändert.',
    'sup.noAccess': 'Du darfst nicht alle Tickets sehen.',
    'att.label': 'Anhänge (optional)',
    'att.hint': 'Screenshots (PNG, JPG, GIF, WebP) oder Textdateien (.txt, .log). Bis zu {n} Dateien, je {mb} MB.',
    'att.add': 'Dateien hinzufügen',
    'att.remove': 'Entfernen',
    'att.tooMany': 'Du kannst höchstens {n} Dateien anhängen.',
    'att.tooBig': '„{name}“ ist größer als {mb} MB.',
    'att.title': 'Anhänge',
    'att.failed': 'Die Datei konnte nicht heruntergeladen werden.',
    'mail.label': 'Per E-Mail benachrichtigen, wenn es eine neue Nachricht in einem Ticket gibt',
    'mail.hint': 'Gesendet an die E-Mail-Adresse deines Kontos. Du kannst das jederzeit ändern; die Benachrichtigung im Panel bleibt.',
    'mail.on': 'E-Mail-Benachrichtigungen sind an.',
    'mail.off': 'E-Mail-Benachrichtigungen sind aus.',
    'prio.low': 'Niedrig',
    'prio.normal': 'Normal',
    'prio.high': 'Hoch',
    'prio.urgent': 'Dringend',
    'prio.low.hint': 'Niedrig - keine Eile',
    'prio.normal.hint': 'Normal',
    'prio.high.hint': 'Hoch - das blockiert mich',
    'prio.urgent.hint': 'Dringend - alles steht still',
    'prio.all': 'Alle Prioritäten',
    'status.open': 'Offen',
    'status.in_progress': 'In Bearbeitung',
    'status.resolved': 'Gelöst',
    'status.closed': 'Geschlossen',
    'status.active': 'Aktiv (offen + in Bearbeitung)',
    'status.all': 'Alle Status',

    'notif.bell': 'Support ({n})',
    'notif.mine': 'Du hast {n} Ticket(s) mit einer neuen Antwort.',
    'notif.staff': '{n} Support-Ticket(s) brauchen Aufmerksamkeit (neue Tickets oder Antworten).',
    'notif.open': 'Support öffnen',
    'notif.toast': 'Neue Support-Aktivität ({n})',
};

const DICT = { en: EN, de: DE };
const LS_KEY = 'team-roles.lang';

function translate(lang, key, vars) {
    const raw = (DICT[lang] && DICT[lang][key]) || EN[key] || key;
    return vars ? raw.replace(/\{(\w+)\}/g, (m, name) => (vars[name] != null ? String(vars[name]) : m)) : raw;
}

// Language choice of the user (stored in the browser). Without a choice the panel language decides.
let langOverride = '';
try { langOverride = window.localStorage.getItem(LS_KEY) || ''; } catch (e) { /* storage not available */ }
if (langOverride !== 'en' && langOverride !== 'de') langOverride = '';
const langSubs = new Set();
const langStore = {
    get: () => langOverride,
    subscribe: (cb) => { langSubs.add(cb); return () => langSubs.delete(cb); },
    set: (value) => {
        langOverride = value === 'de' || value === 'en' ? value : '';
        try {
            if (langOverride) window.localStorage.setItem(LS_KEY, langOverride);
            else window.localStorage.removeItem(LS_KEY);
        } catch (e) { /* ignore */ }
        langSubs.forEach((cb) => cb());
    },
};
let lastLang = 'en';

function panelLocale() {
    try {
        const settings = typeof sdk.useSiteSettings === 'function' ? sdk.useSiteSettings() : null;
        const locale = settings && (settings.locale || (settings.data && settings.data.locale));
        if (locale) return String(locale);
    } catch (e) { /* hook not usable here */ }
    return (typeof document !== 'undefined' && document.documentElement && document.documentElement.lang) || '';
}

function useT() {
    const chosen = useSyncExternalStore(langStore.subscribe, langStore.get, langStore.get);
    const panel = panelLocale();
    const lang = chosen || (/^de/i.test(panel) ? 'de' : 'en');
    lastLang = lang;
    return { lang, chosen, setLang: langStore.set, t: (key, vars) => translate(lang, key, vars) };
}

// -------------------------------------------------------------------- helpers

const http = sdk.http;
const api = (base) => ({
    get: (path, params) => http.get(base + path, { params }).then((r) => r.data),
    post: (path, body) => http.post(base + path, body).then((r) => r.data),
    put: (path, body) => http.put(base + path, body).then((r) => r.data),
    blob: (path) => http.get(base + path, { responseType: 'blob' }).then((r) => r.data),
    del: (path) => http.delete(base + path).then((r) => r.data),
});
const admin = api(ADMIN_BASE);
const client = api(CLIENT_BASE);

const toast = {
    ok: (m) => { try { sdk.toast && sdk.toast.success && sdk.toast.success(m); } catch (e) { /* ignore */ } },
    err: (m) => { try { sdk.toast && sdk.toast.error && sdk.toast.error(m); } catch (e) { /* ignore */ } },
};

function errText(e) {
    const data = e && e.response && e.response.data;
    if (data && data.errors) {
        const first = Object.values(data.errors)[0];
        if (Array.isArray(first) && first[0]) return String(first[0]);
    }
    if (data && typeof data.message === 'string' && data.message) return data.message;
    if (data && data.errors === undefined && Array.isArray(data.errors)) return String(data.errors[0]);
    try { if (typeof sdk.httpErrorToHuman === 'function') return sdk.httpErrorToHuman(e); } catch (x) { /* ignore */ }
    return (e && e.message) || 'Error';
}
const status = (e) => (e && e.response && e.response.status) || 0;
const forbidden = (e) => status(e) === 403;

function fieldErrors(e) {
    const errors = e && e.response && e.response.data && e.response.data.errors;
    const out = {};
    if (errors && typeof errors === 'object') {
        Object.keys(errors).forEach((k) => { out[k] = Array.isArray(errors[k]) ? String(errors[k][0]) : String(errors[k]); });
    }
    return out;
}

function fmtDate(lang, iso) {
    if (!iso) return '';
    const d = new Date(iso);
    return isNaN(d.getTime()) ? '' : d.toLocaleString(lang === 'de' ? 'de-DE' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' });
}

const S = {
    stack: { display: 'grid', gap: 16 },
    row: { display: 'flex', gap: 12, alignItems: 'center', flexWrap: 'wrap' },
    box: { border: '1px solid ' + LINE, borderRadius: 8, padding: 16, background: SOFT },
    boxTitle: { margin: '0 0 12px', fontSize: 16, fontWeight: 600 },
    control: { padding: '6px 10px', borderRadius: 6, border: '1px solid ' + LINE, background: 'Canvas', color: 'CanvasText', font: 'inherit', maxWidth: '100%' },
    th: { textAlign: 'left', padding: '8px 10px', borderBottom: '1px solid ' + LINE, fontWeight: 600, fontSize: 13, whiteSpace: 'nowrap' },
    td: { padding: '8px 10px', borderBottom: '1px solid ' + LINE, fontSize: 14, verticalAlign: 'middle' },
    muted: { opacity: 0.7, fontSize: 13 },
    chip: { display: 'inline-block', padding: '2px 8px', borderRadius: 999, border: '1px solid ' + LINE, fontSize: 12, whiteSpace: 'nowrap' },
    danger: { display: 'inline-block', marginLeft: 8, padding: '1px 6px', borderRadius: 4, fontSize: 11, border: '1px solid #d9534f', color: '#d9534f' },
    link: { background: 'none', border: 0, padding: 0, color: 'inherit', textDecoration: 'underline', cursor: 'pointer', font: 'inherit', fontSize: 12, opacity: 0.8 },
    anchor: { color: 'inherit', textDecoration: 'underline' },
    linkBtn: { color: 'inherit', textDecoration: 'underline', background: 'none', border: 0, padding: 0, cursor: 'pointer', font: 'inherit', textAlign: 'left' },
    tab: (on) => ({ padding: '6px 12px', borderRadius: 6, border: '1px solid ' + LINE, background: on ? 'color-mix(in srgb, currentColor 14%, transparent)' : 'transparent', color: 'inherit', cursor: 'pointer', font: 'inherit', fontWeight: on ? 600 : 400, textDecoration: 'none', display: 'inline-block' }),
    btn: (kind, disabled) => ({
        padding: '7px 14px', borderRadius: 6, font: 'inherit', fontWeight: 600, cursor: disabled ? 'not-allowed' : 'pointer', opacity: disabled ? 0.55 : 1,
        border: '1px solid ' + (kind === 'danger' ? '#d9534f' : kind === 'primary' ? 'transparent' : LINE),
        background: kind === 'primary' ? '#2563eb' : 'transparent',
        color: kind === 'primary' ? '#fff' : kind === 'danger' ? '#d9534f' : 'inherit',
    }),
    alert: (type) => {
        const color = { success: '#2e9e5b', danger: '#d9534f', warning: '#d9a400', info: '#3b82f6' }[type] || '#3b82f6';
        return { padding: '10px 14px', borderRadius: 8, border: '1px solid ' + color, background: 'color-mix(in srgb, ' + color + ' 12%, transparent)', fontSize: 14 };
    },
};

// -------------------------------------------------------- small UI components

function Btn({ kind, loading, disabled, onClick, type, children }) {
    const off = Boolean(disabled || loading);
    return h('button', { type: type || 'button', disabled: off, onClick, style: S.btn(kind, off) }, loading ? '…' : children);
}

function Note({ type, children }) {
    return h('div', { style: S.alert(type), role: type === 'danger' ? 'alert' : 'status' }, children);
}

function Loading() {
    const { t } = useT();
    return h('p', { style: { ...S.muted, padding: 12 } }, t('loading'));
}

function Box({ title, children }) {
    return h('section', { style: S.box },
        title ? h('h2', { style: S.boxTitle }, title) : null,
        children);
}

function LangSwitch() {
    const { t, chosen, setLang } = useT();
    return h('label', { style: { ...S.row, gap: 6, fontSize: 13 } },
        h('span', { style: S.muted }, t('lang.label')),
        h('select', { style: S.control, value: chosen, 'aria-label': t('lang.label'), onChange: (e) => setLang(e.target.value) },
            h('option', { value: '' }, t('lang.auto')),
            h('option', { value: 'en' }, 'English'),
            h('option', { value: 'de' }, 'Deutsch')));
}

function Footer() {
    const { t } = useT();
    return h('p', { style: { ...S.muted, marginTop: 16 } }, t('footer'));
}

function Page({ title, children }) {
    return h('div', { style: { ...S.stack, padding: 4 } },
        h('div', { style: { ...S.row, justifyContent: 'space-between' } },
            h('h1', { style: { margin: 0, fontSize: 22, fontWeight: 700 } }, title),
            h(LangSwitch)),
        children);
}

function Pager({ page, meta, what, onPage }) {
    const { t } = useT();
    const total = meta ? meta.total : 0;
    const per = meta ? meta.per_page : 20;
    const pages = Math.max(1, Math.ceil(total / per));
    return h('div', { style: S.row },
        h(Btn, { disabled: page <= 1, onClick: () => onPage(page - 1) }, t('prev')),
        h('span', { style: S.muted }, t('page', { p: page, n: pages, total, what })),
        h(Btn, { disabled: page >= pages, onClick: () => onPage(page + 1) }, t('next')));
}

function roleLabel(t, roles, slug) {
    if (SYSTEM_ROLES.includes(slug)) return t('role.' + slug);
    const found = (roles || []).find((r) => r.slug === slug);
    return found ? found.name : slug;
}

const resLabel = (t, res) => { const k = 'res.' + res.key + '.label'; const v = t(k); return v === k ? res.label : v; };
const resHint = (t, res) => { if (!res.hint) return ''; const k = 'res.' + res.key + '.hint'; const v = t(k); return v === k ? res.hint : v; };

// ===================================================================== TEAM

function PermissionMatrix({ catalog, selected, onToggle, onRow }) {
    const { t } = useT();
    const resources = catalog.resources.filter((r) => r.key !== 'tickets');
    return h('div', { style: { overflowX: 'auto' } },
        h('table', { style: { borderCollapse: 'collapse', width: '100%' } },
            h('thead', null,
                h('tr', null,
                    h('th', { style: S.th }, t('matrix.area')),
                    catalog.actions.map((a) => h('th', { key: a.key, style: { ...S.th, textAlign: 'center' } }, t('act.' + a.key))))),
            h('tbody', null,
                resources.map((res) => h('tr', { key: res.key },
                    h('td', { style: S.td },
                        h('div', null,
                            h('strong', null, resLabel(t, res)),
                            res.danger ? h('span', { style: S.danger }, t('matrix.sensitive')) : null),
                        resHint(t, res) ? h('div', { style: S.muted }, resHint(t, res)) : null,
                        h('div', { style: { marginTop: 4, display: 'flex', gap: 10 } },
                            h('button', { type: 'button', style: S.link, onClick: () => onRow(res, true) }, t('matrix.all')),
                            h('button', { type: 'button', style: S.link, onClick: () => onRow(res, false) }, t('matrix.none')))),
                    catalog.actions.map((a) => {
                        const key = res.key + '.' + a.key;
                        return h('td', { key: a.key, style: { ...S.td, textAlign: 'center' } },
                            res.actions.includes(a.key)
                                ? h('input', { type: 'checkbox', checked: selected.has(key), onChange: () => onToggle(key), 'aria-label': resLabel(t, res) + ': ' + t('act.' + a.key) })
                                : '–');
                    }))))));
}

function TicketRights({ catalog, selected, onToggle, onRow }) {
    const { t } = useT();
    const res = catalog.resources.find((r) => r.key === 'tickets');
    if (!res) return null;
    const notifyOnly = selected.has('tickets.notify') && !selected.has('tickets.view');
    return h('div', { style: { ...S.box, background: 'transparent' } },
        h('div', { style: S.row },
            h('strong', null, t('tk.title')),
            h('button', { type: 'button', style: S.link, onClick: () => onRow(res, true) }, t('matrix.all')),
            h('button', { type: 'button', style: S.link, onClick: () => onRow(res, false) }, t('matrix.none'))),
        h('p', { style: S.muted }, t('tk.intro')),
        h('div', { style: { display: 'grid', gap: 6 } },
            TICKET_KEYS.map((k) => h('label', { key: k, style: { ...S.row, gap: 8 } },
                h('input', { type: 'checkbox', checked: selected.has('tickets.' + k), onChange: () => onToggle('tickets.' + k) }),
                h('span', null, t('tk.' + k))))),
        notifyOnly ? h('p', { style: { ...S.muted, color: '#d9a400' } }, t('tk.notifyNeedsView')) : null);
}

function RoleEditor({ roles, catalog, onSaved }) {
    const { t } = useT();
    const editable = roles.filter((r) => !r.locked);
    const [active, setActive] = useState(editable.length ? editable[0].slug : null);
    const role = editable.find((r) => r.slug === active) || null;
    const [draft, setDraft] = useState(() => new Set(role ? role.permissions : []));
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        setDraft(new Set(role ? role.permissions : []));
    }, [active, roles]);

    if (!role) return h(Note, { type: 'info' }, t('roles.none'));

    const saved = new Set(role.permissions);
    const dirty = saved.size !== draft.size || [...draft].some((k) => !saved.has(k));
    const hasAdminRights = [...draft].some((k) => !k.startsWith('tickets.'));
    const name = roleLabel(t, roles, role.slug);

    const toggle = (key) => setDraft((prev) => {
        const next = new Set(prev);
        next.has(key) ? next.delete(key) : next.add(key);
        return next;
    });
    const setRow = (res, on) => setDraft((prev) => {
        const next = new Set(prev);
        res.actions.forEach((a) => (on ? next.add(res.key + '.' + a) : next.delete(res.key + '.' + a)));
        return next;
    });
    const setAll = (mode) => setDraft(() => {
        const next = new Set();
        catalog.resources.forEach((res) => res.actions.forEach((a) => {
            if (mode === 'all' || (mode === 'view' && a === 'view')) next.add(res.key + '.' + a);
        }));
        return next;
    });

    const save = () => {
        setSaving(true);
        admin.put('/roles/' + role.slug, { permissions: [...draft] })
            .then((data) => { toast.ok(t('roles.saved', { role: name })); onSaved(data); })
            .catch((e) => toast.err(errText(e)))
            .finally(() => setSaving(false));
    };

    return h('div', { style: S.stack },
        h('div', { style: S.row },
            editable.map((r) => h('button', { key: r.slug, type: 'button', style: S.tab(r.slug === active), onClick: () => setActive(r.slug) },
                roleLabel(t, roles, r.slug) + ' (' + r.members + ')'))),
        h(Note, { type: 'info' }, hasAdminRights ? t('roles.admin', { role: name }) : t('roles.noAdmin', { role: name })),
        h('div', { style: S.row },
            h('button', { type: 'button', style: S.link, onClick: () => setAll('all') }, t('roles.allowAll')),
            h('button', { type: 'button', style: S.link, onClick: () => setAll('view') }, t('roles.viewOnly')),
            h('button', { type: 'button', style: S.link, onClick: () => setAll('none') }, t('roles.revokeAll'))),
        h(PermissionMatrix, { catalog, selected: draft, onToggle: toggle, onRow: setRow }),
        h(TicketRights, { catalog, selected: draft, onToggle: toggle, onRow: setRow }),
        h('div', { style: { ...S.row, justifyContent: 'flex-end' } },
            dirty ? h('span', { style: S.muted }, t('roles.unsaved')) : null,
            h(Btn, { kind: 'primary', loading: saving, disabled: !dirty, onClick: save }, t('roles.save'))));
}

function Members({ roles, onChanged }) {
    const { t } = useT();
    const [search, setSearch] = useState('');
    const [query, setQuery] = useState('');
    const [role, setRole] = useState('');
    const [page, setPage] = useState(1);
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);

    const load = useCallback(() => {
        setBusy(true);
        admin.get('/members', { search: query, role, page })
            .then((d) => { setData(d); setError(null); })
            .catch((e) => setError(e))
            .finally(() => setBusy(false));
    }, [query, role, page]);
    useEffect(() => { load(); }, [load]);

    const change = (user, slug) => {
        if (slug === 'owner' && !window.confirm(t('members.confirmOwner', { user: user.username }))) return;
        admin.put('/members/' + user.id, { role: slug })
            .then(() => { toast.ok(t('members.changed', { user: user.username, role: roleLabel(t, roles, slug) })); load(); onChanged(); })
            .catch((e) => { toast.err(errText(e)); load(); });
    };

    if (error && forbidden(error)) return h(Note, { type: 'warning' }, t('err.ownerOnly'));
    if (error) return h(Note, { type: 'danger' }, errText(error));

    return h('div', { style: S.stack },
        h('form', { style: S.row, onSubmit: (e) => { e.preventDefault(); setPage(1); setQuery(search); } },
            h('input', { style: { ...S.control, minWidth: 220 }, placeholder: t('members.search'), 'aria-label': t('members.search'), value: search, onChange: (e) => setSearch(e.target.value) }),
            h('select', { style: S.control, value: role, 'aria-label': t('members.role'), onChange: (e) => { setPage(1); setRole(e.target.value); } },
                h('option', { value: '' }, t('members.allRoles')),
                roles.map((r) => h('option', { key: r.slug, value: r.slug }, roleLabel(t, roles, r.slug)))),
            h(Btn, { type: 'submit' }, t('members.searchBtn')),
            busy ? h('span', { style: S.muted }, '…') : null),
        !data ? h(Loading) : h('div', { style: { overflowX: 'auto' } },
            h('table', { style: { borderCollapse: 'collapse', width: '100%' } },
                h('thead', null, h('tr', null,
                    h('th', { style: S.th }, t('members.id')),
                    h('th', { style: S.th }, t('members.user')),
                    h('th', { style: S.th }, t('members.email')),
                    h('th', { style: S.th }, t('members.role')))),
                h('tbody', null,
                    data.data.length === 0
                        ? h('tr', null, h('td', { style: S.td, colSpan: 4 }, t('members.none')))
                        : data.data.map((u) => h('tr', { key: u.id },
                            h('td', { style: S.td }, u.id),
                            h('td', { style: S.td }, u.username),
                            h('td', { style: S.td }, u.email),
                            h('td', { style: S.td },
                                h('select', { style: S.control, value: u.role, 'aria-label': t('members.role') + ': ' + u.username, onChange: (e) => change(u, e.target.value) },
                                    roles.map((r) => h('option', { key: r.slug, value: r.slug }, roleLabel(t, roles, r.slug)))))))))),
        data ? h(Pager, { page, meta: data.meta, what: t('members.users'), onPage: setPage }) : null);
}

function TeamScreen() {
    const { t } = useT();
    const [state, setState] = useState({ loading: true });

    const load = useCallback(() => {
        Promise.all([admin.get('/roles'), admin.get('/health')])
            .then(([r, health]) => setState({ loading: false, roles: r.roles, catalog: r.catalog, health }))
            .catch((error) => setState({ loading: false, error }));
    }, []);
    useEffect(() => { load(); }, [load]);

    let body;
    if (state.loading) {
        body = h(Loading);
    } else if (state.error) {
        body = forbidden(state.error) ? h(Note, { type: 'warning' }, t('err.ownerOnly')) : h(Note, { type: 'danger' }, errText(state.error));
    } else {
        const { roles, catalog, health } = state;
        const owner = roles.find((r) => r.locked);
        body = h('div', { style: S.stack },
            health.protected
                ? h(Note, { type: 'success' }, t('health.ok', { groups: Object.keys(health.groups).join(', '), owners: health.owners }))
                : h(Note, { type: 'danger' }, t('health.bad')),
            h(Box, { title: t('roles.title') },
                h('div', { style: S.stack },
                    h('p', { style: S.muted }, t('roles.intro', { owner: owner ? roleLabel(t, roles, owner.slug) : 'Owner' })),
                    h(RoleEditor, { roles, catalog, onSaved: (data) => setState((s) => ({ ...s, roles: data.roles, catalog: data.catalog || s.catalog })) }))),
            h(Box, { title: t('members.title') }, h(Members, { roles, onChanged: load })));
    }

    return h(Page, { title: t('team.title') }, h('div', { style: S.stack }, body, h(Footer)));
}

function pickId(props, resource) {
    const params = props && props.data && props.data.params;
    if (params && params.id != null) return String(params.id);
    const r = resource && resource.resource ? resource.resource : null;
    if (r) {
        if (r.id != null) return String(r.id);
        if (r.attributes && r.attributes.id != null) return String(r.attributes.id);
    }
    return null;
}

const useCurrentResource = typeof sdk.useCurrentResource === 'function' ? sdk.useCurrentResource : () => null;

function UserRoleScreen(props) {
    const { t } = useT();
    const current = useCurrentResource();
    const id = pickId(props, current);
    const [state, setState] = useState({ loading: true });
    const [value, setValue] = useState('');
    const [saving, setSaving] = useState(false);

    const load = useCallback(() => {
        if (!id) { setState({ loading: false, noId: true }); return; }
        admin.get('/members/' + id)
            .then((m) => { setState({ loading: false, member: m }); setValue(m.role); })
            .catch((error) => setState({ loading: false, error }));
    }, [id]);
    useEffect(() => { load(); }, [load]);

    const save = () => {
        if (value === 'owner' && !window.confirm(t('urole.confirmOwner'))) return;
        setSaving(true);
        admin.put('/members/' + id, { role: value })
            .then((m) => { setState({ loading: false, member: m }); toast.ok(t('urole.saved')); })
            .catch((e) => toast.err(errText(e)))
            .finally(() => setSaving(false));
    };

    let body;
    if (state.loading) body = h(Loading);
    else if (state.noId) body = h(Note, { type: 'danger' }, t('urole.noId'));
    else if (state.error) body = forbidden(state.error) ? h(Note, { type: 'warning' }, t('err.ownerOnly')) : h(Note, { type: 'danger' }, errText(state.error));
    else {
        const m = state.member;
        body = h(Box, { title: t('urole.of', { user: m.username }) },
            h('div', { style: S.stack },
                h('div', { style: S.row },
                    h('select', { style: S.control, value, 'aria-label': t('members.role'), onChange: (e) => setValue(e.target.value) },
                        m.roles.map((r) => h('option', { key: r.slug, value: r.slug }, roleLabel(t, m.roles, r.slug)))),
                    h(Btn, { kind: 'primary', loading: saving, disabled: value === m.role, onClick: save }, t('urole.save'))),
                h('p', { style: S.muted }, t('urole.current', {
                    role: roleLabel(t, m.roles, m.role),
                    rights: m.role === 'owner' ? t('urole.allRights') : t('urole.nRights', { n: m.permissions.length }),
                })),
                h('p', { style: S.muted }, t('urole.hint')),
                h('a', { href: '/panel/team', style: S.anchor }, t('urole.open'))));
    }

    return h(Page, { title: t('urole.title') }, h('div', { style: S.stack }, body, h(Footer)));
}

// ================================================================== SUPPORT

// One shared poller for the notification counter (bell, banners, support page).
const summaryStore = { data: null, subs: new Set(), timer: null };

function pollSummary() {
    return client.get('/tickets/summary').then((d) => {
        const prev = summaryStore.data;
        summaryStore.data = d;
        if (prev && d.unread > prev.unread) toast.ok(translate(lastLang, 'notif.toast', { n: d.unread }));
        summaryStore.subs.forEach((cb) => cb());
    }).catch(() => { /* keep the last known value */ });
}

function useSummary() {
    useEffect(() => {
        if (!summaryStore.timer) {
            pollSummary();
            summaryStore.timer = window.setInterval(() => { if (!document.hidden) pollSummary(); }, 60000);
        }
        return undefined;
    }, []);
    return useSyncExternalStore(
        (cb) => {
            summaryStore.subs.add(cb);
            return () => {
                summaryStore.subs.delete(cb);
                if (summaryStore.subs.size === 0 && summaryStore.timer) {
                    window.clearInterval(summaryStore.timer);
                    summaryStore.timer = null;
                }
            };
        },
        () => summaryStore.data,
        () => null,
    );
}

const PRIO_COLOR = { low: '#6b7280', normal: '#3b82f6', high: '#d9a400', urgent: '#d9534f' };
const STATUS_COLOR = { open: '#3b82f6', in_progress: '#d9a400', resolved: '#2e9e5b', closed: '#6b7280' };

function Badge({ color, children }) {
    return h('span', { style: { ...S.chip, borderColor: color, color } }, children);
}
const PrioBadge = ({ value }) => { const { t } = useT(); return h(Badge, { color: PRIO_COLOR[value] || '#6b7280' }, t('prio.' + value)); };
const StatusBadge = ({ value }) => { const { t } = useT(); return h(Badge, { color: STATUS_COLOR[value] || '#6b7280' }, t('status.' + value)); };

function NewTicket({ onCreated, onCancel }) {
    const { t } = useT();
    const [subject, setSubject] = useState('');
    const [description, setDescription] = useState('');
    const [priority, setPriority] = useState('normal');
    const [files, setFiles] = useState([]);
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState(false);

    const submit = (ev) => {
        ev.preventDefault();
        const local = {};
        const s = subject.trim().length;
        if (s < 3 || s > 150) local.subject = t('sup.subjectErr');
        const d = description.trim().length;
        if (d < 10 || d > 5000) local.description = t('sup.descErr');
        setErrors(local);
        if (Object.keys(local).length) return;

        setBusy(true);
        client.post('/tickets', withFiles({ subject, description, priority }, files))
            .then((ticket) => { toast.ok(t('sup.created', { id: ticket.id })); pollSummary(); onCreated(ticket.id); })
            .catch((e) => { const fe = fieldErrors(e); if (Object.keys(fe).length) setErrors(fe); else toast.err(errText(e)); })
            .finally(() => setBusy(false));
    };

    const field = (label, control, error) => h('label', { style: { display: 'grid', gap: 4 } },
        h('span', { style: { fontWeight: 600, fontSize: 14 } }, label),
        control,
        error ? h('span', { style: { color: '#d9534f', fontSize: 13 }, role: 'alert' }, error) : null);

    return h(Box, { title: t('sup.new') },
        h('form', { style: S.stack, onSubmit: submit, noValidate: true },
            h('p', { style: S.muted }, t('sup.intro')),
            field(t('sup.subject'), h('input', { style: S.control, value: subject, maxLength: 150, placeholder: t('sup.subjectPh'), onChange: (e) => setSubject(e.target.value) }), errors.subject),
            field(t('sup.desc'), h('textarea', { style: { ...S.control, minHeight: 140, resize: 'vertical' }, value: description, maxLength: 5000, placeholder: t('sup.descPh'), onChange: (e) => setDescription(e.target.value) }), errors.description),
            field(t('sup.priority'),
                h('select', { style: S.control, value: priority, onChange: (e) => setPriority(e.target.value) },
                    PRIORITIES.map((p) => h('option', { key: p, value: p }, t('prio.' + p + '.hint')))),
                errors.priority),
            h(FilePicker, { files, onChange: setFiles, error: errors.files }),
            h('div', { style: S.row },
                h(Btn, { kind: 'primary', type: 'submit', loading: busy }, t('sup.submit')),
                h(Btn, { onClick: onCancel }, t('sup.cancel')))));
}

function TicketList({ scope, onOpen, summaryKey }) {
    const { t, lang } = useT();
    const all = scope === 'all';
    const [statusF, setStatusF] = useState(all ? 'active' : '');
    const [prioF, setPrioF] = useState('');
    const [sort, setSort] = useState(all ? 'priority' : 'activity');
    const [search, setSearch] = useState('');
    const [query, setQuery] = useState('');
    const [page, setPage] = useState(1);
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);

    const load = useCallback(() => {
        client.get('/tickets', { scope, status: statusF, priority: prioF, sort, search: query, page })
            .then((d) => { setData(d); setError(null); })
            .catch((e) => setError(e));
    }, [scope, statusF, prioF, sort, query, page, summaryKey]);
    useEffect(() => { load(); }, [load]);

    if (error) return h(Note, { type: forbidden(error) ? 'warning' : 'danger' }, forbidden(error) ? t('sup.noAccess') : errText(error));

    const filtered = Boolean(statusF || prioF || query);
    return h('div', { style: S.stack },
        h('form', { style: S.row, onSubmit: (e) => { e.preventDefault(); setPage(1); setQuery(search); } },
            h('input', { style: { ...S.control, minWidth: 180 }, placeholder: t('sup.search'), 'aria-label': t('sup.search'), value: search, onChange: (e) => setSearch(e.target.value) }),
            h('select', { style: S.control, value: statusF, 'aria-label': t('sup.col.status'), onChange: (e) => { setPage(1); setStatusF(e.target.value); } },
                h('option', { value: '' }, t('status.all')),
                h('option', { value: 'active' }, t('status.active')),
                STATUSES.map((s) => h('option', { key: s, value: s }, t('status.' + s)))),
            h('select', { style: S.control, value: prioF, 'aria-label': t('sup.col.priority'), onChange: (e) => { setPage(1); setPrioF(e.target.value); } },
                h('option', { value: '' }, t('prio.all')),
                PRIORITIES.map((p) => h('option', { key: p, value: p }, t('prio.' + p)))),
            h('select', { style: S.control, value: sort, onChange: (e) => { setPage(1); setSort(e.target.value); } },
                h('option', { value: 'activity' }, t('sup.sortActivity')),
                h('option', { value: 'priority' }, t('sup.sortPriority'))),
            h(Btn, { type: 'submit' }, t('sup.searchBtn'))),
        !data ? h(Loading) : h('div', { style: { overflowX: 'auto' } },
            h('table', { style: { borderCollapse: 'collapse', width: '100%' } },
                h('thead', null, h('tr', null,
                    h('th', { style: S.th }, '#'),
                    h('th', { style: S.th }, t('sup.col.subject')),
                    h('th', { style: S.th }, t('sup.col.priority')),
                    h('th', { style: S.th }, t('sup.col.status')),
                    all ? h('th', { style: S.th }, t('sup.col.author')) : null,
                    h('th', { style: S.th }, t('sup.col.updated')))),
                h('tbody', null,
                    data.data.length === 0
                        ? h('tr', null, h('td', { style: S.td, colSpan: all ? 6 : 5 }, filtered ? t('sup.emptyFiltered') : t('sup.empty')))
                        : data.data.map((tk) => h('tr', { key: tk.id, style: { cursor: 'pointer', fontWeight: tk.unread ? 700 : 400 }, onClick: () => onOpen(tk.id) },
                            h('td', { style: S.td }, tk.id),
                            h('td', { style: S.td },
                                h('button', { type: 'button', style: { ...S.link, fontSize: 14, textDecoration: 'none', opacity: 1, fontWeight: 'inherit', textAlign: 'left' }, onClick: (e) => { e.stopPropagation(); onOpen(tk.id); } }, tk.subject),
                                tk.unread ? h('span', { style: { ...S.chip, marginLeft: 8, borderColor: '#2563eb', color: '#2563eb' } }, t('sup.unread')) : null),
                            h('td', { style: S.td }, h(PrioBadge, { value: tk.priority })),
                            h('td', { style: S.td }, h(StatusBadge, { value: tk.status })),
                            all ? h('td', { style: S.td }, tk.author.username) : null,
                            h('td', { style: { ...S.td, whiteSpace: 'nowrap' } }, fmtDate(lang, tk.updated_at))))))),
        data ? h(Pager, { page, meta: data.meta, what: t('sup.tickets'), onPage: setPage }) : null);
}

const MAX_FILES = 3;
const MAX_FILE_MB = 5;

// JSON when there are no files, multipart/form-data when there are.
function withFiles(fields, files) {
    if (!files || !files.length) return fields;
    const form = new FormData();
    Object.keys(fields).forEach((k) => form.append(k, fields[k]));
    files.forEach((f) => form.append('files[]', f, f.name));
    return form;
}

function formatSize(bytes) {
    return bytes >= 1048576 ? (bytes / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(bytes / 1024)) + ' KB';
}

function FilePicker({ files, onChange, error }) {
    const { t } = useT();
    const pick = (ev) => {
        const chosen = Array.from((ev.target && ev.target.files) || []);
        try { ev.target.value = ''; } catch (e) { /* ignore */ }
        const big = chosen.find((f) => f.size > MAX_FILE_MB * 1048576);
        if (big) { toast.err(t('att.tooBig', { name: big.name, mb: MAX_FILE_MB })); return; }
        const next = files.concat(chosen);
        if (next.length > MAX_FILES) { toast.err(t('att.tooMany', { n: MAX_FILES })); return; }
        onChange(next);
    };
    return h('div', { style: { display: 'grid', gap: 6 } },
        h('span', { style: { fontWeight: 600, fontSize: 14 } }, t('att.label')),
        h('span', { style: S.muted }, t('att.hint', { n: MAX_FILES, mb: MAX_FILE_MB })),
        files.map((f, i) => h('div', { key: f.name + i, style: { ...S.row, gap: 8 } },
            h('span', { style: { wordBreak: 'break-all' } }, f.name + ' (' + formatSize(f.size) + ')'),
            h('button', { type: 'button', style: S.linkBtn, onClick: () => onChange(files.filter((_, j) => j !== i)) }, t('att.remove')))),
        files.length < MAX_FILES
            ? h('input', { type: 'file', multiple: true, accept: 'image/png,image/jpeg,image/gif,image/webp,.txt,.log,text/plain', onChange: pick })
            : null,
        error ? h('span', { style: { color: '#d9534f', fontSize: 13 }, role: 'alert' }, error) : null);
}

function AttachmentList({ ticketId, files }) {
    const { t } = useT();
    if (!files || !files.length) return null;
    const open = (a) => {
        client.blob('/tickets/' + ticketId + '/attachments/' + a.id)
            .then((blob) => {
                const url = URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.href = url;
                link.download = a.name;
                document.body.appendChild(link);
                link.click();
                link.remove();
                setTimeout(() => URL.revokeObjectURL(url), 10000);
            })
            .catch(() => toast.err(t('att.failed')));
    };
    return h('div', { style: { ...S.row, gap: 8, marginTop: 8 } },
        h('span', { style: S.muted }, t('att.title') + ':'),
        files.map((a) => h('button', { key: a.id, type: 'button', style: S.linkBtn, onClick: () => open(a), title: a.name }, a.name + ' (' + formatSize(a.size) + ')')));
}

function Message({ author, date, body, team, you, ticketId, attachments }) {
    const { t, lang } = useT();
    return h('div', { style: { ...S.box, background: you ? 'transparent' : SOFT } },
        h('div', { style: { ...S.row, gap: 8, marginBottom: 6 } },
            h('strong', null, author),
            team ? h(Badge, { color: '#2563eb' }, t('sup.team')) : null,
            h('span', { style: S.muted }, fmtDate(lang, date))),
        h('div', { style: { whiteSpace: 'pre-wrap', wordBreak: 'break-word', fontSize: 14 } }, body),
        h(AttachmentList, { ticketId, files: attachments }));
}

function TicketDetail({ id, onBack }) {
    const { t, lang } = useT();
    const [state, setState] = useState({ loading: true });
    const [reply, setReply] = useState('');
    const [files, setFiles] = useState([]);
    const [busy, setBusy] = useState('');

    const load = useCallback(() => {
        client.get('/tickets/' + id)
            .then((tk) => { setState({ loading: false, ticket: tk }); pollSummary(); })
            .catch((error) => setState({ loading: false, error }));
    }, [id]);
    useEffect(() => { setState({ loading: true }); load(); }, [load]);

    if (state.loading) return h(Loading);
    if (state.error) {
        return h('div', { style: S.stack },
            h('div', null, h(Btn, { onClick: onBack }, t('sup.back'))),
            h(Note, { type: 'danger' }, status(state.error) === 404 ? errText(state.error) : errText(state.error)));
    }

    const tk = state.ticket;
    const can = tk.can || {};
    const run = (name, promise, okMessage) => {
        setBusy(name);
        return promise
            .then((data) => { if (data) setState({ loading: false, ticket: data }); if (okMessage) toast.ok(okMessage); pollSummary(); return data; })
            .catch((e) => { toast.err(errText(e)); })
            .finally(() => setBusy(''));
    };

    const send = (ev) => {
        ev.preventDefault();
        if (!reply.trim()) return;
        run('reply', client.post('/tickets/' + id + '/messages', withFiles({ body: reply }, files)), t('sup.replied')).then((d) => { if (d) { setReply(''); setFiles([]); } });
    };
    const setStatus = (value) => run('status', client.put('/tickets/' + id, { status: value }), t('sup.statusChanged'));
    const setPriority = (value) => run('priority', client.put('/tickets/' + id, { priority: value }), t('sup.priorityChanged'));
    const remove = () => {
        if (!window.confirm(t('sup.confirmDelete', { id: tk.id }))) return;
        setBusy('delete');
        client.del('/tickets/' + id).then(() => { toast.ok(t('sup.deleted')); pollSummary(); onBack(); }).catch((e) => toast.err(errText(e))).finally(() => setBusy(''));
    };

    return h('div', { style: S.stack },
        h('div', null, h(Btn, { onClick: onBack }, t('sup.back'))),
        h(Box, null,
            h('div', { style: { ...S.row, justifyContent: 'space-between', alignItems: 'flex-start' } },
                h('div', null,
                    h('h2', { style: { margin: 0, fontSize: 20, fontWeight: 700, wordBreak: 'break-word' } }, '#' + tk.id + ' ' + tk.subject),
                    h('p', { style: S.muted }, t('sup.by', { user: tk.author.username, date: fmtDate(lang, tk.created_at) }))),
                h('div', { style: S.row },
                    h(PrioBadge, { value: tk.priority }),
                    h(StatusBadge, { value: tk.status }))),
            (can.manage || can.close || can.reopen || can.delete)
                ? h('div', { style: { ...S.row, marginTop: 12 } },
                    can.manage ? h('label', { style: { ...S.row, gap: 6 } },
                        h('span', { style: S.muted }, t('sup.col.status')),
                        h('select', { style: S.control, value: tk.status, disabled: Boolean(busy), onChange: (e) => setStatus(e.target.value) },
                            STATUSES.map((s) => h('option', { key: s, value: s }, t('status.' + s))))) : null,
                    can.manage ? h('label', { style: { ...S.row, gap: 6 } },
                        h('span', { style: S.muted }, t('sup.col.priority')),
                        h('select', { style: S.control, value: tk.priority, disabled: Boolean(busy), onChange: (e) => setPriority(e.target.value) },
                            PRIORITIES.map((p) => h('option', { key: p, value: p }, t('prio.' + p))))) : null,
                    !can.manage && can.close ? h(Btn, { loading: busy === 'status', onClick: () => setStatus('closed') }, t('sup.close')) : null,
                    !can.manage && can.reopen ? h(Btn, { loading: busy === 'status', onClick: () => setStatus('open') }, t('sup.reopen')) : null,
                    can.delete ? h(Btn, { kind: 'danger', loading: busy === 'delete', onClick: remove }, t('sup.delete')) : null)
                : null),
        h('h3', { style: { margin: 0, fontSize: 16, fontWeight: 600 } }, t('sup.thread')),
        h(Message, { author: tk.author.username, date: tk.created_at, body: tk.description, team: false, you: can.author, ticketId: tk.id, attachments: tk.attachments }),
        (tk.messages || []).map((m) => h(Message, { key: m.id, author: m.author.username, date: m.created_at, body: m.body, team: m.is_staff, you: false, ticketId: tk.id, attachments: m.attachments })),
        can.reply
            ? h('form', { style: S.stack, onSubmit: send },
                h('label', { style: { display: 'grid', gap: 4 } },
                    h('span', { style: { fontWeight: 600, fontSize: 14 } }, t('sup.reply')),
                    h('textarea', { style: { ...S.control, minHeight: 100, resize: 'vertical' }, value: reply, maxLength: 5000, placeholder: t('sup.replyPh'), onChange: (e) => setReply(e.target.value) })),
                h(FilePicker, { files, onChange: setFiles }),
                h('div', null, h(Btn, { kind: 'primary', type: 'submit', loading: busy === 'reply', disabled: !reply.trim() }, t('sup.send'))))
            : (tk.status === 'closed' ? h(Note, { type: 'info' }, t('sup.closedNote')) : null));
}

function MailSetting() {
    const { t } = useT();
    const [on, setOn] = useState(null);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        let alive = true;
        client.get('/tickets/settings').then((d) => { if (alive) setOn(Boolean(d && d.email_notifications)); }).catch(() => {});
        return () => { alive = false; };
    }, []);

    if (on === null) return null;

    const change = (value) => {
        setBusy(true);
        client.put('/tickets/settings', { email_notifications: value })
            .then((d) => {
                const next = Boolean(d && d.email_notifications);
                setOn(next);
                toast.ok(t(next ? 'mail.on' : 'mail.off'));
            })
            .catch((e) => toast.err(errText(e)))
            .then(() => setBusy(false));
    };

    return h('label', { style: { ...S.row, alignItems: 'flex-start', gap: 8, cursor: 'pointer' } },
        h('input', { type: 'checkbox', checked: on, disabled: busy, onChange: (e) => change(e.target.checked), style: { marginTop: 3 } }),
        h('span', null,
            h('span', { style: { fontWeight: 600 } }, t('mail.label')),
            h('span', { style: { ...S.muted, display: 'block' } }, t('mail.hint'))));
}

function SupportScreen() {
    const { t } = useT();
    const summary = useSummary();
    const initial = (() => {
        try {
            const params = new URLSearchParams(window.location.search);
            const id = params.get('ticket');
            if (id && /^\d+$/.test(id)) return { name: 'detail', id: Number(id) };
            return params.get('new') === '1' ? { name: 'new' } : { name: 'list' };
        } catch (e) { return { name: 'list' }; }
    })();
    const [view, setView] = useState(initial);
    const [scope, setScope] = useState('mine');
    const canAll = Boolean(summary && summary.can && summary.can.view);
    const summaryKey = summary ? summary.unread + ':' + summary.active_total : '';

    let body;
    if (view.name === 'new') {
        body = h(NewTicket, { onCreated: (id) => setView({ name: 'detail', id }), onCancel: () => setView({ name: 'list' }) });
    } else if (view.name === 'detail') {
        body = h(TicketDetail, { id: view.id, onBack: () => setView({ name: 'list' }) });
    } else {
        body = h('div', { style: S.stack },
            h('div', { style: S.row },
                h('button', { type: 'button', style: S.tab(scope === 'mine'), onClick: () => setScope('mine') }, t('sup.mine')),
                canAll ? h('button', { type: 'button', style: S.tab(scope === 'all'), onClick: () => setScope('all') },
                    t('sup.all') + (summary.unread_staff ? ' (' + summary.unread_staff + ')' : '')) : null,
                h('span', { style: { flex: 1 } }),
                h(Btn, { kind: 'primary', onClick: () => setView({ name: 'new' }) }, t('sup.new'))),
            h(TicketList, { key: scope, scope: canAll ? scope : 'mine', onOpen: (id) => setView({ name: 'detail', id }), summaryKey }));
    }

    return h(Page, { title: t('sup.title') }, h('div', { style: S.stack }, body, h(MailSetting), h(Footer)));
}

// ================================================================== SLOTS

// Always-visible entry in the top navigation: "Support" (with the unread counter) and "New ticket".
function SupportNav() {
    const { t } = useT();
    const s = useSummary();
    const unread = s && s.unread ? s.unread : 0;
    const title = unread ? (s.unread_staff ? t('notif.staff', { n: s.unread_staff }) : t('notif.mine', { n: s.unread_mine })) : t('sup.title');
    const link = { color: 'inherit', textDecoration: 'none', fontWeight: 600, padding: '4px 6px' };
    return h('span', { style: { display: 'inline-flex', gap: 6, alignItems: 'center', margin: '0 8px', flexWrap: 'wrap' } },
        h('a', { href: '/account/support', title, style: unread ? { ...S.chip, borderColor: '#2563eb', color: '#2563eb', textDecoration: 'none', fontWeight: 600 } : link },
            unread ? t('notif.bell', { n: unread }) : t('sup.title')),
        h('a', { href: '/account/support?new=1', style: link }, '+ ' + t('sup.new')));
}

function SupportBanner() {
    const { t } = useT();
    const s = useSummary();
    if (!s || !s.unread) return null;
    return h('div', { style: { ...S.alert('info'), marginBottom: 12, display: 'flex', gap: 12, alignItems: 'center', flexWrap: 'wrap', justifyContent: 'space-between' } },
        h('div', null,
            s.unread_mine ? h('div', null, t('notif.mine', { n: s.unread_mine })) : null,
            s.unread_staff ? h('div', null, t('notif.staff', { n: s.unread_staff })) : null),
        h('a', { href: '/account/support', style: S.anchor }, t('notif.open')));
}

function RoleBadge({ data }) {
    const { t } = useT();
    const r = data && data.resource;
    const id = r ? (r.id != null ? r.id : r.attributes && r.attributes.id) : null;
    const [label, setLabel] = useState(null);

    useEffect(() => {
        if (id == null) return;
        admin.get('/members/' + id).then((m) => setLabel(m.role)).catch(() => setLabel(null));
    }, [id]);

    return label ? h('span', { style: S.chip }, t('badge.role', { role: roleLabel(t, [], label) })) : null;
}

export default sdk.definePterodactylExtension({
    setup({ screens, slots }) {
        screens.register('team', () => Promise.resolve({ default: TeamScreen }));
        screens.register('user-role', () => Promise.resolve({ default: UserRoleScreen }));
        screens.register('support', () => Promise.resolve({ default: SupportScreen }));
        screens.register('tickets', () => Promise.resolve({ default: SupportScreen }));
        slots.register('panel.users.detail.actions', RoleBadge);
        slots.register('nav.items.after', SupportNav);
        slots.register('dashboard.before', SupportBanner);
        slots.register('panel.overview.before', SupportBanner);
    },
});
