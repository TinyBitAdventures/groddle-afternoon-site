/**
 * The administrators' console (/console/, src/AdminConsolePage.php). Every
 * figure comes from groddle/v1/admin/* (src/AdminConsole.php, manage_options
 * only) with the session's REST nonce; the page itself holds nothing.
 *
 * - Online now: polled every POLL_MS while the tab is visible (the realtime
 *   server's own list, through the site), with how long each has been on.
 * - Find a player: characters by name, online or not.
 * - Each player has a menu: right-click the row, its menu button, or
 *   Shift+F10 / the Menu key on the focused row. Arrow keys move in the menu,
 *   Enter or Space picks, Escape closes and gives focus back.
 * - Bans with Unban, and the activity log of every administrator's action.
 *
 * Everything a player or an administrator wrote is set as text, never HTML.
 * Pure helpers are exported for tests (spike tests/g407-admin-console.test.js).
 */

export const POLL_MS = 5000;
export const LOG_POLL_MS = 15000;
export const TEXT_MAX = 500;
export const REASON_MAX = 200;
export const GIVE_STACKS = 10;
export const GIVE_EACH = 999;
export const GIVE_CURRANTS = 100000;

export const COPY = Object.freeze({
  serverUp: (n) => (n === 1 ? 'Realtime server up · 1 online' : `Realtime server up · ${n} online`),
  serverDown: 'Realtime server not answering',
  failed: 'That did not work. Try again in a moment.',
  signedOut: 'You are signed out. Reload to sign in again.',
  betweenStreets: 'Between streets',
  noStreet: 'Not saved yet',
  self: 'You',
});

/** "45 s", "12 min", "3 h 05 min", "2 d 4 h". */
export function onlineFor(seconds) {
  const s = Math.max(0, Math.floor(Number(seconds) || 0));
  if (s < 60) return `${s} s`;
  const m = Math.floor(s / 60);
  if (m < 60) return `${m} min`;
  const h = Math.floor(m / 60);
  if (h < 24) return `${h} h ${String(m % 60).padStart(2, '0')} min`;
  return `${Math.floor(h / 24)} d ${h % 24} h`;
}

/** What the menu offers a player: [{ id, label, enabled, reason }]. */
export function menuFor(player, { me = 0, online = false } = {}) {
  const self = player.id === me;
  const items = [
    { id: 'message', label: 'Send a message…', enabled: true },
    { id: 'teleport', label: 'Teleport…', enabled: !self, reason: self ? 'Use /tp in your own game.' : '' },
    { id: 'give', label: 'Give…', enabled: true },
    { id: 'refill', label: 'Refill mood and energy', enabled: online, reason: online ? '' : 'Only for a connected game.' },
    { id: 'boot', label: 'Sign out…', enabled: online && !self, reason: self ? 'Not yourself.' : online ? '' : 'Not online.' },
  ];
  if (player.banned) items.push({ id: 'unban', label: 'Unban', enabled: true });
  else items.push({ id: 'ban', label: 'Ban…', enabled: !self && !player.admin, reason: self ? 'Not yourself.' : player.admin ? 'Administrators cannot be banned.' : '' });
  return items.map((i) => ({ reason: '', ...i }));
}

