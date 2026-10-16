// UI smoke test for dist/client.js (team management + support tickets) - by floiofficial.
// Runs on a tiny React-like runtime and a fake backend (tests/ui/stubs); no npm packages needed.
//   node tests/ui/run.mjs
import { readFileSync, copyFileSync, mkdtempSync, mkdirSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const EXT = join(HERE, '..', '..', 'extension', 'team-roles');
const WORK = mkdtempSync(join(tmpdir(), 'team-roles-ui-'));
for (const [pkg, file] of [['react', 'react.mjs'], ['@pterodactyl/sdk', 'sdk.mjs']]) {
    const dir = join(WORK, 'node_modules', pkg);
    mkdirSync(dir, { recursive: true });
    writeFileSync(join(dir, 'package.json'), JSON.stringify({ name: pkg, type: 'module', main: 'index.js' }));
    copyFileSync(join(HERE, 'stubs', file), join(dir, 'index.js'));
}
copyFileSync(join(EXT, 'dist', 'client.js'), join(WORK, 'client.mjs'));
const SDK_URL = pathToFileURL(join(WORK, 'node_modules', '@pterodactyl/sdk', 'index.js')).href;
// browser globals
const store = {};
globalThis.window = { localStorage: { getItem: (k) => store[k] ?? null, setItem: (k, v) => { store[k] = String(v); }, removeItem: (k) => { delete store[k]; } }, location: { search: '' }, setInterval: (f, ms) => { globalThis.__interval = f; return 1; }, clearInterval: () => { globalThis.__interval = null; }, confirm: () => true };
let confirmAnswer = true; globalThis.window.confirm = () => confirmAnswer;
globalThis.document = { hidden: false, documentElement: { lang: '' } };
const R = await import(pathToFileURL(join(WORK, 'node_modules', 'react', 'index.js')).href);
const def = (await import(pathToFileURL(join(WORK, 'client.mjs')).href)).default;
const manifest = JSON.parse(readFileSync(join(EXT, 'extension.json'), 'utf8'));
const be = globalThis.__be;

let fails = 0, total = 0;
let last = ''; process.on('exit', () => { if (process.exitCode !== 0 && last) console.log('last check before exit:', last); });
const ok = (n, c) => { last = n; total++; if (!c) { fails++; console.log('FAIL', n); } };

const screens = {}, slots = {};
def.setup({ screens: { register: (id, imp) => { screens[id] = imp; } }, slots: { register: (n, c) => { slots[n] = c; } } });

// ---- manifest <-> setup, documented slots only
for (const s of manifest.ui.screens) ok('screen registered: ' + s.id, typeof screens[s.id] === 'function');
ok('no extra screens', Object.keys(screens).length === manifest.ui.screens.length);
const DOC_SLOTS = ['nav.items.before', 'nav.items.after', 'dashboard.before', 'dashboard.after', 'panel.overview.before', 'panel.overview.after', 'panel.users.before', 'panel.users.after', 'panel.users.detail.actions', 'account.overview.before', 'account.overview.after'];
ok('only documented slots', Object.keys(slots).every(n => DOC_SLOTS.includes(n)));
ok('support screen is an account screen', manifest.ui.screens.find(s => s.id === 'support').area === 'account');
ok('team screen has no top-level nav (lives under Users)', !manifest.ui.screens.find(s => s.id === 'team').nav);
ok('manifest version 1.1.0', manifest.version === '1.1.0');
ok('manifest all English', !/[äöüß]|Rollen|Freund|Kunde/.test(JSON.stringify(manifest)));

// ---- dictionaries
const src = readFileSync(join(EXT, 'dist', 'client.js'), 'utf8');
const grab = (name) => new Function('return ' + src.slice(src.indexOf('const ' + name + ' = {') + ('const ' + name + ' = ').length, src.indexOf('\n};', src.indexOf('const ' + name + ' = {')) + 3))();
const EN = grab('EN'), DE = grab('DE');
const ek = Object.keys(EN), dk = Object.keys(DE);
ok('EN/DE same keys (' + ek.length + ')', ek.length === dk.length && ek.every(k => k in DE));
const ph = (s) => (s.match(/\{\w+\}/g) || []).sort().join(',');
ok('EN/DE same placeholders', ek.every(k => ph(EN[k]) === ph(DE[k])));
const used = [...src.matchAll(/\bt\('([a-zA-Z0-9_.\-]+)'/g)].map(m => m[1]).filter(k => !k.endsWith('.'));
ok('every static t() key exists: ' + used.filter(k => !(k in EN)).join(','), used.every(k => k in EN));
const dyn = [['act.', ['view', 'create', 'edit', 'delete']], ['tk.', ['view', 'reply', 'manage', 'delete', 'notify']], ['prio.', ['low', 'normal', 'high', 'urgent']], ['prio.@.hint', ['low', 'normal', 'high', 'urgent']], ['status.', ['open', 'in_progress', 'resolved', 'closed']], ['role.', ['owner', 'administrator', 'moderator', 'friend', 'user']]];
ok('dynamic keys exist', dyn.every(([p, ks]) => ks.every(k => (p.includes('@') ? p.replace('@', k) : p + k) in EN)));
ok('English text has no German umlauts', ek.every(k => !/[äöüß]/.test(EN[k])));

// ---- helpers
const find = (el, pred, acc = []) => { if (el && typeof el === 'object') { if (pred(el)) acc.push(el); (el.kids || []).forEach(c => find(c, pred, acc)); } return acc; };
const text = (el) => el === null || el === undefined ? '' : typeof el !== 'object' ? String(el) : el.kids.map(text).join(' ');
const T = () => text(R.tree());
const byText = (tag, s) => find(R.tree(), e => e.tag === tag && text(e).trim() === s)[0];
const byTextIn = (tag, s) => find(R.tree(), e => e.tag === tag && text(e).includes(s))[0];
const byPh = (s) => find(R.tree(), e => (e.tag === 'input' || e.tag === 'textarea') && e.props.placeholder === s)[0];
const click = async (el) => { if (!el) throw new Error('click: element missing'); await el.props.onClick({ stopPropagation() {}, preventDefault() {} }); await R.settle(); };
const type = async (el, v) => { if (!el) throw new Error('type: element missing'); el.props.onChange({ target: { value: v } }); await R.settle(); };
const submit = async (form) => { await form.props.onSubmit({ preventDefault() {} }); await R.settle(); };
const resetLang = async () => { const el = find(R.tree() || {}, e => e.tag === 'select' && e.props['aria-label'] === 'Language' || e.props && e.props['aria-label'] === 'Sprache')[0]; if (el) await type(el, ''); };
const reset = () => { R.unmount(); be.calls.length = 0; globalThis.__toasts = []; globalThis.__locale = 'en'; for (const k of Object.keys(store)) delete store[k]; document.documentElement.lang = ''; };
const mountScreen = async (Comp, props) => { R.mount(R.createElement(Comp, props || {})); await R.settle(); };
const Support = (await screens.support()).default, Team = (await screens.team()).default, UserRole = (await screens['user-role']()).default;
const seed = () => { be.tickets = [ { id: 10, user_id: 3, subject: 'Server crashed', description: 'It keeps crashing every hour', priority: 'urgent', status: 'open', seq: 1, last_actor_id: 3 }, { id: 11, user_id: 1, subject: 'Billing question', description: 'Where is my invoice?', priority: 'low', status: 'open', seq: 2, last_actor_id: 2 } ]; be.messages = [{ id: 1, ticket_id: 11, user_id: 2, body: 'We are looking into it', is_staff: true }]; be.seen = { 10: 0, 11: 1 }; be.nextId = 12; };
const asCustomer = () => { be.user = { id: 1 }; be.can = { view: false, reply: false, manage: false, delete: false, notify: false }; };
const asStaff = (extra) => { be.user = { id: 2 }; be.can = { view: true, reply: true, manage: false, delete: false, notify: true, ...(extra || {}) }; };

// =========================== customer flow
reset(); seed(); asCustomer();
await mountScreen(Support);
let t = T();
ok('customer: English by default', t.includes('My tickets') && t.includes('New ticket') && t.includes('by floiofficial'));
ok('customer: no "All tickets" tab', !t.includes('All tickets'));
ok('customer: sees own ticket only', t.includes('Billing question') && !t.includes('Server crashed'));
ok('customer: unread marker on own ticket with team reply', t.includes('New activity'));
await click(byText('button', 'New ticket'));
t = T();
ok('form fields: subject / problem / priority', t.includes('Subject') && t.includes('What is the problem?') && t.includes('Priority'));
const sel = find(R.tree(), e => e.tag === 'select').find(s => (s.kids || []).some(o => text(o).includes('Urgent - everything is down')));
ok('priority select has 4 options', sel && sel.kids.length === 4 && sel.props.value === 'normal');
await type(byPh('Short summary of the problem'), 'ab');
await type(byPh('Describe the problem in as much detail as you can…'), 'short');
await submit(find(R.tree(), e => e.tag === 'form')[0]);
t = T();
ok('client-side validation shows both errors, nothing sent', t.includes('between 3 and 150') && t.includes('10 to 5000') && !be.calls.some(c => c[0] === 'POST'));
await type(byPh('Short summary of the problem'), 'Cannot log in to server');
await type(byPh('Describe the problem in as much detail as you can…'), 'The console says connection refused since this morning.');
await type(find(R.tree(), e => e.tag === 'select' && e.props.value === 'normal')[0], 'high');
await submit(find(R.tree(), e => e.tag === 'form')[0]);
const post = be.calls.find(c => c[0] === 'POST' && c[1].endsWith('/tickets'));
ok('POST sends subject, description and chosen priority', post && post[2].subject === 'Cannot log in to server' && post[2].priority === 'high' && post[2].description.includes('connection refused'));
t = T();
ok('after submit the new ticket is opened', t.includes('#12 Cannot log in to server') && t.includes('High') && t.includes('Open'));
ok('toast on create', globalThis.__toasts.some(x => x[1] === 'Ticket #12 created.'));
// reply
await type(find(R.tree(), e => e.tag === 'textarea')[0], 'Update: still failing');
await submit(find(R.tree(), e => e.tag === 'form')[0]);
ok('reply posted & shown', T().includes('Update: still failing'));
ok('author without manage can close but not delete / no priority select', !!byText('button', 'Close ticket') && !byText('button', 'Delete ticket') && !T().includes('Priority High'));
await click(byText('button', 'Close ticket'));
ok('close sends status=closed', be.calls.some(c => c[0] === 'PUT' && c[2] && c[2].status === 'closed'));
ok('closed ticket: reply form hidden, note shown', T().includes('This ticket is closed') && find(R.tree(), e => e.tag === 'textarea').length === 0);
ok('reopen offered', !!byText('button', 'Reopen ticket'));
// language switch
const langSel = find(R.tree(), e => e.tag === 'select' && e.props['aria-label'] === 'Language')[0];
await type(langSel, 'de');
ok('switch to German works + stored', T().includes('Unterhaltung') && store['team-roles.lang'] === 'de' && T().includes('von floiofficial'));
await type(find(R.tree(), e => e.tag === 'select' && e.props['aria-label'] === 'Sprache')[0], 'en');
ok('switch back to English', T().includes('Conversation'));
await resetLang();
ok('automatic option clears stored choice', !('team-roles.lang' in store));
// back to list
await click(byText('button', '← Back to tickets'));
ok('back to list', T().includes('My tickets'));

// panel locale default
reset(); seed(); asCustomer(); globalThis.__locale = 'de';
await mountScreen(Support);
ok('panel locale de => German default', T().includes('Meine Tickets') && T().includes('Neues Ticket'));
reset(); seed(); asCustomer(); document.documentElement.lang = 'fr';
await mountScreen(Support);
ok('unknown locale => English', T().includes('My tickets'));
reset(); seed(); asCustomer(); globalThis.__locale = 'de'; store['team-roles.lang'] = 'en';
{ R.unmount(); }
// (override is read at module load; verified through switcher above)

// =========================== staff flow
reset(); seed(); asStaff({ manage: true, delete: true });
await mountScreen(Support);
t = T();
ok('staff: "All tickets" tab with unread badge (1 other-person ticket + counted own)', t.includes('All tickets'));
await click(byTextIn('button', 'All tickets'));
t = T();
ok('staff all view: both tickets + author column', t.includes('Server crashed') && t.includes('Billing question') && t.includes('Author') && t.includes('other'));
const gets = be.calls.filter(c => c[0] === 'GET' && c[1].endsWith('/tickets')).pop();
ok('all view requests scope=all, active filter, priority sort', gets[3].scope === 'all' && gets[3].status === 'active' && gets[3].sort === 'priority');
await click(find(R.tree(), e => e.tag === 'button' && text(e).trim() === 'Server crashed')[0]);
t = T();
ok('staff opens foreign ticket', t.includes('#10 Server crashed') && t.includes('It keeps crashing'));
ok('staff manage UI: status + priority selects + delete', find(R.tree(), e => e.tag === 'select').length >= 2 && !!byText('button', 'Delete ticket'));
const prioSel = find(R.tree(), e => e.tag === 'select' && e.props.value === 'urgent')[0];
await type(prioSel, 'low');
ok('priority change sent', be.calls.some(c => c[0] === 'PUT' && c[2].priority === 'low'));
await type(find(R.tree(), e => e.tag === 'textarea')[0], 'We are on it');
await submit(find(R.tree(), e => e.tag === 'form')[0]);
ok('staff reply shows Team badge', T().includes('We are on it') && T().includes('Team'));
await click(byText('button', 'Delete ticket'));
ok('delete sends DELETE after confirm and returns to list', be.calls.some(c => c[0] === 'DELETE') && T().includes('My tickets'));
// declined confirm
reset(); seed(); asStaff({ delete: true }); confirmAnswer = false;
await mountScreen(Support);
await click(byTextIn('button', 'All tickets'));
await click(find(R.tree(), e => e.tag === 'button' && text(e).trim() === 'Server crashed')[0]);
await click(byText('button', 'Delete ticket'));
ok('declined confirm => nothing deleted', !be.calls.some(c => c[0] === 'DELETE'));
confirmAnswer = true;
// view-only staff cannot reply form/manage
reset(); seed(); asStaff({ reply: false });
await mountScreen(Support);
await click(byTextIn('button', 'All tickets'));
await click(find(R.tree(), e => e.tag === 'button' && text(e).trim() === 'Server crashed')[0]);
ok('view-only staff: no reply form, no delete/manage', find(R.tree(), e => e.tag === 'textarea').length === 0 && !byText('button', 'Delete ticket'));
// 404 for invisible
reset(); seed(); asCustomer();
await mountScreen(Support);
window.location.search = '?ticket=10'; reset(); seed(); asCustomer();
await mountScreen(Support);
ok('deep link ?ticket=10 as customer => ticket not found shown', T().includes('Ticket not found.'));
window.location.search = '';
// 403 on scope=all
reset(); seed(); asCustomer();
await mountScreen(Support);

// =========================== notifications
reset(); seed(); asCustomer();
const slotRun = async (name, props) => { R.unmount(); await mountScreen(slots[name], props); return T(); };
let bell = await slotRun('nav.items.after');
ok('bell shows count for customer with new reply', bell.includes('Support (1)'));
let banner = await slotRun('dashboard.before');
ok('banner (customer): mine text + link', banner.includes('1 ticket(s) with a new reply') && find(R.tree(), e => e.tag === 'a' && e.props.href === '/account/support').length === 1);
ok('overview banner also registered', typeof slots['panel.overview.before'] === 'function');
reset(); seed(); asStaff({ notify: true });
banner = await slotRun('dashboard.before');
ok('staff with notify: sees staff text', banner.includes('need attention'));
reset(); seed(); asStaff({ notify: false });
banner = await slotRun('dashboard.before');
ok('staff WITHOUT notify: no banner for other people\'s tickets (only own unread)', !banner.includes('need attention'));
reset(); seed(); asCustomer(); be.seen[11] = 2;
bell = await slotRun('nav.items.after');
ok('no unread => nothing rendered', bell === '');
// poller: toast on increase, timer only when visible
reset(); seed(); asCustomer(); be.seen[11] = 2;
await mountScreen(slots['nav.items.after']);
ok('poller registered with interval', typeof globalThis.__interval === 'function');
be.tickets.push({ id: 20, user_id: 1, subject: 'x', description: 'y', priority: 'low', status: 'open', seq: 2, last_actor_id: 2 });
document.hidden = true; const before = be.calls.length; globalThis.__interval(); await R.settle();
ok('hidden tab does not poll', be.calls.length === before);
document.hidden = false; globalThis.__interval(); await R.settle();
ok('visible tab polls, bell updates, toast fired', T().includes('Support (1)') && globalThis.__toasts.some(x => x[1].includes('New support activity (1)')));
R.unmount();
ok('timer cleared after unmount', globalThis.__interval === null);

// =========================== team management
const catalog = { actions: [{ key: 'view' }, { key: 'create' }, { key: 'edit' }, { key: 'delete' }], ticketActions: [], resources: [
  { key: 'users', label: 'Users', hint: '', danger: false, actions: ['view', 'create', 'edit', 'delete'] },
  { key: 'tickets', label: 'Support tickets', hint: '', danger: false, actions: ['view', 'reply', 'manage', 'delete', 'notify'] } ] };
be.roles = { catalog, roles: [
  { slug: 'owner', name: 'Owner', rank: 100, locked: true, permissions: [], staff: true, members: 1 },
  { slug: 'moderator', name: 'Moderator', rank: 60, locked: false, permissions: ['users.view', 'tickets.view', 'tickets.reply', 'tickets.notify'], staff: true, members: 2 },
  { slug: 'friend', name: 'Friend', rank: 20, locked: false, permissions: ['tickets.view', 'tickets.reply', 'tickets.notify'], staff: false, members: 1 } ] };
be.health = { protected: true, groups: { 'admin-api': true }, owners: 1 };
be.members = [{ id: 1, username: 'floi', email: 'f@x.de', role: 'owner' }, { id: 2, username: 'mod', email: 'm@x.de', role: 'moderator' }];
reset();
await mountScreen(Team);
t = T();
ok('team screen: tabs Users / Team management / Support tickets', t.includes('Users') && t.includes('Team management') && t.includes('Support tickets'));
const hrefs = find(R.tree(), e => e.tag === 'a').map(a => a.props.href);
ok('tab links', hrefs.includes('/panel/users') && hrefs.includes('/panel/team') && hrefs.includes('/account/support'));
ok('active tab marked', find(R.tree(), e => e.tag === 'a' && e.props['aria-current'] === 'page').length === 1);
ok('health OK text', t.includes('Permission check is active (admin-api). Owners: 1.'));
ok('ticket rights block with 5 options', ['See all tickets', 'Reply to tickets', 'Change status & priority', 'Delete tickets', 'Get notified in the panel and by e-mail (new tickets & replies)'].every(s => t.includes(s)));
ok('role tabs show Moderator (2), no Owner tab', t.includes('Moderator (2)') && t.includes('Friend (1)') && !t.includes('Owner (1)'));
ok('moderator has admin rights hint', t.includes('gets access to the admin area'));
// ticket checkboxes preselected
const tkBoxes = () => find(R.tree(), e => e.tag === 'label' && /^(See all tickets|Reply to tickets|Change status & priority|Delete tickets|Get notified)/.test(text(e).trim())).map(l => find(l, x => x.tag === 'input')[0]);
let cb = tkBoxes();
ok('moderator ticket checkboxes: view, reply, notify on; manage, delete off', cb.map(b => b.props.checked).join() === 'true,true,false,false,true');
// switch to friend: only ticket rights => "no admin" hint
await click(byTextIn('button', 'Friend (1)'));
ok('friend with ticket rights only => no admin access hint', T().includes('has no admin rights') && T().includes('never grant admin access'));
// toggle manage and save
await click({ props: { onClick: () => cb = null } }).catch(() => {});
cb = tkBoxes();
cb[2].props.onChange(); await R.settle();
ok('unsaved changes shown', T().includes('Unsaved changes'));
await click(byText('button', 'Save rights'));
ok('save sends role + permissions incl. tickets.manage', be.lastPut && be.lastPut[0] === '/roles/friend' && be.lastPut[1].permissions.includes('tickets.manage') && be.lastPut[1].permissions.includes('tickets.view'));
// notify without view hint
cb = tkBoxes(); cb[0].props.onChange(); await R.settle();
ok('notify without view warns', T().includes('only work together with'));
// 403
reset(); const saveRoles = be.roles; be.roles = null;
http403: {
    const sdk = await import(SDK_URL);
    const orig = sdk.http.get; sdk.http.get = () => { const e = new Error('x'); e.response = { status: 403, data: {} }; return Promise.reject(e); };
    await mountScreen(Team);
    ok('team screen non-owner => owner-only notice', T().includes('Only the Owner may manage roles and rights.'));
    sdk.http.get = orig;
}
be.roles = saveRoles;
// members
reset(); await mountScreen(Team);
ok('members listed', T().includes('floi') && T().includes('m@x.de'));
const memSel = find(R.tree(), e => e.tag === 'select' && (e.props['aria-label'] || '').startsWith('Role: mod'))[0];
ok('member role select', !!memSel && memSel.props.value === 'moderator');
// German team screen
reset(); globalThis.__locale = 'de'; await mountScreen(Team);
ok('team screen German', T().includes('Teamverwaltung') && T().includes('Rollen & Rechte') && T().includes('Alle Tickets sehen'));

// user role tab + badge + users bar
reset(); globalThis.__resource = { resource: { id: 2 } };
await mountScreen(UserRole, { data: { params: { id: '2' } } });
ok('user role tab', T().includes('Role of mod') && T().includes('Current: Moderator'));
globalThis.__resource = null;
reset(); await mountScreen(slots['panel.users.detail.actions'], { data: { resource: { id: 2 } } });
ok('role badge', T().includes('Role: Moderator'));
reset(); await mountScreen(slots['panel.users.before']);
ok('users bar renders tab bar with active Users', find(R.tree(), e => e.tag === 'a' && e.props['aria-current'] === 'page').length === 1 && T().includes('Team management'));

console.log(fails === 0 ? 'OK' : 'FAILED', total + ' checks, ' + fails + ' failed');
process.exit(fails ? 1 : 0);
