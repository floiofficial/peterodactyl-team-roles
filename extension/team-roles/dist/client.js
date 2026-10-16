// Teamverwaltung (team-roles) - von floiofficial.
// Handgeschriebenes ES-Modul: React und das SDK stellt das Panel zur Laufzeit bereit
// (Import-Map), deshalb ist kein Build-Schritt noetig.
import { createElement as h, useCallback, useEffect, useState } from 'react';
import {
    Alert,
    Button,
    PageContentBlock,
    PanelLink,
    Spinner,
    TitledGreyBox,
    definePterodactylExtension,
    http,
    httpErrorToHuman,
    toast,
    useCurrentResource,
} from '@pterodactyl/sdk';

const BASE = '/api/admin/extensions/team-roles';
const LINE = 'color-mix(in srgb, currentColor 18%, transparent)';

const S = {
    stack: { display: 'grid', gap: 16 },
    row: { display: 'flex', gap: 12, alignItems: 'center', flexWrap: 'wrap' },
    control: { padding: '6px 10px', borderRadius: 6, border: '1px solid ' + LINE, background: 'Canvas', color: 'CanvasText', font: 'inherit' },
    th: { textAlign: 'left', padding: '8px 10px', borderBottom: '1px solid ' + LINE, fontWeight: 600, fontSize: 13, whiteSpace: 'nowrap' },
    td: { padding: '8px 10px', borderBottom: '1px solid ' + LINE, fontSize: 14, verticalAlign: 'middle' },
    muted: { opacity: 0.7, fontSize: 13 },
    chip: { display: 'inline-block', padding: '2px 8px', borderRadius: 999, border: '1px solid ' + LINE, fontSize: 12 },
    danger: { display: 'inline-block', marginLeft: 8, padding: '1px 6px', borderRadius: 4, fontSize: 11, border: '1px solid #d9534f', color: '#d9534f' },
    link: { background: 'none', border: 0, padding: 0, color: 'inherit', textDecoration: 'underline', cursor: 'pointer', font: 'inherit', fontSize: 12, opacity: 0.8 },
    tab: (on) => ({ padding: '6px 12px', borderRadius: 6, border: '1px solid ' + LINE, background: on ? 'color-mix(in srgb, currentColor 14%, transparent)' : 'transparent', color: 'inherit', cursor: 'pointer', font: 'inherit', fontWeight: on ? 600 : 400 }),
};

const get = (path, params) => http.get(BASE + path, { params }).then((r) => r.data);
const put = (path, body) => http.put(BASE + path, body).then((r) => r.data);
const forbidden = (e) => Boolean(e && e.response && e.response.status === 403);

function Footer() {
    return h('p', { style: { ...S.muted, marginTop: 16 } }, 'Teamverwaltung · von floiofficial');
}

function Forbidden() {
    return h(Alert, { type: 'warning' }, 'Nur der Owner darf Rollen und Rechte verwalten.');
}

function roleLabel(roles, slug) {
    const found = (roles || []).find((r) => r.slug === slug);
    return found ? found.name : slug;
}

// ---------------------------------------------------------------- Rechte-Matrix

function PermissionMatrix({ catalog, selected, onToggle, onRow }) {
    return h('div', { style: { overflowX: 'auto' } },
        h('table', { style: { borderCollapse: 'collapse', width: '100%' } },
            h('thead', null,
                h('tr', null,
                    h('th', { style: S.th }, 'Bereich'),
                    catalog.actions.map((a) => h('th', { key: a.key, style: { ...S.th, textAlign: 'center' } }, a.label)))),
            h('tbody', null,
                catalog.resources.map((res) => h('tr', { key: res.key },
                    h('td', { style: S.td },
                        h('div', null,
                            h('strong', null, res.label),
                            res.danger ? h('span', { style: S.danger }, 'heikel') : null),
                        res.hint ? h('div', { style: S.muted }, res.hint) : null,
                        h('div', { style: { marginTop: 4, display: 'flex', gap: 10 } },
                            h('button', { type: 'button', style: S.link, onClick: () => onRow(res, true) }, 'alle'),
                            h('button', { type: 'button', style: S.link, onClick: () => onRow(res, false) }, 'keine'))),
                    catalog.actions.map((a) => {
                        const key = res.key + '.' + a.key;
                        return h('td', { key: a.key, style: { ...S.td, textAlign: 'center' } },
                            res.actions.includes(a.key)
                                ? h('input', {
                                    type: 'checkbox',
                                    checked: selected.has(key),
                                    onChange: () => onToggle(key),
                                    'aria-label': res.label + ': ' + a.label,
                                })
                                : '–');
                    }))))));
}

