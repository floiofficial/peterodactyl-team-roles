// Minimal React-like runtime for tests: function components, hooks (state/effect/callback/syncExternalStore), full re-render on change.
const S = { instances: new Map(), cur: null, root: null, scheduled: false, effects: [], visited: null };
export const createElement = (type, props, ...children) => ({ type, props: props || {}, children: children.flat(Infinity).filter(c => c !== null && c !== undefined && c !== false && c !== true), key: props && props.key });
const same = (a, b) => a && b && a.length === b.length && a.every((v, i) => Object.is(v, b[i]));
function schedule() { if (S.scheduled) return; S.scheduled = true; queueMicrotask(() => { S.scheduled = false; commit(); }); }
function inst(path) { let i = S.instances.get(path); if (!i) { i = { hooks: [], idx: 0 }; S.instances.set(path, i); } return i; }
export const useState = (init) => { const i = S.cur, k = i.idx++; if (!(k in i.hooks)) i.hooks[k] = { v: typeof init === 'function' ? init() : init }; const hk = i.hooks[k]; return [hk.v, (n) => { const nv = typeof n === 'function' ? n(hk.v) : n; if (!Object.is(nv, hk.v)) { hk.v = nv; schedule(); } }]; };
export const useEffect = (fn, deps) => { const i = S.cur, k = i.idx++; const hk = i.hooks[k]; if (!hk || !deps || !same(hk.deps, deps)) { const rec = hk || (i.hooks[k] = {}); rec.deps = deps; S.effects.push(() => { if (rec.cleanup) rec.cleanup(); const c = fn(); rec.cleanup = typeof c === 'function' ? c : null; }); i.hooks[k] = rec; } };
export const useCallback = (f, deps) => { const i = S.cur, k = i.idx++; const hk = i.hooks[k]; if (hk && same(hk.deps, deps)) return hk.f; i.hooks[k] = { f, deps }; return f; };
export const useSyncExternalStore = (sub, get) => { const i = S.cur, k = i.idx++; if (!(k in i.hooks)) { const rec = { unsub: sub(() => schedule()) }; i.hooks[k] = rec; } return get(); };
function build(el, path) {
    if (el === null || el === undefined) return null;
    if (typeof el !== 'object') return String(el);
    S.visited.add(path);
    if (typeof el.type === 'function') {
        let i = inst(path); if (i.type && i.type !== el.type) { i.hooks.forEach(h => { if (h && h.cleanup) h.cleanup(); if (h && h.unsub) h.unsub(); }); S.instances.delete(path); i = inst(path); } i.idx = 0; i.type = el.type; const prev = S.cur; S.cur = i;
        const out = el.type({ ...el.props, children: el.children.length <= 1 ? el.children[0] : el.children });
        S.cur = prev;
        return build(out, path + '/0');
    }
    return { tag: el.type, props: el.props, kids: el.children.map((c, n) => build(c, path + '/' + (c && c.key != null ? 'k' + c.key : n))).filter(c => c !== null) };
}
function commit() {
    S.visited = new Set(); const root = S.root; S.tree = build(root.el, 'r');
    for (const [p, i] of [...S.instances]) if (!S.visited.has(p)) { i.hooks.forEach(h => { if (h && h.cleanup) h.cleanup(); if (h && h.unsub) h.unsub(); }); S.instances.delete(p); }
    const fx = S.effects; S.effects = []; fx.forEach(f => f());
}
export function mount(el) { S.instances.clear(); S.root = { el }; commit(); }
export const tree = () => S.tree;
export const unmount = () => { for (const i of S.instances.values()) i.hooks.forEach(h => { if (h && h.cleanup) h.cleanup(); if (h && h.unsub) h.unsub(); }); S.instances.clear(); S.tree = null; };
export const settle = async () => { for (let n = 0; n < 12; n++) { await new Promise(r => setTimeout(r, 0)); } };
