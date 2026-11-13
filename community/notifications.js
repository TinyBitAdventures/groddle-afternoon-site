/**
 * The bell in the chat app's header (src/SiteNav.php bell, src/Notifications.php): a panel of what
 * happened while you weren't looking, newest first, each with its icon, a short line, the time and
 * an unread dot; a click opens the topic, the conversation or the line's thread (in place when the
 * chat app is open on /chat/), Mark all read reads them all. The same count is on the Community link.
 *
 * Live: the chat app asks again (soon()) when its socket says something that may be one (an IM, a
 * thread reply, a new board post, a buddy add); otherwise every POLL_MS while the tab is visible.
 * The thing you are looking at (the open IM, the open thread) is read at once.
 *
 * Local preview (Austin 2026-11-04): /chat/?notifdemo=1 on a .localhost site (the page draws
 * body[data-notif-demo] only there, CommunityPages::notifDemo) fills the panel with samples
 * (chat-pure.js demoNotifications), marked as samples; nothing is written anywhere.
 *
 * QA: window.groddleNotif (items, unread, open, refresh), the bell's [data-notif-n].
 */
const { agoLabel, countLabel, demoAllowed, demoNotifications, markRead, sortNotifications, unreadCount } = await import(`./chat-pure.js${new URL(import.meta.url).search}`);

const POLL_MS = 60 * 1000;
/** The realtime server hands its lines to the site every 3 s (chatstore.go): ask after one batch, and again after the next. */
const SOON_MS = 3500;
/** How long a socket's item waits for the site before the site's answer stands alone. */
const HINT_MS = 20 * 1000;
const WORDS = {
  title: 'Notifications',
  all: 'Mark all read',
  empty: 'Nothing yet. Replies, quotes, IMs, buddy adds, the team\'s news and answers show here.',
  samples: 'Samples',
  sampleOnly: 'A sample: nothing to open.',
  failed: 'That didn\'t work. Try again in a moment.',
  ticketOpen: 'Answer in the game: Menu, Reports',
  loading: 'Loading…',
};
const svg = (d) => `<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">${d}</svg>`;
const ICONS = {
  reply: svg('<path d="M8 5 3.5 9.5 8 14"/><path d="M4 9.5h7.5a5 5 0 0 1 5 5v1"/>'),
  quote: svg('<path d="M4 12.5c0-3 1.5-5 4-6M11 12.5c0-3 1.5-5 4-6"/><circle cx="5.5" cy="13" r="1.8"/><circle cx="12.5" cy="13" r="1.8"/>'),
  thread: svg('<path d="M3 4.5h10v6.5H8l-3 2.5V11H3z"/><path d="M15 8h2v6.5h-1.5V17l-3-2.5H10"/>'),
  im: svg('<rect x="2.5" y="4.5" width="15" height="11" rx="2"/><path d="m3.5 6 6.5 5 6.5-5"/>'),
  buddy: svg('<circle cx="8" cy="7" r="3"/><path d="M2.5 16.5c.6-3 2.8-4.5 5.5-4.5s4.9 1.5 5.5 4.5M15.5 6.5v5M13 9h5"/>'),
  announce: svg('<path d="M3 8v4h3l6 4V4L6 8z"/><path d="M15 7.5a3.5 3.5 0 0 1 0 5"/>'),
  ticket: svg('<circle cx="10" cy="10" r="7"/><circle cx="10" cy="10" r="3"/><path d="m5 5 2.9 2.9M15 5l-2.9 2.9M5 15l2.9-2.9M15 15l-2.9-2.9"/>'),
};

function el(tag, attrs = {}, text = '') {
  const n = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) if (v !== null && v !== undefined && v !== false) n.setAttribute(k, v === true ? '' : String(v));
  if (text) n.textContent = text;
  return n;
}

/**
 * Starts the bell. call: community.js's groddle/v1 call; nav: the moves inside /chat/ (chat-nav.js),
 * so a row's topic or conversation opens in place, without a reload. Returns the panel's API, or
 * null without a bell on the page.
 */
