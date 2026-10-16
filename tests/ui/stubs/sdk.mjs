// SDK stub with a small fake backend that mimics the PHP controllers' response shapes.
export const definePterodactylExtension = (d) => d;
export const toast = { success: (m) => (globalThis.__toasts ||= []).push(['ok', m]), error: (m) => (globalThis.__toasts ||= []).push(['err', m]) };
export const httpErrorToHuman = (e) => 'HUMAN:' + (e && e.message);
export const useSiteSettings = () => ({ locale: globalThis.__locale || 'en' });
export const useCurrentResource = () => globalThis.__resource ?? null;

const be = (globalThis.__be = { calls: [], user: { id: 1, name: 'cust' }, can: { view: false, reply: false, manage: false, delete: false, notify: false }, tickets: [], messages: [], seen: {}, nextId: 1, roles: null, health: null, mail: true, members: [], lastPut: null });
const fail = (status, message, errors) => { const e = new Error(message || 'HTTP ' + status); e.response = { status, data: { message, errors } }; return Promise.reject(e); };
const ok = (data) => Promise.resolve({ data });
const names = { 1: 'cust', 2: 'staffy', 3: 'other' };
const unread = (t) => t.last_actor_id !== be.user.id && (be.seen[t.id] ?? 0) < t.seq;
const visible = (t) => t.user_id === be.user.id || be.can.view;
const view = (t) => ({ id: t.id, subject: t.subject, description: t.description, priority: t.priority, status: t.status, unread: unread(t), author: { id: t.user_id, username: names[t.user_id] }, created_at: '2026-10-16T10:00:00+00:00', updated_at: '2026-10-16T11:00:00+00:00',
    attachments: t.files || [], can: { reply: visible(t) && t.status !== 'closed' && (t.user_id === be.user.id || be.can.reply), manage: be.can.manage, close: t.status !== 'closed' && (be.can.manage || t.user_id === be.user.id), reopen: t.status === 'closed', delete: be.can.delete, author: t.user_id === be.user.id } });
const detail = (t) => ({ ...view(t), messages: be.messages.filter(m => m.ticket_id === t.id).map(m => ({ id: m.id, body: m.body, is_staff: m.is_staff, created_at: '2026-10-16T11:00:00+00:00', author: { id: m.user_id, username: names[m.user_id] }, attachments: m.files || [] })) });
const summary = () => { const own = be.tickets.filter(t => t.status !== 'closed' && (t.user_id === be.user.id || be.can.view)); let mine = 0, staff = 0; own.forEach(t => { if (unread(t)) { if (t.user_id === be.user.id) mine++; else if (be.can.view && be.can.notify) staff++; } }); return { unread: mine + staff, unread_mine: mine, unread_staff: staff, active_total: be.can.view ? own.length : 0, can: be.can }; };

