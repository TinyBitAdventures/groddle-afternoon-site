/**
 * The administrators' console (/console/, src/AdminConsolePage.php). Every
 * figure comes from groddle/v1/admin/* (src/AdminConsole.php, manage_options
 * only) with the session's REST nonce; the page itself holds nothing.
 *
 * - Online now: polled every POLL_MS while the tab is visible (the realtime
 *   server's own list, through the site), with how long each has been on.
 * - Find a player: characters by name, online or not.
 * - Each player has a menu: click or right-click the row (it opens at the
 *   pointer), its menu button, or Enter, Shift+F10 or the Menu key on the
 *   focused row. A click on a link or control in the row, a double click, or
 *   the click that ends selecting text leaves it shut (rowClickOpens). Arrow
 *   keys move in the menu, Enter or Space picks, Escape closes and gives focus
 *   back.
 * - Bans with Unban, and the activity log of every administrator's action.
 * - Reports (the second tab; src/AdminTickets.php): players' bug reports as
 *   threads, unread first, with a status filter; a thread shows what the game
 *   knew, the messages, a reply box (the player is emailed) and Close or
 *   Reopen. Polled every TICKETS_POLL_MS while the tab is visible. #reports
 *   opens the tab, #report=<id> a report.
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
export const TICKETS_POLL_MS = 15000;
export const TICKET_MAX = 2000;

/** A ticket's status in words (src/TicketStore.php STATUSES). */
export const TICKET_STATUS = Object.freeze({ open: 'Open', answered: 'Answered', closed: 'Closed' });

/** What the game knew, in the order and words the old Bug report posts used (src/BugReports.php CONTEXT). */
export const CONTEXT_LABELS = Object.freeze([['streetName', 'Street'], ['street', 'Street id'], ['x', 'x'], ['y', 'y'], ['build', 'Build'], ['page', 'Page'], ['agent', 'Browser'], ['screen', 'Screen']]);

/** A report's context as [label, value] rows, errors last, empty ones left out. */
export function contextRows(ctx) {
  const c = ctx && typeof ctx === 'object' ? ctx : {};
  const rows = CONTEXT_LABELS.filter(([k]) => typeof c[k] === 'string' && c[k] !== '').map(([k, label]) => [label, c[k]]);
  (Array.isArray(c.errors) ? c.errors : []).forEach((e, i) => { if (typeof e === 'string' && e) rows.push([`Error ${i + 1}`, e]); });
  return rows;
}

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

/**
 * Whether a click on a player row opens its menu: the primary button, one
 * click (a double click is selecting a word), not on a link or a control in
 * the row (they do their own thing; the menu button opens it by itself), and
 * not the click that ends selecting text.
 */