export function startNotifications({ call, nav = null }) {
  const box = document.querySelector('[data-notif]');
  const bell = box?.querySelector('[data-notif-open]');
  if (!box || !bell) return null;
  const demo = demoAllowed(location.hostname, document.body.hasAttribute('data-notif-demo'));
  let items = demo ? demoNotifications(Date.now()) : [];
  let loaded = demo;
  let busy = false;
  let timer = null;
  let later = null;
  /** key -> { item, at, when }: items the socket brought before the site kept them. */
  const hinted = new Map();
  /** Rows opened in place (a report's answer): key -> the box under the row, kept across redraws. */
  const expanded = new Map();
  /** The chat app's: the keys being looked at now (an open IM, an open thread), and opening one in place. */
  const app = { looking: () => [], open: null };

  const panel = el('section', { class: 'notif-panel', id: 'site-nav-notif', role: 'dialog', 'aria-labelledby': 'notif-title', hidden: true });
  const head = el('header', { class: 'notif-head' });
  head.append(el('h2', { class: 'notif-title', id: 'notif-title' }, WORDS.title));
  if (demo) head.append(el('span', { class: 'notif-sample', title: 'Made-up notifications for a local preview' }, WORDS.samples));
  const all = el('button', { type: 'button', class: 'notif-all', 'data-notif-all': '' }, WORDS.all);
  head.append(all);
  const list = el('ol', { class: 'notif-list', 'data-notif-list': '' });
  const empty = el('p', { class: 'notif-empty' }, WORDS.empty);
  const said = el('p', { class: 'notif-said', role: 'status', 'aria-live': 'polite' });
  panel.append(head, list, empty, said);
  box.append(panel);

  function setCount() {
    const n = unreadCount(items);
    const badge = box.querySelector('[data-notif-n]');
    const count = box.querySelector('[data-notif-count]');
    if (badge && count) {
      badge.hidden = n === 0;
      count.textContent = countLabel(n);
    }
    bell.setAttribute('aria-label', n > 0 ? `Notifications, ${n} new` : 'Notifications');
    all.disabled = n === 0;
    // The Community link in the menu carries the same count (SiteNav::badge).
    for (const a of document.querySelectorAll('.site-nav-links a[href="/chat/"]')) {
      let b = a.querySelector('.site-nav-badge');
      if (n === 0) { b?.remove(); continue; }
      if (!b) {
        b = el('span', { class: 'site-nav-badge' });
        a.append(b);
      }
      b.innerHTML = `<span class="visually-hidden">, </span>${countLabel(n)}<span class="visually-hidden"> new</span>`;
    }
    document.documentElement.dataset.notifUnread = String(n);
  }

  function draw() {
    const now = Date.now();
    const focused = document.activeElement?.closest?.('[data-notif-key]')?.dataset.notifKey || '';
    const rows = [];
    for (const item of items) {
      const li = el('li', { class: 'notif-item', 'data-notif-key': item.key, 'data-kind': item.kind, ...(item.unread ? { 'data-unread': '' } : {}), ...(item.sample ? { 'data-sample': '' } : {}) });
      // A row is a button: a click reads it, and opens a conversation, thread or topic in place; it never leaves for a profile.
      const a = el('button', { type: 'button', class: 'notif-row' });
      const icon = el('span', { class: 'notif-icon', 'data-kind': item.kind, 'aria-hidden': 'true' });
      icon.innerHTML = ICONS[item.kind] || ICONS.reply;
      const txt = el('span', { class: 'notif-txt' });
      const line = el('span', { class: 'notif-line' });
      const name = item.from?.name || '';
      if (name && item.text.startsWith(name)) {
        line.append(el('b', {}, name), document.createTextNode(item.text.slice(name.length)));
      } else line.textContent = item.text;
      txt.append(line);
      if (item.snippet) txt.append(el('span', { class: 'notif-snip' }, item.snippet));
      txt.append(el('time', { datetime: new Date(item.at).toISOString(), title: new Date(item.at).toLocaleString() }, agoLabel(item.at, now)));
      a.append(icon, txt, el('span', { class: 'notif-dot', 'aria-label': item.unread ? 'New' : null, role: item.unread ? 'img' : null }));
      li.append(a);
      // A buddy add: small, quiet actions beside the row (the row itself only reads it).
      if (item.kind === 'buddy' && item.from?.id) {
        const acts = el('span', { class: 'notif-acts' });
        if (typeof item.url === 'string' && item.url.startsWith('/players/')) acts.append(el('a', { class: 'notif-act', href: item.url }, 'View profile'));
        acts.append(el('button', { type: 'button', class: 'notif-act', 'data-notif-im': String(item.from.id) }, 'Send IM'));
        li.append(acts);
      }
      if (expanded.has(item.key)) li.append(expanded.get(item.key));
      rows.push(li);
    }
    list.replaceChildren(...rows);
    if (focused) list.querySelector(`[data-notif-key="${CSS.escape(focused)}"] .notif-row`)?.focus({ preventScroll: true });
    empty.hidden = items.length > 0 || !loaded;
    setCount();
  }

  async function refresh() {
    if (demo || busy) return;
    busy = true;
    const r = await call('notifications');
    busy = false;
    if (!r.ok || !Array.isArray(r.data?.items)) return;
    // What the socket said and the site has not kept yet stays as the socket said it.
    const now0 = Date.now();
    const fresh = r.data.items.slice();
    for (const [key, h] of hinted) {
      const got = fresh.find((i) => i.key === key);
      if (got && Number(got.at) >= Number(h.at)) hinted.delete(key);
      else if (now0 - h.when > HINT_MS) hinted.delete(key);
      else if (got) fresh.splice(fresh.indexOf(got), 1, { ...got, ...h.item });
      else fresh.push(h.item);
    }
    items = sortNotifications(fresh);
    loaded = true;
    // What you are looking at now is read.
    const looking = new Set(document.hidden ? [] : app.looking());
    const now = items.filter((i) => i.unread && looking.has(i.key));
    draw();
    for (const i of now) read(i.key);
  }

  function soon() {
    clearTimeout(timer);
    clearTimeout(later);
    timer = setTimeout(refresh, SOON_MS);
    later = setTimeout(refresh, SOON_MS * 2.5);
  }

  /**
   * What the socket just said (an IM, a reply under your line), shown at once: the site keeps it a few
   * seconds later, and the next refresh() draws the same item from there.
   */
  function hint(next) {
    if (demo || !next?.key) return;
    const was = items.find((i) => i.key === next.key);
    const count = (was?.unread ? was.count : 0) + 1;
    const merged = { ...(was || {}), ...next, count, unread: true, text: next.textFor ? next.textFor(count) : next.text };
    delete merged.textFor;
    hinted.set(next.key, { item: merged, at: merged.at, when: Date.now() });
    items = sortNotifications([merged, ...items.filter((i) => i.key !== next.key)]);
    draw();
    soon();
  }

  async function read(key = null) {
    items = markRead(items, key);
    for (const [k, h] of hinted) if (key === null || k === key) h.item = { ...h.item, unread: false };
    draw();
    if (demo) return true;
    const r = await call('notifications/read', { method: 'POST', body: key === null ? {} : { key } });
    if (!r.ok) {
      said.textContent = r.data?.message || WORDS.failed;
      return false;
    }
    return true;
  }

  function isOpen() { return !panel.hidden; }
  function openPanel() {
    panel.hidden = false;
    bell.setAttribute('aria-expanded', 'true');
    if (!loaded) list.replaceChildren(el('li', { class: 'notif-empty' }, WORDS.loading));
    (list.querySelector('.notif-row') || all).focus({ preventScroll: true });
    refresh();
  }
  function closePanel(back = false) {
    if (panel.hidden) return;
    panel.hidden = true;
    bell.setAttribute('aria-expanded', 'false');
    said.textContent = '';
    if (back) bell.focus({ preventScroll: true });
  }

  bell.addEventListener('click', () => (isOpen() ? closePanel() : openPanel()));
  all.addEventListener('click', () => read(null));
  panel.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closePanel(true); return; }
    if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
    const rows = [...list.querySelectorAll('.notif-row')];
    const at = rows.indexOf(document.activeElement);
    if (!rows.length) return;
    e.preventDefault();
    rows[(at + (e.key === 'ArrowDown' ? 1 : -1) + rows.length) % rows.length].focus();
  });
  document.addEventListener('pointerdown', (e) => {
    if (isOpen() && !box.contains(e.target)) closePanel();
  });
  box.addEventListener('focusout', (e) => {
    if (isOpen() && e.relatedTarget && !box.contains(e.relatedTarget)) closePanel();
  });

  list.addEventListener('click', async (e) => {
    const row = e.target.closest('.notif-row');
    if (!row || e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
    const key = row.closest('[data-notif-key]').dataset.notifKey;
    const item = items.find((i) => i.key === key);
    if (!item) return;
    e.preventDefault();
    if (item.sample) {
      read(key);
      said.textContent = WORDS.sampleOnly;
      return;
    }
    if (item.open?.ticket) {
      ticket(key, item.open.ticket);
      read(key);
      return;
    }
    const wait = read(key);
    if (item.open?.conv && app.open?.(item.open)) {
      closePanel();
      return;
    }
    // Only a board topic or a conversation on /chat/ is opened from the row; anything else (a buddy add's
    // profile) is just read, and its own small action buttons go further.
    if (typeof item.url !== 'string' || !item.url.startsWith('/chat/')) return;
    if (nav) {
      closePanel();
      nav.go(item.url);
      return;
    }
    await Promise.race([wait, new Promise((r) => setTimeout(r, 600))]);
    location.href = item.url;
  });

  // A buddy add's Send IM: the IM in place when the chat app is open, else /chat/?im=<id>.
  list.addEventListener('click', (e) => {
    const act = e.target.closest('.notif-act');
    if (!act) return;
    const key = act.closest('[data-notif-key]')?.dataset.notifKey;
    // The local preview's samples are nobody: their actions only say so.
    if (items.find((i) => i.key === key)?.sample) {
      e.preventDefault();
      said.textContent = WORDS.sampleOnly;
      return;
    }
    const b = act.matches('[data-notif-im]') ? act : null;
    if (!b) { if (key) read(key); return; }
    const id = b.dataset.notifIm;
    if (key) read(key);
    if (app.open?.({ conv: `im:${id}` })) { closePanel(); return; }
    if (nav) { closePanel(); nav.go(`/chat/?im=${encodeURIComponent(id)}`); return; }
    location.href = `/chat/?im=${encodeURIComponent(id)}`;
  });

  /** The team's answer, under its row (opening it reads it in the game too, as the Reports sheet does). */
  async function ticket(key, id) {
    if (expanded.has(key)) {
      expanded.get(key).remove();
      expanded.delete(key);
      return;
    }
    const more = el('div', { class: 'notif-more' }, WORDS.loading);
    expanded.set(key, more);
    list.querySelector(`[data-notif-key="${CSS.escape(key)}"]`)?.append(more);
    const r = await call(`tickets/${id}`);
    if (!r.ok) { more.textContent = r.data?.message || WORDS.failed; return; }
    const answer = [...(r.data.messages || [])].reverse().find((m) => m.author === 'admin');
    more.replaceChildren(el('p', { class: 'notif-answer' }, answer?.body || ''), el('a', { class: 'notif-go', href: '/play/online/' }, WORDS.ticketOpen));
  }

  draw();
  if (!demo) {
    refresh();
    setInterval(() => { if (!document.hidden) refresh(); }, POLL_MS);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) soon(); });
  }
  const api = {
    refresh,
    soon,
    hint,
    read,
    app,
    items: () => items.map((i) => ({ ...i })),
    unread: () => unreadCount(items),
    get open() { return isOpen(); },
    show: openPanel,
    hide: closePanel,
    demo,
  };
  globalThis.groddleNotif = api;
  return api;
}