function RoleEditor({ roles, catalog, onSaved }) {
    const editable = roles.filter((r) => !r.locked);
    const [active, setActive] = useState(editable.length ? editable[0].slug : null);
    const role = editable.find((r) => r.slug === active) || null;
    const [draft, setDraft] = useState(() => new Set(role ? role.permissions : []));
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        setDraft(new Set(role ? role.permissions : []));
    }, [active, roles]);

    if (!role) {
        return h(Alert, { type: 'info' }, 'Keine bearbeitbaren Rollen vorhanden.');
    }

    const saved = new Set(role.permissions);
    const dirty = saved.size !== draft.size || [...draft].some((k) => !saved.has(k));

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
        put('/roles/' + role.slug, { permissions: [...draft] })
            .then((data) => {
                toast.success('Rechte für "' + role.name + '" gespeichert.');
                onSaved(data);
            })
            .catch((e) => toast.error(httpErrorToHuman(e)))
            .finally(() => setSaving(false));
    };

    return h('div', { style: S.stack },
        h('div', { style: S.row },
            editable.map((r) => h('button', { key: r.slug, type: 'button', style: S.tab(r.slug === active), onClick: () => setActive(r.slug) },
                r.name + ' (' + r.members + ')'))),
        h(Alert, { type: 'info' }, draft.size > 0
            ? 'Mit mindestens einem Recht bekommt "' + role.name + '" Zugang zum Admin-Bereich - aber nur im Rahmen der angehakten Rechte.'
            : '"' + role.name + '" hat keine Rechte und damit keinen Zugang zum Admin-Bereich.'),
        h('div', { style: S.row },
            h('button', { type: 'button', style: S.link, onClick: () => setAll('all') }, 'Alles erlauben'),
            h('button', { type: 'button', style: S.link, onClick: () => setAll('view') }, 'Nur Ansehen'),
            h('button', { type: 'button', style: S.link, onClick: () => setAll('none') }, 'Alles entziehen')),
        h(PermissionMatrix, { catalog, selected: draft, onToggle: toggle, onRow: setRow }),
        h('div', { style: { ...S.row, justifyContent: 'flex-end' } },
            dirty ? h('span', { style: S.muted }, 'Ungespeicherte Änderungen') : null,
            h(Button, { isLoading: saving, disabled: !dirty, onClick: save }, 'Rechte speichern')));
}

// ------------------------------------------------------------------ Mitglieder