/** Search over the street index: whole-word starts first, then anywhere; tsid exact too. */
export function findStreets(streets, query, limit = 12) {
  const q = String(query || '').toLowerCase().replace(/[’'"`]/g, '').replace(/\s+/g, ' ').trim();
  if (!q) return [];
  const norm = (s) => String(s || '').toLowerCase().replace(/[’'"`]/g, '');
  const scored = [];
  for (const s of streets) {
    const label = norm(s.label);
    let score = -1;
    if (s.tsid.toLowerCase() === q) score = 0;
    else if (label === q) score = 1;
    else if (label.startsWith(q)) score = 2;
    else if (` ${label}`.includes(` ${q}`)) score = 3;
    else if (label.includes(q) || norm(s.hub).startsWith(q)) score = 4;
    if (score >= 0) scored.push([score, s]);
  }
  scored.sort((a, b) => a[0] - b[0] || a[1].label.localeCompare(b[1].label) || a[1].tsid.localeCompare(b[1].tsid));
  return scored.slice(0, limit).map(([, s]) => s);
}

/** Search over the give catalog by name or class. */
export function findItems(items, query, limit = 12) {
  const q = String(query || '').toLowerCase().trim();
  if (!q) return [];
  const out = [];
  for (const it of items) {
    const n = it.name.toLowerCase();
    const score = it.c === q ? 0 : n === q ? 1 : n.startsWith(q) ? 2 : ` ${n}`.includes(` ${q}`) ? 3 : n.includes(q) || it.c.includes(q.replace(/\s+/g, '_')) ? 4 : -1;
    if (score >= 0) out.push([score, it]);
  }
  out.sort((a, b) => a[0] - b[0] || a[1].name.localeCompare(b[1].name));
  return out.slice(0, limit).map(([, it]) => it);
}

/** One activity line in words. */
export function logWords(line) {
  const who = line.player?.name || `#${line.player?.id}`;
  const d = line.detail || {};
  const how = { live: '', queued: ' (offline: waiting for their next connect)', offline: ' (they were not online)', saved: ' (offline: their next load)', stored: '', down: ' (realtime server down)', self: '' }[line.delivery] ?? ` (${line.delivery})`;
  switch (line.kind) {
    case 'message': return `messaged ${who}: “${d.text || ''}”${how}`;
    case 'teleport': return `moved ${who} to ${d.to?.label || d.to?.tsid || 'a street'}${how}`;
    case 'boot': return `signed ${who} out${d.reason ? `: ${d.reason}` : ''}${how}`;
    case 'ban': return `banned ${who}${d.reason ? `: ${d.reason}` : ''}${how}`;
    case 'unban': return `unbanned ${who}`;
    case 'give': return `gave ${who} ${d.text || 'something'}${how}`;
    case 'refill': return `refilled ${who}'s mood and energy${how}`;
    default: return `${line.kind} ${who}${how}`;
  }
}

// == The page ==

function el(tag, attrs = {}, ...kids) {
  const n = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) {
    if (v === false || v == null) continue;
    if (k === 'text') n.textContent = String(v);
    else if (k.startsWith('on')) n.addEventListener(k.slice(2), v);
    else n.setAttribute(k, v === true ? '' : String(v));
  }
  for (const k of kids) if (k != null) n.append(k);
  return n;
}

function createApi() {
  let nonce = '';
  async function fresh() {
    const r = await fetch('/wp-admin/admin-ajax.php?action=rest-nonce', { credentials: 'same-origin', cache: 'no-store' });
    const t = (await r.text()).trim();
    nonce = r.ok && /^[0-9a-f]{10}$/.test(t) ? t : '';
    return nonce;
  }
  async function call(path, { method = 'GET', body } = {}, retried = false) {
    if (!nonce) await fresh();
    const r = await fetch(`/wp-json/groddle/v1/${path}`, {
      method,
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { 'X-WP-Nonce': nonce, ...(body ? { 'Content-Type': 'application/json' } : {}) },
      body: body ? JSON.stringify(body) : undefined,
    });
    const data = await r.json().catch(() => null);
    if (r.status === 403 && data?.code === 'rest_cookie_invalid_nonce' && !retried) {
      await fresh();
      return call(path, { method, body }, true);
    }
    if (!r.ok) throw Object.assign(new Error(data?.message || COPY.failed), { status: r.status, code: data?.code || '' });
    return data;
  }
  return { call };
}

function boot() {
  const root = document.querySelector('[data-console]');
  if (!root) return;
  const api = createApi();
  const state = { online: [], onlineIds: new Set(), found: [], bans: [], streets: null, items: null, me: 0, logBefore: 0 };
  const $ = (sel) => root.querySelector(sel);
  const note = $('[data-console-note]');
  const server = $('[data-console-server]');
  let noteTimer = 0;

  function say(text, tone = 'ok') {
    note.hidden = false;
    note.dataset.tone = tone;
    note.textContent = text;
    clearTimeout(noteTimer);
    noteTimer = setTimeout(() => { note.hidden = true; }, 9000);
  }
  function failed(err) {
    say(err?.status === 401 ? COPY.signedOut : err?.message || COPY.failed, 'bad');
  }

  // == Players ==
  function playerCell(p) {
    const tags = [];
    if (p.id === state.me) tags.push(el('span', { class: 'console-tag', text: COPY.self }));
    if (p.admin) tags.push(el('span', { class: 'console-tag', text: 'Admin' }));
    if (p.banned) tags.push(el('span', { class: 'console-tag console-tag-bad', text: 'Banned' }));
    return el('td', { class: 'console-who' }, el('b', { text: p.name }), el('span', { class: 'console-sub', text: ` Level ${p.level}` }), ...tags);
  }
  function menuButton(p) {
    return el('button', { type: 'button', class: 'console-menu-btn', 'aria-haspopup': 'menu', 'aria-label': `Actions for ${p.name}`, onclick: (e) => openMenu(p, e.currentTarget, null) }, '⋯');
  }
  function row(p, cells) {
    const tr = el('tr', { tabindex: '0', 'data-player': p.id, 'aria-label': p.name }, playerCell(p), ...cells, el('td', { class: 'console-act' }, menuButton(p)));
    tr.addEventListener('contextmenu', (e) => { e.preventDefault(); openMenu(p, tr, { x: e.clientX, y: e.clientY }); });
    tr.addEventListener('keydown', (e) => {
      if (e.target !== tr) return;
      if ((e.shiftKey && e.key === 'F10') || e.key === 'ContextMenu' || e.key === 'Enter') { e.preventDefault(); openMenu(p, tr, null); }
    });
    return tr;
  }
  function renderOnline() {
    const body = $('[data-console-online] tbody');
    const focused = document.activeElement?.closest?.('[data-console-online] tr')?.dataset.player;
    body.replaceChildren(...state.online.map((p) => row(p, [
      el('td', { text: p.street?.label || COPY.betweenStreets, title: p.street?.tsid || '' }),
      el('td', { class: 'console-num', text: onlineFor(Date.now() / 1000 - p.since) }),
    ])));
    if (focused) body.querySelector(`tr[data-player="${focused}"]`)?.focus();
    $('[data-console-online-empty]').hidden = state.online.length > 0;
    $('[data-console-count]').textContent = state.online.length ? String(state.online.length) : '';
  }
  function renderFound() {
    const table = $('[data-console-found]');
    table.hidden = state.found.length === 0;
    table.querySelector('tbody').replaceChildren(...state.found.map((p) => row(p, [
      el('td', { text: state.onlineIds.has(p.id) ? 'Online now' : p.street?.label || COPY.noStreet }),
      el('td', { class: 'console-num', text: p.savedAt ? new Date(p.savedAt).toLocaleString() : '' }),
    ])));
  }
  async function pollOnline(sync = false) {
    try {
      const r = await api.call(`admin/online${sync ? '?sync=1' : ''}`);
      state.online = r.players || [];
      state.onlineIds = new Set(state.online.map((p) => p.id));
      server.textContent = r.server === 'up' ? COPY.serverUp(state.online.length) : COPY.serverDown;
      server.dataset.state = r.server;
      renderOnline();
      renderFound();
    } catch (err) {
      server.textContent = err?.status === 401 ? COPY.signedOut : COPY.serverDown;
      server.dataset.state = 'down';
    }
  }
  $('[data-console-search]').addEventListener('submit', async (e) => {
    e.preventDefault();
    const q = e.currentTarget.q.value.trim();
    if (!q) return;
    try {
      state.found = (await api.call(`admin/players?q=${encodeURIComponent(q)}`)).players || [];
      $('[data-console-found-empty]').hidden = state.found.length > 0;
      renderFound();
    } catch (err) { failed(err); }
  });

  // == Bans and the log ==
  async function loadBans() {
    try {
      state.bans = (await api.call('admin/bans')).bans || [];
    } catch (err) { return failed(err); }
    $('[data-console-bans]').replaceChildren(...state.bans.map((b) => el('li', { class: 'console-ban' },
      el('div', {}, el('b', { text: b.player.name }), el('span', { class: 'console-sub', text: ` by ${b.by.name}, ${new Date(b.at).toLocaleString()}` }),
        b.reason ? el('p', { class: 'console-reason', text: b.reason }) : null),
      el('button', { type: 'button', class: 'console-small', text: 'Unban', 'aria-label': `Unban ${b.player.name}`, onclick: () => act('unban', { id: b.player.id, name: b.player.name }, {}) }))));
    $('[data-console-bans-empty]').hidden = state.bans.length > 0;
    $('[data-console-bans-count]').textContent = state.bans.length ? String(state.bans.length) : '';
  }
  async function loadLog(older = false) {
    let r;
    try { r = await api.call(`admin/log${older && state.logBefore ? `?before=${state.logBefore}` : ''}`); } catch (err) { return failed(err); }
    const list = $('[data-console-log]');
    const items = (r.lines || []).map((l) => el('li', { 'data-kind': l.kind },
      el('time', { datetime: l.at, text: new Date(l.at).toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) }),
      el('span', { class: `console-kind console-kind-${l.kind}`, text: l.kind }),
      el('span', {}, el('b', { text: l.by.name }), ` ${logWords(l)}`)));
    if (older) list.append(...items);
    else list.replaceChildren(...items);
    if (r.lines?.length) state.logBefore = r.lines[r.lines.length - 1].id;
    $('[data-console-log-empty]').hidden = list.children.length > 0;
    $('[data-console-log-more]').hidden = !r.more;
  }
  $('[data-console-log-more]').addEventListener('click', () => loadLog(true));

  // == The menu ==
  let menu = null;
  function closeMenu(focusBack = true) {
    if (!menu) return;
    const { node, opener } = menu;
    menu = null;
    node.remove();
    if (focusBack) opener?.focus?.();
  }
  function openMenu(p, opener, at) {
    closeMenu(false);
    const online = state.onlineIds.has(p.id);
    const fresh = state.online.find((o) => o.id === p.id) || state.found.find((o) => o.id === p.id) || p;
    const entries = menuFor({ ...p, ...fresh }, { me: state.me, online });
    const node = el('div', { class: 'console-menu', role: 'menu', 'aria-label': `Actions for ${p.name}` },
      el('p', { class: 'console-menu-head', text: p.name, 'aria-hidden': 'true' }));
    const buttons = entries.map((m) => el('button', { type: 'button', role: 'menuitem', 'aria-disabled': m.enabled ? null : 'true', title: m.reason || null, 'data-menu-item': m.id,
      onclick: () => { if (!m.enabled) return; closeMenu(false); pick(m.id, { ...p, ...fresh }, opener); } },
    el('span', { text: m.label }), m.enabled ? null : el('span', { class: 'console-sub', text: m.reason })));
    node.append(...buttons);
    document.body.append(node);
    const r = opener.getBoundingClientRect();
    const x = at ? at.x : r.right - 8;
    const y = at ? at.y : r.bottom + 4;
    const w = node.offsetWidth;
    const h = node.offsetHeight;
    node.style.left = `${Math.max(8, Math.min(x, innerWidth - w - 8)) + scrollX}px`;
    node.style.top = `${Math.max(8, Math.min(y, innerHeight - h - 8)) + scrollY}px`;
    menu = { node, opener };
    let i = Math.max(0, entries.findIndex((m) => m.enabled));
    buttons[i].focus();
    node.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' || (e.shiftKey && e.key === 'F10') || e.key === 'Tab') { e.preventDefault(); closeMenu(); return; }
      const step = { ArrowDown: 1, ArrowUp: -1 }[e.key];
      if (step) { e.preventDefault(); i = (i + step + buttons.length) % buttons.length; buttons[i].focus(); }
      if (e.key === 'Home') { e.preventDefault(); i = 0; buttons[0].focus(); }
      if (e.key === 'End') { e.preventDefault(); i = buttons.length - 1; buttons[i].focus(); }
    });
  }
  document.addEventListener('pointerdown', (e) => { if (menu && !menu.node.contains(e.target)) closeMenu(false); });
  addEventListener('scroll', () => closeMenu(false), { passive: true });

  // == Dialogs ==
  function dialog(title, body, submitLabel, onSubmit, { danger = false } = {}) {
    const form = el('form', { method: 'dialog', class: 'console-form' });
    const status = el('p', { class: 'console-form-status', role: 'alert' });
    const submit = el('button', { type: 'submit', class: danger ? 'console-primary console-danger' : 'console-primary', text: submitLabel });
    const cancel = el('button', { type: 'button', text: 'Cancel' });
    form.append(el('h2', { text: title }), ...body, status, el('div', { class: 'console-form-buttons' }, cancel, submit));
    const dlg = el('dialog', { class: 'console-dialog', 'aria-label': title }, form);
    document.body.append(dlg);
    const opener = document.activeElement;
    const done = () => { dlg.close(); dlg.remove(); if (opener?.isConnected) opener.focus(); };
    cancel.addEventListener('click', done);
    dlg.addEventListener('cancel', (e) => { e.preventDefault(); done(); });
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      submit.disabled = true;
      status.textContent = '';
      try {
        if ((await onSubmit()) !== false) done();
      } catch (err) {
        status.textContent = err?.message || COPY.failed;
      } finally {
        submit.disabled = false;
      }
    });
    dlg.showModal();
    form.querySelector('textarea, input, select')?.focus();
    return dlg;
  }

  async function act(kind, p, body) {
    const r = await api.call(`admin/${kind}`, { method: 'POST', body: { player: p.id, ...body } });
    say(r.text || 'Done.', r.delivery === 'down' ? 'bad' : 'ok');
    pollOnline();
    loadLog();
    if (kind === 'ban' || kind === 'unban') {
      loadBans();
      for (const list of [state.online, state.found]) for (const o of list) if (o.id === p.id) o.banned = kind === 'ban';
      renderFound();
    }
    return r;
  }

  function counter(input, max) {
    const out = el('span', { class: 'console-sub console-counter', 'aria-live': 'polite' });
    const upd = () => { out.textContent = `${input.value.length} / ${max}`; };
    input.addEventListener('input', upd);
    upd();
    return out;
  }

  async function streets() {
    state.streets ??= (await api.call('admin/streets')).streets || [];
    return state.streets;
  }
  async function items() {
    state.items ??= (await api.call('admin/catalog')).items || [];
    return state.items;
  }

  /** A search box with a listbox of results (arrow keys, Enter picks). */
  function picker({ label, placeholder, search, describe, onPick }) {
    const id = `pick-${Math.random().toString(36).slice(2, 8)}`;
    const input = el('input', { id, type: 'search', autocomplete: 'off', spellcheck: 'false', placeholder, role: 'combobox', 'aria-expanded': 'false', 'aria-controls': `${id}-list`, 'aria-autocomplete': 'list' });
    const list = el('ul', { id: `${id}-list`, role: 'listbox', class: 'console-picks', hidden: true });
    let found = [];
    let at = -1;
    const mark = () => {
      [...list.children].forEach((li, i) => li.setAttribute('aria-selected', String(i === at)));
      input.setAttribute('aria-activedescendant', at >= 0 ? `${id}-${at}` : '');
      list.children[at]?.scrollIntoView({ block: 'nearest' });
    };
    const choose = (i) => { if (found[i]) { onPick(found[i]); list.hidden = true; input.setAttribute('aria-expanded', 'false'); } };
    input.addEventListener('input', async () => {
      found = await search(input.value);
      at = found.length ? 0 : -1;
      list.replaceChildren(...found.map((f, i) => el('li', { id: `${id}-${i}`, role: 'option', onpointerdown: (e) => { e.preventDefault(); choose(i); } }, ...describe(f))));
      list.hidden = found.length === 0;
      input.setAttribute('aria-expanded', String(!list.hidden));
      mark();
    });
    input.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); if (found.length) { at = (at + (e.key === 'ArrowDown' ? 1 : -1) + found.length) % found.length; mark(); } }
      if (e.key === 'Enter' && !list.hidden && at >= 0) { e.preventDefault(); choose(at); }
    });
    return { input, node: el('div', { class: 'console-picker' }, el('label', { for: id, text: label }), input, list) };
  }

  function pick(kind, p, opener) {
    if (kind === 'message') {
      const text = el('textarea', { name: 'text', rows: '5', maxlength: String(TEXT_MAX), required: true, placeholder: 'Plain text. It shows in their game as "Message from an administrator".' });
      dialog(`Message ${p.name}`, [el('label', { text: 'Message' }, text), counter(text, TEXT_MAX)], 'Send', async () => { await act('message', p, { text: text.value }); });
    } else if (kind === 'teleport') {
      let chosen = null;
      const shown = el('p', { class: 'console-chosen', text: 'No street chosen yet.' });
      const pk = picker({ label: 'Street', placeholder: 'Street name or tsid', search: async (q) => findStreets(await streets(), q), describe: (s) => [el('b', { text: s.label }), el('span', { class: 'console-sub', text: ` ${s.hub ? `${s.hub} · ` : ''}${s.tsid}` })], onPick: (s) => { chosen = s; shown.textContent = `${s.label} (${s.tsid})`; pk.input.value = s.label; } });
      dialog(`Teleport ${p.name}`, [pk.node, shown], 'Teleport', async () => {
        if (!chosen) throw new Error('Choose a street first.');
        const r = await api.call('admin/teleport', { method: 'POST', body: { player: p.name, tsid: chosen.tsid } });
        say(r.text || 'Done.');
        pollOnline();
        loadLog();
      });
    } else if (kind === 'give') {
      const stacks = new Map();
      const list = el('ul', { class: 'console-stacks' });
      const draw = () => list.replaceChildren(...[...stacks.values()].map((s) => {
        const n = el('input', { type: 'number', min: '1', max: String(GIVE_EACH), step: '1', value: String(s.n), 'aria-label': `How many ${s.item.plural}`, class: 'console-qty' });
        n.addEventListener('input', () => { s.n = Math.max(1, Math.min(GIVE_EACH, Math.floor(Number(n.value) || 1))); });
        return el('li', {}, n, el('span', { text: ` ${s.item.name}` }), el('button', { type: 'button', class: 'console-small', text: 'Remove', 'aria-label': `Remove ${s.item.name}`, onclick: () => { stacks.delete(s.item.c); draw(); } }));
      }));
      const pk = picker({ label: 'Add an item', placeholder: 'Item name', search: async (q) => findItems(await items(), q), describe: (it) => [el('b', { text: it.name }), el('span', { class: 'console-sub', text: ` ${it.c}` })], onPick: (it) => { if (!stacks.has(it.c) && stacks.size < GIVE_STACKS) stacks.set(it.c, { item: it, n: 1 }); pk.input.value = ''; draw(); } });
      const cur = el('input', { type: 'number', min: '0', max: String(GIVE_CURRANTS), step: '1', value: '0', name: 'currants' });
      dialog(`Give ${p.name}`, [pk.node, list, el('label', { text: 'Currants' }, cur), el('p', { class: 'console-sub', text: `Up to ${GIVE_STACKS} kinds, ${GIVE_EACH} of each. Only things the ledger carries. It goes through the ledger, so their save keeps it.` })], 'Give', async () => {
        const currants = Math.max(0, Math.floor(Number(cur.value) || 0));
        await act('give', p, { items: [...stacks.values()].map((s) => ({ c: s.item.c, n: s.n })), currants });
      });
    } else if (kind === 'refill') {
      act('refill', p, {}).catch(failed);
    } else if (kind === 'boot') {
      const reason = el('input', { type: 'text', name: 'reason', maxlength: String(REASON_MAX), placeholder: 'They see: An administrator signed you out: <reason>' });
      dialog(`Sign ${p.name} out`, [el('label', { text: 'Reason' }, reason), el('p', { class: 'console-sub', text: 'Their game stops reconnecting until they reload.' })], 'Sign out', async () => { await act('boot', p, { reason: reason.value }); }, { danger: true });
    } else if (kind === 'ban') {
      const reason = el('input', { type: 'text', name: 'reason', maxlength: String(REASON_MAX), required: true });
      dialog(`Ban ${p.name}`, [el('label', { text: 'Reason' }, reason), el('p', { class: 'console-sub', text: 'They are signed out now and cannot play multiplayer until unbanned. Their game and /play/online/ say the account is suspended.' })], 'Ban', async () => { await act('ban', p, { reason: reason.value }); }, { danger: true });
    } else if (kind === 'unban') {
      act('unban', p, {}).catch(failed);
    }
    void opener;
  }

  // == Start ==
  (async () => {
    try {
      const me = await api.call('me');
      state.me = Number(me?.player?.id) || 0;
    } catch { /* the lists say what went wrong */ }
    await pollOnline(true);
    loadBans();
    loadLog();
    let timer = setInterval(() => pollOnline(), POLL_MS);
    let logTimer = setInterval(() => loadLog(), LOG_POLL_MS);
    document.addEventListener('visibilitychange', () => {
      clearInterval(timer);
      clearInterval(logTimer);
      if (document.hidden) return;
      pollOnline();
      timer = setInterval(() => pollOnline(), POLL_MS);
      logTimer = setInterval(() => loadLog(), LOG_POLL_MS);
    });
  })();
}

if (typeof document !== 'undefined') {
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
}