export function rowClickOpens({ button = 0, detail = 1, inControl = false, selected = '' } = {}) {
  return button === 0 && detail <= 1 && !inControl && String(selected).trim() === '';
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
    case 'reply': return `replied to ${who}'s report “${d.subject || ''}”${{ mailed: ' (emailed)', unread: ' (not emailed: an earlier reply is still unread)', busy: ' (not emailed: hourly email limit)', failed: ' (the email did not send)' }[line.delivery] ?? ''}`;
    case 'close': return `closed ${who}'s report “${d.subject || ''}”`;
    case 'reopen': return `reopened ${who}'s report “${d.subject || ''}”`;
    case 'migrate': return `brought in ${d.made ?? 0} old bug ${d.made === 1 ? 'report' : 'reports'} as tickets`;
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
    // A plain click opens the same menu at the pointer (Austin 2026-10-30).
    tr.addEventListener('click', (e) => {
      const sel = globalThis.getSelection?.();
      const selected = sel && !sel.isCollapsed ? sel.toString() : '';
      const inControl = Boolean(e.target?.closest?.('a, button, input, select, textarea, label, summary, [contenteditable]'));
      if (!rowClickOpens({ button: e.button, detail: e.detail, inControl, selected })) return;
      openMenu(p, tr, { x: e.clientX, y: e.clientY });
    });
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

  // == Tabs ==
  const tablist = $('[data-console-tabs]');
  const tabs = [...tablist.querySelectorAll('[role="tab"]')];
  let tab = 'players';
  function showTab(id, focus = false) {
    tab = id;
    for (const t of tabs) {
      const on = t.dataset.consoleTab === id;
      t.setAttribute('aria-selected', String(on));
      t.tabIndex = on ? 0 : -1;
      if (on && focus) t.focus();
    }
    for (const p of root.querySelectorAll('[data-console-tabpanel]')) p.hidden = p.dataset.consoleTabpanel !== id;
    if (id === 'reports') loadTickets();
  }
  tablist.addEventListener('click', (e) => { const t = e.target.closest('[role="tab"]'); if (t) showTab(t.dataset.consoleTab); });
  tablist.addEventListener('keydown', (e) => {
    const at = tabs.indexOf(e.target.closest('[role="tab"]'));
    if (at < 0) return;
    const to = { ArrowRight: at + 1, ArrowLeft: at - 1, Home: 0, End: tabs.length - 1 }[e.key];
    if (to === undefined) return;
    e.preventDefault();
    showTab(tabs[(to + tabs.length) % tabs.length].dataset.consoleTab, true);
  });

  // == Reports ==
  const tickets = { list: [], counts: {}, status: 'all', open: 0, thread: null };
  function chip(status) {
    return el('span', { class: `console-chip console-chip-${status}`, text: TICKET_STATUS[status] || status });
  }
  function when(iso) {
    return iso ? new Date(iso).toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) : '';
  }
  function renderTickets() {
    const list = $('[data-console-tickets]');
    list.replaceChildren(...tickets.list.map((t) => {
      const btn = el('button', { type: 'button', class: 'console-ticket', 'data-ticket': t.id, 'aria-current': t.id === tickets.open ? 'true' : null, onclick: () => openTicket(t.id) },
        el('span', { class: 'console-ticket-top' },
          t.unread ? el('span', { class: 'console-dot', title: 'Unread', 'aria-label': 'Unread' }) : null,
          el('b', { text: t.subject || '(no words)' }),
          chip(t.status)),
        el('span', { class: 'console-sub', text: `${t.player.name} · ${t.lastAuthor === 'admin' ? 'you replied' : 'they wrote'} ${when(t.lastMessageAt)}` }));
      return el('li', { 'data-unread': t.unread ? '1' : null }, btn);
    }));
    $('[data-console-tickets-empty]').hidden = tickets.list.length > 0;
    const c = tickets.counts;
    $('[data-console-tickets-count]').textContent = tickets.list.length ? String(tickets.list.length) : '';
    for (const k of ['open', 'answered', 'closed']) $(`[data-console-status-count="${k}"]`).textContent = c[k] ? String(c[k]) : '';
    $('[data-console-reports-unread]').textContent = c.unread ? String(c.unread) : '';
    $('[data-console-tab="reports"]').setAttribute('aria-label', c.unread ? `Reports, ${c.unread} unread` : 'Reports');
  }
  async function loadTickets() {
    let r;
    try { r = await api.call(`admin/tickets?status=${tickets.status}`); } catch (err) { return failed(err); }
    tickets.list = r.tickets || [];
    tickets.counts = r.counts || {};
    const legacy = Number(r.legacy) || 0;
    $('[data-console-legacy]').hidden = legacy === 0;
    $('[data-console-legacy-text]').textContent = legacy === 1 ? '1 bug report from before tickets is not a ticket yet.' : `${legacy} bug reports from before tickets are not tickets yet.`;
    renderTickets();
  }
  $('[data-console-ticket-filter]').addEventListener('click', (e) => {
    const b = e.target.closest('button[data-status]');
    if (!b) return;
    tickets.status = b.dataset.status;
    for (const o of e.currentTarget.querySelectorAll('button')) o.setAttribute('aria-pressed', String(o === b));
    loadTickets();
  });
  $('[data-console-migrate]').addEventListener('click', async (e) => {
    e.currentTarget.disabled = true;
    try {
      const r = await api.call('admin/tickets/migrate', { method: 'POST' });
      say(r.text || 'Done.');
      loadTickets();
      loadLog();
    } catch (err) { failed(err); } finally { e.currentTarget.disabled = false; }
  });
  function renderThread(r, focus = false) {
    tickets.thread = r;
    tickets.open = r.ticket.id;
    const t = r.ticket;
    $('[data-console-thread-empty]').hidden = true;
    $('[data-console-thread-body]').hidden = false;
    $('[data-console-thread-subject]').textContent = t.subject || '(no words)';
    $('[data-console-thread-meta]').textContent = `#${t.id} from ${t.player.name}, ${when(t.createdAt)}`;
    const st = $('[data-console-thread-status]');
    st.className = `console-chip console-chip-${t.status}`;
    st.textContent = TICKET_STATUS[t.status] || t.status;
    $('[data-console-thread-close]').hidden = t.status === 'closed';
    $('[data-console-thread-reopen]').hidden = t.status !== 'closed';
    const rows = contextRows(r.context);
    $('[data-console-context]').hidden = rows.length === 0;
    $('[data-console-context-list]').replaceChildren(...rows.flatMap(([k, v]) => [el('dt', { text: k }), el('dd', { text: v })]));
    $('[data-console-messages]').replaceChildren(...(r.messages || []).map((m) => el('li', { class: `console-msg console-msg-${m.author}` },
      el('p', { class: 'console-msg-who' }, el('b', { text: m.author === 'admin' ? 'Administrator' : t.player.name }), el('time', { datetime: m.at, text: ` ${when(m.at)}` })),
      el('p', { class: 'console-msg-body', text: m.body }))));
    const msgs = $('[data-console-messages]');
    msgs.scrollTop = msgs.scrollHeight;
    // Read now: its row loses the dot.
    const row = tickets.list.find((x) => x.id === t.id);
    if (row && row.unread) { row.unread = false; tickets.counts.unread = Math.max(0, (tickets.counts.unread || 1) - 1); }
    if (row) Object.assign(row, { status: t.status, lastAuthor: t.lastAuthor, lastMessageAt: t.lastMessageAt });
    renderTickets();
    if (focus) $('[data-console-thread-subject]').focus();
  }
  async function openTicket(id, focus = true) {
    try { renderThread(await api.call(`admin/tickets/${id}`), focus); } catch (err) { failed(err); }
  }
  const reply = $('[data-console-reply]');
  const replyCount = $('[data-console-reply-count]');
  reply.message.addEventListener('input', () => { replyCount.textContent = `${reply.message.value.length} / ${TICKET_MAX}`; });
  reply.addEventListener('submit', async (e) => {
    e.preventDefault();
    const text = reply.message.value.trim();
    if (!text || !tickets.open) return;
    const send = reply.querySelector('button[type="submit"]');
    send.disabled = true;
    try {
      const r = await api.call(`admin/tickets/${tickets.open}/reply`, { method: 'POST', body: { message: text } });
      reply.message.value = '';
      replyCount.textContent = '';
      renderThread(r);
      say(r.text || 'Replied.', r.delivery === 'failed' ? 'bad' : 'ok');
      loadTickets();
      loadLog();
    } catch (err) { failed(err); } finally { send.disabled = false; }
  });
  reply.message.addEventListener('keydown', (e) => { if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) { e.preventDefault(); reply.requestSubmit(); } });
  for (const [sel, kind] of [['[data-console-thread-close]', 'close'], ['[data-console-thread-reopen]', 'reopen']]) {
    $(sel).addEventListener('click', async () => {
      if (!tickets.open) return;
      try {
        const r = await api.call(`admin/tickets/${tickets.open}/${kind}`, { method: 'POST' });
        renderThread(r);
        say(r.text || 'Done.');
        loadTickets();
        loadLog();
        $(kind === 'close' ? '[data-console-thread-reopen]' : '[data-console-thread-close]').focus();
      } catch (err) { failed(err); }
    });
  }
  function fromHash() {
    const m = /^#report=(\d+)$/.exec(location.hash);
    if (m) { showTab('reports'); openTicket(Number(m[1]), false); } else if (location.hash === '#reports') showTab('reports');
  }
  addEventListener('hashchange', fromHash);

  // == Start ==
  (async () => {
    try {
      const me = await api.call('me');
      state.me = Number(me?.player?.id) || 0;
    } catch { /* the lists say what went wrong */ }
    fromHash();
    await pollOnline(true);
    loadBans();
    loadLog();
    if (tab !== 'reports') loadTickets();
    let timer = setInterval(() => pollOnline(), POLL_MS);
    let logTimer = setInterval(() => loadLog(), LOG_POLL_MS);
    let ticketTimer = setInterval(() => loadTickets(), TICKETS_POLL_MS);
    document.addEventListener('visibilitychange', () => {
      clearInterval(timer);
      clearInterval(logTimer);
      clearInterval(ticketTimer);
      if (document.hidden) return;
      pollOnline();
      loadTickets();
      timer = setInterval(() => pollOnline(), POLL_MS);
      logTimer = setInterval(() => loadLog(), LOG_POLL_MS);
      ticketTimer = setInterval(() => loadTickets(), TICKETS_POLL_MS);
    });
  })();
}

if (typeof document !== 'undefined') {
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
}