function Members({ roles, onChanged }) {
    const [search, setSearch] = useState('');
    const [query, setQuery] = useState('');
    const [role, setRole] = useState('');
    const [page, setPage] = useState(1);
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);

    const load = useCallback(() => {
        setBusy(true);
        get('/members', { search: query, role, page })
            .then((d) => { setData(d); setError(null); })
            .catch((e) => setError(e))
            .finally(() => setBusy(false));
    }, [query, role, page]);

    useEffect(() => { load(); }, [load]);

    const change = (user, slug) => {
        if (slug === 'owner' && !window.confirm('"' + user.username + '" wirklich zum Owner machen? Owner haben ALLE Rechte.')) {
            return;
        }
        put('/members/' + user.id, { role: slug })
            .then(() => {
                toast.success(user.username + ' ist jetzt ' + roleLabel(roles, slug) + '.');
                load();
                onChanged();
            })
            .catch((e) => { toast.error(httpErrorToHuman(e)); load(); });
    };

    if (error && forbidden(error)) return h(Forbidden);
    if (error) return h(Alert, { type: 'danger' }, httpErrorToHuman(error));

    const total = data ? data.meta.total : 0;
    const perPage = data ? data.meta.per_page : 25;
    const pages = Math.max(1, Math.ceil(total / perPage));

    return h('div', { style: S.stack },
        h('form', { style: S.row, onSubmit: (e) => { e.preventDefault(); setPage(1); setQuery(search); } },
            h('input', { style: { ...S.control, minWidth: 220 }, placeholder: 'Benutzername oder E-Mail suchen', value: search, onChange: (e) => setSearch(e.target.value) }),
            h('select', { style: S.control, value: role, onChange: (e) => { setPage(1); setRole(e.target.value); } },
                h('option', { value: '' }, 'Alle Rollen'),
                roles.map((r) => h('option', { key: r.slug, value: r.slug }, r.name))),
            h(Button, { type: 'submit' }, 'Suchen'),
            busy ? h(Spinner, { size: 'small' }) : null),
        !data ? h(Spinner, { centered: true }) : h('div', { style: { overflowX: 'auto' } },
            h('table', { style: { borderCollapse: 'collapse', width: '100%' } },
                h('thead', null, h('tr', null,
                    h('th', { style: S.th }, 'ID'),
                    h('th', { style: S.th }, 'Benutzer'),
                    h('th', { style: S.th }, 'E-Mail'),
                    h('th', { style: S.th }, 'Rolle'))),
                h('tbody', null,
                    data.data.length === 0
                        ? h('tr', null, h('td', { style: S.td, colSpan: 4 }, 'Keine Benutzer gefunden.'))
                        : data.data.map((u) => h('tr', { key: u.id },
                            h('td', { style: S.td }, u.id),
                            h('td', { style: S.td }, u.username),
                            h('td', { style: S.td }, u.email),
                            h('td', { style: S.td },
                                h('select', { style: S.control, value: u.role, onChange: (e) => change(u, e.target.value) },
                                    roles.map((r) => h('option', { key: r.slug, value: r.slug }, r.name))))))))),
        h('div', { style: S.row },
            h(Button, { isSecondary: true, disabled: page <= 1, onClick: () => setPage(page - 1) }, 'Zurück'),
            h('span', { style: S.muted }, 'Seite ' + page + ' von ' + pages + ' · ' + total + ' Benutzer'),
            h(Button, { isSecondary: true, disabled: page >= pages, onClick: () => setPage(page + 1) }, 'Weiter')));
}

// --------------------------------------------------------------------- Screens