function route(method, url, rawBody, params) {
    be.calls.push([method, url, rawBody, params]);
    const isForm = typeof FormData !== 'undefined' && rawBody instanceof FormData;
    const body = isForm ? Object.assign(Object.fromEntries([...rawBody.entries()].filter(([k]) => k !== 'files[]')), { __files: rawBody.getAll('files[]').map(f => ({ name: f.name, size: f.size })) }) : rawBody;
    const u = url.replace(/^\/api\/(admin|client)\/extensions\/team-roles/, '');
    let m;
    if (url.includes('/api/client/')) {
        if (be.fail403 && u !== '/tickets/summary') return fail(403, 'nope');
        if (method === 'GET' && u === '/tickets/summary') return ok(summary());
        if (method === 'GET' && /^\/tickets\/\d+\/attachments\/\d+$/.test(u)) return ok(new Blob(['file-content']));
        if (method === 'GET' && u === '/tickets/settings') return ok({ email_notifications: be.mail });
        if (method === 'PUT' && u === '/tickets/settings') { be.mail = Boolean(body.email_notifications); return ok({ email_notifications: be.mail }); }
        if (method === 'GET' && u === '/tickets') {
            if (params.scope === 'all' && !be.can.view) return fail(403, 'You are not allowed to see all tickets.');
            let list = be.tickets.filter(t => params.scope === 'all' ? true : t.user_id === be.user.id);
            if (params.status === 'active') list = list.filter(t => ['open', 'in_progress'].includes(t.status)); else if (params.status) list = list.filter(t => t.status === params.status);
            if (params.priority) list = list.filter(t => t.priority === params.priority);
            if (params.search) list = list.filter(t => t.subject.includes(params.search));
            return ok({ data: list.map(view), meta: { page: 1, per_page: 20, total: list.length } });
        }
        if (method === 'POST' && u === '/tickets') {
            const errors = {};
            if (!body.subject || body.subject.trim().length < 3) errors.subject = ['The subject must be between 3 and 150 characters.'];
            if (!['low', 'normal', 'high', 'urgent'].includes(body.priority)) errors.priority = ['Unknown priority.'];
            if (Object.keys(errors).length) return fail(422, 'The given data was invalid.', errors);
            const t = { id: be.nextId++, user_id: be.user.id, subject: body.subject.trim(), description: body.description, priority: body.priority, status: 'open', seq: 1, last_actor_id: be.user.id, files: (body.__files || []).map((f, i) => ({ id: 100 + i, name: f.name, kind: 'png', size: f.size })) };
            be.tickets.push(t); be.seen[t.id] = 1; return ok(view(t));
        }
        if ((m = u.match(/^\/tickets\/(\d+)$/))) {
            const t = be.tickets.find(x => x.id === +m[1]);
            if (!t || !visible(t)) return fail(404, 'Ticket not found.');
            if (method === 'GET') { be.seen[t.id] = t.seq; return ok(detail(t)); }
            if (method === 'PUT') { if (body.status) t.status = body.status; if (body.priority) t.priority = body.priority; t.seq++; t.last_actor_id = be.user.id; be.seen[t.id] = t.seq; return ok(detail(t)); }
            if (method === 'DELETE') { be.tickets = be.tickets.filter(x => x !== t); return ok(null); }
        }
        if ((m = u.match(/^\/tickets\/(\d+)\/messages$/)) && method === 'POST') {
            const t = be.tickets.find(x => x.id === +m[1]);
            if (!t || !visible(t)) return fail(404, 'Ticket not found.');
            be.messages.push({ id: be.messages.length + 1, ticket_id: t.id, user_id: be.user.id, body: body.body, is_staff: t.user_id !== be.user.id, files: (body.__files || []).map((f, i) => ({ id: 200 + i, name: f.name, kind: 'txt', size: f.size })) });
            t.seq++; t.last_actor_id = be.user.id; be.seen[t.id] = t.seq; return ok(detail(t));
        }
    } else {
        if (method === 'GET' && u === '/roles') return ok(be.roles);
        if (method === 'GET' && u === '/health') return ok(be.health);
        if (method === 'GET' && u === '/members') return ok({ data: be.members, meta: { page: 1, per_page: 25, total: be.members.length } });
        if (method === 'PUT' && u.startsWith('/roles/')) { be.lastPut = [u, body]; return ok({ roles: be.roles.roles.map(r => r.slug === u.split('/')[2] ? { ...r, permissions: body.permissions } : r) }); }
        if ((m = u.match(/^\/members\/(\d+)$/))) { const mem = be.members.find(x => x.id === +m[1]); if (method === 'GET') return ok({ ...mem, roles: be.roles.roles, permissions: [] }); }
    }
    return fail(500, 'unmocked ' + method + ' ' + url);
}
export const http = {
    get: (url, cfg) => { be.lastCfg = cfg; return route('GET', url, null, (cfg && cfg.params) || {}); },
    post: (url, body) => route('POST', url, body, {}),
    put: (url, body) => route('PUT', url, body, {}),
    delete: (url) => route('DELETE', url, null, {}),
};