function TeamScreen() {
    const [state, setState] = useState({ loading: true });

    const load = useCallback(() => {
        Promise.all([get('/roles'), get('/health')])
            .then(([r, health]) => setState({ loading: false, roles: r.roles, catalog: r.catalog, health }))
            .catch((error) => setState({ loading: false, error }));
    }, []);
    useEffect(() => { load(); }, [load]);

    let body;
    if (state.loading) {
        body = h(Spinner, { centered: true });
    } else if (state.error) {
        body = forbidden(state.error) ? h(Forbidden) : h(Alert, { type: 'danger' }, httpErrorToHuman(state.error));
    } else {
        const { roles, catalog, health } = state;
        const owner = roles.find((r) => r.locked);
        body = h('div', { style: S.stack },
            health.protected
                ? h(Alert, { type: 'success' }, 'Rechteprüfung ist aktiv (' + Object.keys(health.groups).join(', ') + '). Owner: ' + health.owners + '.')
                : h(Alert, { type: 'danger' }, 'ACHTUNG: Die Rechteprüfung hängt nicht in allen API-Gruppen! Moderatoren/Administratoren hätten dann vollen Zugriff. Bitte Extension neu aktivieren oder tools/team-tool.php demote-staff ausführen.'),
            h(TitledGreyBox, { title: 'Rollen & Rechte' },
                h('div', { style: S.stack },
                    h('p', { style: S.muted }, (owner ? owner.name : 'Owner') + ' hat immer alle Rechte und ist nicht einstellbar. Freund und Benutzer/Kunde haben standardmäßig keinen Admin-Zugang. Extensions und diese Teamverwaltung kann nur der Owner bedienen.'),
                    h(RoleEditor, { roles, catalog, onSaved: (data) => setState((s) => ({ ...s, roles: data.roles })) }))),
            h(TitledGreyBox, { title: 'Mitglieder' },
                h(Members, { roles, onChanged: load })),
            h(Footer));
    }

    return h(PageContentBlock, { title: 'Teamverwaltung' }, body);
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

function UserRoleScreen(props) {
    const current = useCurrentResource();
    const id = pickId(props, current);
    const [state, setState] = useState({ loading: true });
    const [value, setValue] = useState('');
    const [saving, setSaving] = useState(false);

    const load = useCallback(() => {
        if (!id) { setState({ loading: false, noId: true }); return; }
        get('/members/' + id)
            .then((m) => { setState({ loading: false, member: m }); setValue(m.role); })
            .catch((error) => setState({ loading: false, error }));
    }, [id]);
    useEffect(() => { load(); }, [load]);

    const save = () => {
        if (value === 'owner' && !window.confirm('Diesen Benutzer wirklich zum Owner machen? Owner haben ALLE Rechte.')) return;
        setSaving(true);
        put('/members/' + id, { role: value })
            .then((m) => { setState({ loading: false, member: m }); toast.success('Rolle gespeichert.'); })
            .catch((e) => toast.error(httpErrorToHuman(e)))
            .finally(() => setSaving(false));
    };

    let body;
    if (state.loading) body = h(Spinner, { centered: true });
    else if (state.noId) body = h(Alert, { type: 'danger' }, 'Benutzer-ID konnte nicht ermittelt werden.');
    else if (state.error) body = forbidden(state.error) ? h(Forbidden) : h(Alert, { type: 'danger' }, httpErrorToHuman(state.error));
    else {
        const m = state.member;
        body = h(TitledGreyBox, { title: 'Rolle von ' + m.username },
            h('div', { style: S.stack },
                h('div', { style: S.row },
                    h('select', { style: S.control, value, onChange: (e) => setValue(e.target.value) },
                        m.roles.map((r) => h('option', { key: r.slug, value: r.slug }, r.name))),
                    h(Button, { isLoading: saving, disabled: value === m.role, onClick: save }, 'Rolle speichern')),
                h('p', { style: S.muted }, 'Aktuell: ' + roleLabel(m.roles, m.role) + ' · ' + (m.role === 'owner' ? 'alle Rechte' : m.permissions.length + ' Recht(e)') + '.'),
                h('p', { style: S.muted }, 'Welche Rechte eine Rolle hat, stellst du unter "Teamverwaltung" ein. Der Admin-Haken im normalen Benutzerformular wird von der Rolle überschrieben.'),
                h(PanelLink, { destination: { extension: 'team-roles', screen: 'team' } }, 'Zur Teamverwaltung'),
                h(Footer)));
    }

    return h(PageContentBlock, { title: 'Rolle' }, body);
}

// ----------------------------------------------------------------------- Slots

function RoleBadge({ data }) {
    const r = data && data.resource;
    const id = r ? (r.id != null ? r.id : r.attributes && r.attributes.id) : null;
    const [label, setLabel] = useState(null);

    useEffect(() => {
        if (id == null) return;
        get('/members/' + id).then((m) => setLabel(roleLabel(m.roles, m.role))).catch(() => setLabel(null));
    }, [id]);

    return label ? h('span', { style: S.chip }, 'Rolle: ' + label) : null;
}

function UsersBanner() {
    return h('div', { style: { ...S.row, marginBottom: 12 } },
        h('span', { style: S.muted }, 'Rollen und Rechte der Benutzer:'),
        h(PanelLink, { destination: { extension: 'team-roles', screen: 'team' } }, 'Teamverwaltung öffnen'));
}

export default definePterodactylExtension({
    setup({ screens, slots }) {
        screens.register('team', () => Promise.resolve({ default: TeamScreen }));
        screens.register('user-role', () => Promise.resolve({ default: UserRoleScreen }));
        slots.register('panel.users.detail.actions', RoleBadge);
        slots.register('panel.users.before', UsersBanner);
    },
});
