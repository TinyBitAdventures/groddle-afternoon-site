/**
 * The /chat/ app for signed-in players (Austin 2026-11-04: the game's Social drawer as a full-screen chat
 * app). src/CommunityPages.php draws the frame (the rail, the conversation, the thread panel and Who's
 * around) and the first channel's lines; this keeps it live:
 *
 * - The rail: Channels (Global Chat, Live Help, the Trade Channel as the game allows them, with unread
 *   marks), Chats (your IMs, newest first: groddle/v1/chat/ims), Buddies (groddle/v1/buddies, online and
 *   where from the realtime server), Boards (links; the topics are server-drawn pages in the same frame).
 * - A conversation: a channel's or an IM's lines as the drawer draws them (time lines after a gap, "name
 *   words", links that ask before another site, reactions as chips, the add-reaction button), Earlier
 *   messages at the top (and when you scroll up to it), the rounded input at the bottom.
 * - Reply in thread on any line: the thread slides in on the right with its own box; a line with replies
 *   says "N replies · <last>". IM threads are the two players'.
 * - Who's around (presence.go): everyone online you may see, in the game, on the web or idle, buddies with
 *   their street; Appear offline (groddle/v1/account/presence).
 * - Live: a realtime socket with the site's web ticket (groddle/v1/realtime {"via":"web"}, the server's
 *   web.go) brings channel lines, reactions, thread replies, IMs, presence and new board posts as they
 *   happen. When it can't connect, the open conversation is polled every POLL_MS (Who's around waits for
 *   the socket) and it tries again with a growing wait.
 *
 * - Links: /chat/?c=global#l<id> opens the channel at a line (earlier messages come in until it shows),
 *   #t<id> opens a line's thread (Copy link to this line, the bell's thread replies).
 * - The bell (notifications.js): told when the socket brings something that may be a notification,
 *   asked what is being looked at (read at once), and opens a conversation or thread in place.
 * - Right-click menus (chat-menus.js) through menuApi(); Mute keeps a channel's or chat's count quiet
 *   on this browser; the players you block are listed last under Buddies, so Unblock stays in reach.
 *
 * - Moving around (chat-nav.js): every view is a real address. A conversation, a thread opened or
 *   closed, and the boards' pages come in place with pushState, the socket staying connected; Back
 *   and Forward bring each back (nav.app.show), and a conversation you come back to keeps its place
 *   (c.scroll) while a new one starts at its newest line.
 *
 * Phones: the rail is one screen and a conversation another (main[data-cx-view]); "‹ Chats" goes back.
 * QA: main[data-cx-live] ("on" | "off"), [data-cx-conv] on the open conversation, window.groddleChat.
 */
import { MUTED_KEY, convPath, lineHash, readMuted } from './chat-pure.js';

// community.js hands over its helpers (start()): one copy of them and of its state.
let main; let call; let say; let SAID; let openPicker; let live; let lineReact; let topicNow; let notif; let nav;

const POLL_MS = 3000;
const WATCH_MS = 30 * 60 * 1000;
const ACTIVE_MS = 30 * 1000;
const TIME_GAP_MS = 5 * 60 * 1000;
const EARLIER_MAX = 400;
let ME = 0;
const CHANNELS = { global: 'Global Chat', help: 'Live Help', trade: 'Trade Channel' };
const ICON = {
  react: '<svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M9 3.2a6 6 0 1 0 5.8 7.4M6.6 11.4c.6 1 1.6 1.6 2.8 1.6s2.2-.6 2.8-1.6M7 8h.01M11.6 8h.01M15.5 2.5v5M13 5h5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>',
  thread: '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 4.5h10v6.5H8l-3 2.5V11H3z"/><path d="M15 8h2v6.5h-1.5V17l-3-2.5H10"/></svg>',
  send: '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3.5 10 16.5 4l-4 12-2.5-5z"/></svg>',
  back: '<svg viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="m7.5 3-3 3 3 3"/></svg>',
};
const WORDS = {
  offline: 'Offline',
  game: 'In the game',
  web: 'On the web',
  idle: 'Idle',
  you: 'You',
  hidden: 'You appear offline',
  aroundDown: 'Who\'s around shows once the live connection is back. Trying again…',
  aroundNone: 'Nobody else is around right now.',
  noBuddies: 'No buddies yet. Add players from their page or in the game.',
  noIms: 'Your IMs show here.',
  blocked: 'Blocked. Right-click to unblock.',
  reactLive: 'Reactions need the live connection. Try again in a moment.',
  threadOld: 'That thread is not open to you.',
  replies: (n) => (n === 1 ? '1 reply' : `${n} replies`),
  earlier: 'Earlier messages',
  noEarlier: 'No earlier messages',
};

const $ = (sel, root = document) => root.querySelector(sel);
function el(tag, attrs = {}, text = '') {
  const n = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) if (v !== null && v !== undefined && v !== false) n.setAttribute(k, v === true ? '' : String(v));
  if (text) n.textContent = text;
  return n;
}

/** The drawer's colour slot (js/ui/social-drawer.js avatarOf). */
function hueOf(name) {
  const s = String(name || '?').trim() || '?';
  let h = 0;
  for (const ch of s) h = (h * 31 + ch.codePointAt(0)) >>> 0;
  return h % 8;
}
function avatar(name, cls = 'cx-av-lg', { tile = null, status = '' } = {}) {
  const s = String(name || '?').trim() || '?';
  const a = el('span', { class: `cx-av ${cls}`, 'data-hue': hueOf(s), 'aria-hidden': 'true' });
  if (tile) a.append(el('img', { src: tile, alt: '', loading: 'lazy', decoding: 'async' }));
  else a.textContent = [...s][0].toUpperCase();
  if (status) a.append(el('span', { class: 'cx-dot', 'data-status': status }));
  return a;
}
function clockLabel(ms) {
  return new Date(ms).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
}

// Links in what was said (the game's js/chat-links.js: http and https only; another site asks first).
let links = null;
import('/js/chat-links.js').then((m) => { links = m; }).catch(() => { links = null; });
function words(node, text) {
  if (links?.appendLinkedText) links.appendLinkedText(node, String(text || ''), { doc: document });
  else node.append(document.createTextNode(String(text || '')));
}

// —— State ——

/** key ('chan:global', 'im:9') -> { key, kind, id, label, lines: Map lineId -> line, more, oldest, unread, loaded } */
const convs = new Map();
/** key -> its rail row. */
const rows = new Map();
/** player id -> { id, name, profile, tile, buddy } (chat/people). */
const people = new Map();
/** street id -> its name (chat/people streets). */
const streets = new Map();
/** Who's around: id -> the server's entry. */
const around = new Map();
let buddies = { list: [], loaded: false };
/** The players on your own buddy list (buddies.list also holds who added you). */
const held = new Set();
let ims = [];
let open = '';
let thread = null; // { key, parent, lines: [] }
let off = false;
let pane;
let aroundBox;
let threadBox;

function convOf(key) {
  let c = convs.get(key);
  if (!c) {
    const [kind, rawId] = key.split(':');
    const id = kind === 'im' ? Number(rawId) : rawId;
    c = { key, kind, id, label: kind === 'chan' ? CHANNELS[id] || id : nameOf(id), lines: new Map(), more: false, oldest: 0, newest: 0, since: 0, unread: 0, loaded: false, scroll: null };
    convs.set(key, c);
  }
  return c;
}
function nameOf(id) {
  return people.get(id)?.name || ims.find((x) => x.with.id === id)?.with.name || buddies.list.find((b) => b.id === id)?.name || around.get(id)?.name || 'A player';
}
const lineId = (c, l) => (c.kind === 'chan' ? Number(l.lid) : Number(l.id));
/** A line's reaction key as the game's drawer has it: l:<lid> for a channel line, im:<with>:<id> for an IM line (l.id is either). */
const reactKey = (c, l) => (c.kind === 'chan' ? `l:${l.id}` : `im:${c.id}:${l.id}`);

/** A line as the conversation keeps it: { id, from, name, text, at, web, r, th, parent }. */
function normal(c, raw) {
  if (c.kind === 'chan') return { id: Number(raw.lid), from: Number(raw.id), name: String(raw.name || ''), text: String(raw.text || ''), at: Number(raw.at) || 0, web: raw.web === true, r: raw.r || [], th: raw.th || null };
  const from = Number(raw.from);
  return { id: Number(raw.id), from, name: from === ME ? (main.dataset.meName || 'You') : nameOf(c.id), text: String(raw.text || ''), at: Number(raw.at) || 0, web: false, r: raw.r || [], th: raw.th || null, parent: Number(raw.parent) || 0 };
}

// —— Drawing a line ——

function chipsNode(c, l) {
  const box = el('span', { class: 'cchan-chips', 'data-reactions-line': '' });
  for (const r of l.r || []) {
    const def = REACT_DEFS.get(r.e);
    if (!def) continue;
    const mine = (r.by || []).some((b) => Number(b.id) === ME);
    const names = (r.by || []).map((b) => (Number(b.id) === ME ? 'You' : b.name));
    const label = `${def.label}: ${names.join(', ')}`;
    const b = el('button', { type: 'button', class: 'cchip cchip-small', 'data-react': r.e, 'aria-pressed': String(mine), title: label, 'aria-label': `${def.glyph || def.label} ${names.length}, ${names.join(', ')}` });
    if (def.art) b.append(el('span', { class: `cchip-art cchip-${r.e}`, 'aria-hidden': 'true' }));
    else b.append(el('span', { class: 'cchip-glyph', 'aria-hidden': 'true' }, def.glyph));
    b.append(el('span', { 'aria-hidden': 'true' }, String(names.length)));
    box.append(b);
  }
  return box;
}
const REACT_DEFS = new Map();

function repliesNode(c, l) {
  if (!l.th || !(l.th.n > 0)) return null;
  const a = el('button', { type: 'button', class: 'cx-replies', 'data-cx-replies': String(l.id) });
  a.innerHTML = ICON.thread;
  a.append(el('span', {}, `${WORDS.replies(l.th.n)} · ${l.th.last?.id === ME ? 'You' : l.th.last?.name || ''}`));
  return a;
}

function lineNode(c, l, { inThread = false } = {}) {
  const li = el('li', { class: `cchan-line${l.from === ME ? ' is-mine' : ''}`, 'data-line': String(l.id), 'data-rk': reactKey(c, l), 'data-from': String(l.from), 'data-name': l.name });
  li.append(el('time', { datetime: new Date(l.at).toISOString(), title: new Date(l.at).toLocaleString() }, clockLabel(l.at)));
  const who = el('b', { class: 'cchan-name' });
  const p = people.get(l.from);
  // Another player's name opens their menu (chat-menus.js): a link to their page when it is public, else a button.
  if (l.from === ME) who.textContent = 'You';
  else if (p?.profile) who.append(el('a', { href: p.profile }, l.name));
  else who.append(el('button', { type: 'button', class: 'cchan-who', 'aria-haspopup': 'menu' }, l.name));
  li.append(who);
  if (l.web) li.append(el('span', { class: 'cchan-web', title: 'Said on the website' }, '(web)'));
  li.append(document.createTextNode(' '));
  const t = el('span', { class: 'cchan-text' });
  words(t, l.text);
  li.append(t);
  const acts = el('span', { class: 'cx-acts' });
  const add = el('button', { type: 'button', class: 'cx-act', 'data-react-open': '', 'aria-haspopup': 'true', 'aria-expanded': 'false', 'aria-label': `React to ${l.from === ME ? 'your' : `${l.name}'s`} message`, title: 'React' });
  add.innerHTML = ICON.react;
  acts.append(add);
  if (!inThread && !l.parent) {
    const th = el('button', { type: 'button', class: 'cx-act', 'data-cx-thread-open': String(l.id), 'aria-label': 'Reply in thread', title: 'Reply in thread' });
    th.innerHTML = ICON.thread;
    acts.append(th);
  }
  li.append(acts);
  li.append(chipsNode(c, l));
  const rep = inThread ? null : repliesNode(c, l);
  if (rep) li.append(rep);
  return li;
}

/**
 * Draws a list of lines with the drawer's time lines (a gap of TIME_GAP_MS or more). A line drawn before
 * and not changed keeps its node (a hover, a focus and a selection in it stay).
 */
function drawLines(list, c, lines, opts = {}) {
  const had = new Map();
  for (const n of list.children) if (n.dataset.sig) had.set(n.dataset.sig, n);
  const out = [];
  let last = 0;
  for (const l of lines) {
    if (!last || l.at - last >= TIME_GAP_MS) {
      const sig = `t:${l.id}:${clockLabel(l.at)}`;
      out.push(had.get(sig) || Object.assign(el('li', { class: 'cx-time', 'aria-hidden': 'true', 'data-sig': sig }, clockLabel(l.at))));
    }
    last = l.at;
    const sig = JSON.stringify([l.id, l.from, l.name, l.text, l.web, l.r, l.th, Boolean(opts.inThread), people.get(l.from)?.profile || '']);
    let node = had.get(sig);
    if (!node) {
      node = lineNode(c, l, opts);
      node.dataset.sig = sig;
    }
    out.push(node);
  }
  // Only what moved is touched.
  let at = 0;
  for (const n of out) {
    if (list.children[at] !== n) list.insertBefore(n, list.children[at] || null);
    at += 1;
  }
  while (list.children.length > out.length) list.lastElementChild.remove();
}

// —— The open conversation ——

function shownLines(c) {
  return [...c.lines.values()].filter((l) => !l.parent && !(l.from !== ME && blocked(l.from))).sort((a, b) => a.at - b.at || a.id - b.id);
}
const blocks = new Set();
/** The players you block, with names, for the Buddies list's Blocked rows (right-click: Unblock). */
let blockedList = [];
const blocked = (id) => blocks.has(Number(id));

function convSection(c) {
  const sec = el('section', { class: 'cx-conv', 'aria-labelledby': 'cx-conv-title', 'data-cx-conv': c.key });
  const head = el('header', { class: 'cx-head' });
  const back = el('a', { class: 'cx-back', href: '/chat/', 'data-cx-back': '' });
  back.innerHTML = ICON.back;
  back.append(el('span', {}, 'Chats'));
  const txt = el('div', { class: 'cx-head-txt' });
  txt.append(el('h2', { class: 'cx-head-name', id: 'cx-conv-title' }, c.label));
  const isBuddy = c.kind === 'im' && buddies.list.some((b) => b.id === c.id);
  txt.append(el('small', { class: 'cx-note' }, c.kind === 'chan' ? 'Everyone' : isBuddy ? 'Buddy' : 'Player'));
  const acts = el('div', { class: 'cx-head-acts' });
  if (aroundBox) acts.append(el('button', { type: 'button', class: 'cx-chip', 'data-cx-around-open': '', 'aria-controls': 'cx-around', 'aria-expanded': String(main.dataset.cxAround !== 'off') }, 'Who\'s around'));
  head.append(back, avatar(c.label, 'cx-av-md'), txt, acts);
  if (c.kind === 'chan') $('.cx-av', head).textContent = c.id === 'help' ? '?' : '#';
  const log = el('div', { class: 'cx-log', 'data-cx-log': '' });
  const more = el('button', { type: 'button', class: 'cx-chip cchan-more', 'data-chan-more': '', hidden: !c.more }, WORDS.earlier);
  const list = el('ol', { class: 'cchan-lines', role: 'log', 'aria-live': 'polite', 'data-chan-lines': '' });
  const empty = el('p', { class: 'cchan-empty cmuted', 'data-chan-empty': '' }, c.kind === 'chan' ? 'Nobody has said anything here yet.' : `Say hi to ${c.label}.`);
  log.append(more, list, empty);
  const form = el('form', { class: 'cx-compose cchan-say', 'data-chan-say': '' });
  const row = el('div', { class: 'cx-say' });
  row.append(el('label', { class: 'visually-hidden', for: 'cchan-text' }, c.kind === 'chan' ? `Chat in ${c.label}` : `Message ${c.label}`));
  row.append(el('input', { class: 'cinput cx-field', id: 'cchan-text', name: 'text', type: 'text', maxlength: '1000', autocomplete: 'off', placeholder: c.kind === 'chan' ? `Chat in ${c.label}` : `Message ${c.label}` }));
  const send = el('button', { class: 'cx-send', type: 'submit', 'aria-label': 'Send', title: 'Send' });
  send.innerHTML = ICON.send;
  row.append(send);
  form.append(row);
  if (c.kind === 'chan') form.append(el('p', { class: 'chint' }, 'Lines you send here go to everyone in the channel in the game, marked web.'));
  form.append(el('p', { class: 'csaid', role: 'status', 'aria-live': 'polite', 'data-chan-said': '' }));
  sec.append(head, log, form);
  return sec;
}

function redraw(c, { toEnd = false } = {}) {
  const sec = pane.querySelector(`[data-cx-conv="${CSS.escape(c.key)}"]`);
  if (!sec) return;
  const log = $('[data-cx-log]', sec);
  const atEnd = log.scrollHeight - log.scrollTop - log.clientHeight < 40;
  const lines = shownLines(c);
  drawLines($('[data-chan-lines]', sec), c, lines);
  $('[data-chan-empty]', sec).hidden = lines.length > 0;
  $('[data-chan-more]', sec).hidden = !c.more;
  if (toEnd || atEnd) log.scrollTop = log.scrollHeight;
}

function keep(c, raws, { older = false } = {}) {
  for (const raw of raws || []) {
    const l = normal(c, raw);
    if (!(l.id > 0)) continue;
    if (l.parent) continue; // a thread reply is the thread's, never a line of the conversation
    c.lines.set(l.id, l);
    if (c.kind === 'chan') {
      c.newest = Math.max(c.newest, l.id);
      c.oldest = c.oldest ? Math.min(c.oldest, l.id) : l.id;
    } else {
      c.newest = Math.max(c.newest, l.at);
      c.oldest = c.oldest ? Math.min(c.oldest, l.at) : l.at;
    }
  }
  if (!older && c.lines.size > EARLIER_MAX) {
    for (const l of shownLines(c).slice(0, c.lines.size - EARLIER_MAX)) c.lines.delete(l.id);
  }
}

async function load(c) {
  const path = c.kind === 'chan' ? `chat/channels/${c.id}?limit=50` : `chat/ims/${c.id}?limit=50`;
  const r = await call(path);
  if (!r.ok) {
    say($('[data-chan-said]', pane), r.data?.message || SAID.failed, true);
    return;
  }
  if (r.data?.blocked) {
    say($('[data-chan-said]', pane), 'You can\'t IM this player.', true);
    return;
  }
  // Again (back to it, the socket back): what came is added, so its place and its earlier lines stay,
  // unless more came than one answer holds (a gap), when it starts again from the newest.
  const was = c.loaded;
  const ids = (r.data.lines || []).map((raw) => lineId(c, raw)).filter((n) => n > 0);
  const gap = was && Boolean(r.data.more) && ids.length > 0 && Math.min(...ids) > Math.max(0, ...c.lines.keys());
  if (!was || gap) {
    c.lines.clear();
    c.oldest = 0;
    c.newest = 0;
    c.more = Boolean(r.data.more);
  }
  keep(c, r.data.lines);
  c.since = Number(r.data.now) || Date.now();
  c.loaded = true;
  if (open === c.key) redraw(c, { toEnd: !was || gap });
  if (wanted && wanted.key === c.key) {
    const want = wanted;
    wanted = null;
    seek(c, want);
  }
}

/** What a link asked for when the page opened ({ key, line? , thread? }), until its conversation loads. */
let wanted = null;

/** Goes to a line (earlier messages are brought in until it shows, or there are none) or opens its thread. */
async function seek(c, want) {
  if (want.thread) {
    openThread(c.key, want.thread, { note: false });
    return;
  }
  if (!want.line) return;
  for (let i = 0; i < 12; i += 1) {
    const li = pane.querySelector(`[data-cx-conv="${CSS.escape(c.key)}"] [data-line="${want.line}"]`);
    if (li) {
      li.scrollIntoView({ block: 'center' });
      li.dataset.cxHit = '';
      setTimeout(() => { delete li.dataset.cxHit; }, 4000);
      return;
    }
    if (!c.more) return;
    await earlier(c);
  }
}

/** Reads the bell's notification for something now open. */
function readNotif(key) {
  if (notif?.items().some((i) => i.key === key && i.unread)) notif.read(key);
}

/** What the menus need from the app (community/chat-menus.js). */
function menuApi() {
  return {
    main,
    call,
    me: ME,
    canIm: main.dataset.canPost === '1',
    notif,
    flash,
    person: (id) => people.get(Number(id)),
    lookUp: async (id) => {
      if (people.get(id)?.checked) return;
      const r = await call(`chat/people?ids=${Number(id)}&streets=`);
      if (!r.ok) return;
      for (const p of r.data.people || []) people.set(p.id, { ...p, checked: true });
      if (!people.has(id)) people.set(id, { id, checked: true });
    },
    isBuddy: (id) => held.has(Number(id)),
    isBlocked: (id) => blocked(id),
    openIm: (id) => openConv(`im:${Number(id)}`, { focus: true }),
    lineOf: (li) => {
      const sec = li.closest('[data-cx-conv]');
      if (sec) return { conv: sec.dataset.cxConv, label: convOf(sec.dataset.cxConv).label, inThread: false, parent: 0 };
      const key = thread?.key || open;
      const isParent = Boolean(li.closest('.cx-thread-parent'));
      return { conv: key, label: convOf(key).label, inThread: true, parent: isParent ? 0 : thread?.parent || 0 };
    },
    openThread: (key, id) => openThread(key, Number(id)),
    react: (li) => openPicker(li, li.querySelector('[data-react-open]') || li),
    reloadBuddies: async () => {
      await loadBuddies();
      if (open) redraw(convOf(open));
      drawIms();
      drawAround();
      if (thread) loadThread(convOf(thread.key), thread.parent);
    },
    railUnread: (key) => convs.get(key)?.unread || 0,
    markRailRead: (key) => {
      const c = convOf(key);
      c.unread = 0;
      markRail(c);
      drawIms();
      if (c.kind === 'im') {
        send('im_read', { with: c.id });
        readNotif(key);
      }
    },
    isMuted: (key) => muted.has(key),
    setMuted,
  };
}

async function earlier(c) {
  const sec = pane.querySelector(`[data-cx-conv="${CSS.escape(c.key)}"]`);
  const btn = sec && $('[data-chan-more]', sec);
  if (!btn || btn.disabled || !c.more) return;
  btn.disabled = true;
  btn.textContent = 'Loading…';
  const path = c.kind === 'chan' ? `chat/channels/${c.id}?before=${c.oldest}&limit=50` : `chat/ims/${c.id}?before_at=${c.oldest}&limit=50`;
  const r = await call(path);
  btn.disabled = false;
  btn.textContent = WORDS.earlier;
  if (!r.ok) return;
  const log = $('[data-cx-log]', sec);
  const height = log.scrollHeight;
  keep(c, r.data.lines, { older: true });
  c.more = Boolean(r.data.more) && c.lines.size < EARLIER_MAX;
  redraw(c);
  if (!c.more) btn.textContent = WORDS.noEarlier;
  log.scrollTop += log.scrollHeight - height;
}

/** Opens a conversation: its address is pushed (chat-nav.js), and it is drawn in place. focus: its box. */
function openConv(key, { focus = false } = {}) {
  if (nav) nav.go(convPath(key), { focus: focus ? 'input' : 'heading' });
  else showConv(key, { focus });
}

/** Where a conversation's lines were, kept for when you come back to it. */
function keepPlace(key) {
  const log = pane.querySelector(`[data-cx-conv="${CSS.escape(key)}"] [data-cx-log]`);
  if (log) convOf(key).scroll = { top: log.scrollTop, atEnd: log.scrollHeight - log.scrollTop - log.clientHeight < 40 };
}

/** Draws a conversation in the pane (the address is chat-nav.js's). */
function showConv(key, { focus = false } = {}) {
  const c = convOf(key);
  if (c.kind === 'im') c.label = nameOf(c.id);
  if (open) keepPlace(open);
  open = key;
  c.unread = 0;
  pane.replaceChildren(convSection(c));
  main.dataset.cxView = 'main';
  for (const [k, row] of rows) {
    if (k === key) row.setAttribute('aria-current', 'true');
    else row.removeAttribute('aria-current');
  }
  document.title = `${c.label} – Community – Groddle Afternoon`;
  markRail(c);
  wireConv(c);
  if (c.loaded) {
    // Back to it: where it was, or its newest line when that is where you were.
    const place = c.scroll;
    redraw(c, { toEnd: !place || place.atEnd });
    if (place && !place.atEnd) $('[data-cx-log]', pane).scrollTop = place.top;
  }
  load(c);
  if (c.kind === 'im') {
    send('im_read', { with: c.id });
    readNotif(key);
  }
  if (focus) $('#cchan-text', pane)?.focus();
  closeThread();
}

/**
 * chat-nav.js: shows a conversation's address (/chat/, ?c=, ?im=, #t<line>, #l<line>), or says it
 * can't (no channels to show) so the page is drawn instead. The same conversation again only opens
 * or closes the thread, or goes to the line.
 */
function showRoute(to, { focus = false } = {}) {
  const first = main.querySelector('[data-cx-open^="chan:"]')?.dataset.cxOpen || '';
  let key = to.view === 'home' ? first : to.key;
  if (to.view === 'chan' && !rows.has(key)) key = first;
  if (!key) return false;
  const want = lineHash(to.hash);
  const view = to.view === 'home' ? 'list' : 'main';
  if (open === key && pane.querySelector(`[data-cx-conv="${CSS.escape(key)}"]`)) {
    if (want.thread) {
      if (!thread || thread.key !== key || thread.parent !== want.thread) openThread(key, want.thread, { note: false });
    } else {
      closeThread();
      if (want.line) seek(convOf(key), want);
    }
    main.dataset.cxView = view;
    return true;
  }
  wanted = want.line || want.thread ? { key, ...want } : null;
  showConv(key, { focus: focus === 'input' });
  // A phone shows the list for /chat/ (the server's 'list'), a conversation for its own address.
  main.dataset.cxView = view;
  if (to.view === 'home') document.title = 'Community – Groddle Afternoon';
  return true;
}

/** chat-nav.js: a board's page takes the pane; the open conversation keeps its place for later. */
function leaveConv() {
  if (!open) return;
  keepPlace(open);
  open = '';
  closeThread();
  for (const row of rows.values()) row.removeAttribute('aria-current');
}

function wireConv(c) {
  const sec = pane.querySelector(`[data-cx-conv="${CSS.escape(c.key)}"]`);
  if (!sec) return;
  const form = $('[data-chan-say]', sec);
  const field = $('input', form);
  const note = $('[data-chan-said]', sec);
  $('[data-chan-more]', sec).addEventListener('click', () => earlier(c));
  const log = $('[data-cx-log]', sec);
  log.addEventListener('scroll', () => { if (log.scrollTop < 40 && c.more) earlier(c); }, { passive: true });
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const text = field.value.trim();
    if (!text) return;
    const button = $('button[type="submit"]', form);
    button.disabled = true;
    const r = c.kind === 'chan'
      ? await call(`chat/channels/${c.id}`, { method: 'POST', body: { text } })
      : await call(`chat/ims/${c.id}`, { method: 'POST', body: { text } });
    button.disabled = false;
    if (!r.ok) {
      say(note, r.data?.message || SAID.failed, true);
      return;
    }
    field.value = '';
    say(note, '');
    if (c.kind === 'chan' && r.data?.line) {
      keep(c, [r.data.line]);
      redraw(c, { toEnd: true });
    } else if (!live.on) load(c);
    field.focus();
  });
}

// —— Reactions on lines (over the socket), the picker is community.js's ——

function reactLine(li, key, on) {
  const rk = li.dataset.rk || '';
  let m = /^l:(\d+)$/.exec(rk);
  if (m && send('react', { line: Number(m[1]), e: key, on })) return;
  m = /^im:(\d+):(\d+)$/.exec(rk);
  if (m && send('im_react', { with: Number(m[1]), line: Number(m[2]), e: key, on })) return;
  flash(WORDS.reactLive);
}

/** A line's reactions now (rk as the drawer keys it): kept, and its chips drawn again where it shows. */
function setReactions(rk, r) {
  const shown = (c, l) => reactKey(c, { lid: l.id, id: l.id }) === rk;
  for (const c of convs.values()) for (const l of c.lines.values()) if (shown(c, l)) l.r = r;
  if (thread) for (const l of thread.lines) if (shown(convOf(thread.key), l)) l.r = r;
  for (const li of main.querySelectorAll(`[data-rk="${CSS.escape(rk)}"]`)) {
    li.querySelector('[data-reactions-line]')?.replaceWith(chipsNode(null, { r }));
    delete li.dataset.sig; // drawn again at the next redraw
  }
}

function flash(text, bad = true) {
  const note = pane.querySelector('[data-chan-said]') || threadBox?.querySelector('.csaid') || toast();
  if (note) {
    say(note, text, bad);
    clearTimeout(note.flashTimer);
    note.flashTimer = setTimeout(() => say(note, ''), 4000);
  }
}

/** A line for what an action did where the page has no box of its own (a board's page). */
function toast() {
  let t = main.querySelector('.cx-toast');
  if (!t) {
    t = el('p', { class: 'csaid cx-toast', role: 'status', 'aria-live': 'polite' });
    main.append(t);
  }
  return t;
}

// —— Threads ——

/** Closes the thread panel; note: the address goes back to the conversation's (you closed it). */
function closeThread({ note = false } = {}) {
  if (!threadBox) return;
  if (note && thread && nav) nav.note(convPath(thread.key));
  thread = null;
  threadBox.hidden = true;
  threadBox.replaceChildren();
  delete main.dataset.cxThreaded;
}

/** Opens a line's thread beside the conversation; note: the address says so (#t<line>), for a link and Back. */
async function openThread(key, parentId, { note: noteIt = true } = {}) {
  if (!threadBox) return;
  if (noteIt && nav && open === key) nav.note(`${convPath(key)}#t${parentId}`);
  const c = convOf(key);
  const parent = c.lines.get(parentId);
  thread = { key, parent: parentId, lines: [] };
  main.dataset.cxThreaded = '';
  if (c.kind === 'chan') readNotif(`th:${c.id}:${parentId}`);
  threadBox.hidden = false;
  const head = el('header', { class: 'cx-side-head' });
  head.append(el('h2', { class: 'cx-side-title', id: 'cx-thread-title' }, 'Thread'), el('small', { class: 'cx-note' }, c.label));
  const x = el('button', { type: 'button', class: 'cx-x', 'aria-label': 'Close the thread', title: 'Close (Esc)' });
  x.innerHTML = ICON.back;
  x.addEventListener('click', () => closeThread({ note: true }));
  head.append(x);
  const log = el('div', { class: 'cx-log', 'data-cx-thread-log': '' });
  const top = el('ol', { class: 'cchan-lines cx-thread-parent' });
  if (parent) top.append(lineNode(c, parent, { inThread: true }));
  const count = el('p', { class: 'cx-thread-count', 'data-cx-thread-count': '' });
  const list = el('ol', { class: 'cchan-lines', role: 'log', 'aria-live': 'polite', 'data-cx-thread-lines': '' });
  log.append(top, count, list);
  const form = el('form', { class: 'cx-compose' });
  const row = el('div', { class: 'cx-say' });
  row.append(el('label', { class: 'visually-hidden', for: 'cx-thread-text' }, 'Reply in thread'));
  const field = el('input', { class: 'cinput cx-field', id: 'cx-thread-text', type: 'text', maxlength: '1000', autocomplete: 'off', placeholder: 'Reply in thread' });
  const sendBtn = el('button', { class: 'cx-send', type: 'submit', 'aria-label': 'Send reply', title: 'Send reply' });
  sendBtn.innerHTML = ICON.send;
  row.append(field, sendBtn);
  const note = el('p', { class: 'csaid', role: 'status', 'aria-live': 'polite' });
  form.append(row, note);
  threadBox.replaceChildren(head, log, form);
  threadBox.dataset.cxThreadFor = `${key}#${parentId}`;
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const text = field.value.trim();
    if (!text) return;
    sendBtn.disabled = true;
    const r = c.kind === 'chan'
      ? await call(`chat/channels/${c.id}/threads/${parentId}`, { method: 'POST', body: { text } })
      : await call(`chat/ims/${c.id}`, { method: 'POST', body: { text, parent: parentId } });
    sendBtn.disabled = false;
    if (!r.ok) {
      say(note, r.data?.message || SAID.failed, true);
      return;
    }
    field.value = '';
    say(note, '');
    if (c.kind === 'chan' && r.data?.line) addReply(c, parentId, r.data.line);
    else if (!live.on) loadThread(c, parentId);
  });
  field.focus();
  await loadThread(c, parentId);
}

async function loadThread(c, parentId) {
  const r = c.kind === 'chan' ? await call(`chat/channels/${c.id}/threads/${parentId}`) : await call(`chat/ims/${c.id}/threads/${parentId}`);
  if (!thread || thread.key !== c.key || thread.parent !== parentId) return;
  if (!r.ok) {
    say($('.csaid', threadBox), r.data?.message || WORDS.threadOld, true);
    return;
  }
  if (c.kind === 'chan' && r.data.parent && !c.lines.has(parentId)) {
    keep(c, [r.data.parent]);
    $('.cx-thread-parent', threadBox).replaceChildren(lineNode(c, c.lines.get(parentId), { inThread: true }));
  }
  thread.lines = (r.data.lines || []).map((raw) => ({ ...normal(c, raw), parent: parentId })).filter((l) => l.from === ME || !blocked(l.from));
  drawThread(c);
}

function drawThread(c) {
  if (!thread) return;
  const log = $('[data-cx-thread-log]', threadBox);
  const atEnd = log.scrollHeight - log.scrollTop - log.clientHeight < 40;
  drawLines($('[data-cx-thread-lines]', threadBox), c, thread.lines, { inThread: true });
  $('[data-cx-thread-count]', threadBox).textContent = thread.lines.length ? WORDS.replies(thread.lines.length) : 'No replies yet.';
  if (atEnd) log.scrollTop = log.scrollHeight;
}

/** A reply arrived (or was sent): the open thread takes it; the parent's count moves. */
function addReply(c, parentId, raw, sum = null) {
  const l = { ...normal(c, raw), parent: parentId };
  if (l.from !== ME && blocked(l.from)) return;
  if (thread && thread.key === c.key && thread.parent === parentId && !thread.lines.some((x) => x.id === l.id)) {
    thread.lines.push(l);
    drawThread(c);
  }
  const p = c.lines.get(parentId);
  if (p) {
    p.th = sum || { n: (p.th?.n || 0) + 1, last: { id: l.from, name: l.name } };
    if (open === c.key) redraw(c);
  }
}

// —— The rail: Chats (IMs) and Buddies ——

function markRail(c) {
  const row = rows.get(c.key);
  if (!row) return;
  const badge = $('[data-cx-unread]', row);
  const quiet = muted.has(c.key);
  if (badge) {
    badge.hidden = !c.unread || quiet;
    badge.textContent = c.unread > 99 ? '99+' : String(c.unread);
  }
  row.classList.toggle('is-unread', c.unread > 0 && !quiet);
  row.classList.toggle('is-muted', quiet);
}

/** Channels and chats muted on this browser (chat-menus.js Mute): no count for new lines. */
let muted = new Set();
function setMuted(key, on) {
  if (on) muted.add(key);
  else muted.delete(key);
  try { localStorage.setItem(MUTED_KEY, JSON.stringify([...muted])); } catch { /* private window */ }
  if (rows.has(key)) markRail(convOf(key));
  if (key.startsWith('im:')) drawIms();
}

function drawIms() {
  const list = $('[data-cx-ims]', main);
  if (!list) return;
  const items = [];
  for (const x of ims) {
    if (blocked(x.with.id)) continue;
    const key = `im:${x.with.id}`;
    const c = convOf(key);
    const quiet = muted.has(key);
    const a = el('a', { class: `cx-row${c.unread && !quiet ? ' is-unread' : ''}${quiet ? ' is-muted' : ''}`, href: `/chat/?im=${x.with.id}`, 'data-cx-open': key });
    if (open === key) a.setAttribute('aria-current', 'true');
    const txt = el('span', { class: 'cx-txt' });
    const name = el('span', { class: 'cx-name' });
    name.append(el('span', { class: 'cx-label' }, x.with.name));
    if (x.with.buddy) name.append(el('small', { class: 'cx-note' }, 'Buddy'));
    const last = x.last ? `${x.last.from === ME ? 'You: ' : ''}${x.last.text}` : '';
    txt.append(name, el('span', { class: 'cx-last' }, last));
    a.append(avatar(x.with.name), txt);
    const badge = el('span', { class: 'cx-badge', 'data-cx-unread': '', hidden: !c.unread || quiet }, String(c.unread));
    a.append(badge);
    rows.set(key, a);
    const li = el('li');
    li.append(a);
    items.push(li);
  }
  if (!items.length) items.push(el('li', { class: 'cx-empty' }, WORDS.noIms));
  list.replaceChildren(...items);
  // The open IM's name, now that it is known.
  const c = open.startsWith('im:') ? convs.get(open) : null;
  const head = c && pane.querySelector(`[data-cx-conv="${CSS.escape(open)}"] .cx-head-name`);
  if (c && head && head.textContent !== nameOf(c.id)) {
    c.label = nameOf(c.id);
    head.textContent = c.label;
    document.title = `${c.label} – Community – Groddle Afternoon`;
    const av = head.closest('.cx-head').querySelector('.cx-av');
    if (av) {
      av.textContent = [...c.label][0].toUpperCase();
      av.dataset.hue = String(hueOf(c.label));
    }
    const field = pane.querySelector('#cchan-text');
    if (field) field.placeholder = `Message ${c.label}`;
  }
}

async function loadIms() {
  const r = await call('chat/ims');
  if (!r.ok) return;
  ims = r.data.convs || [];
  for (const x of ims) people.set(x.with.id, { ...(people.get(x.with.id) || {}), id: x.with.id, name: x.with.name });
  drawIms();
}

function statusWords(p, { buddy = false } = {}) {
  if (!p) return WORDS.offline;
  const base = WORDS[p.status] || WORDS.game;
  const street = p.street && streets.get(p.street)?.street;
  if (buddy && p.status !== 'web') return street ? `Online: ${street}` : p.status === 'idle' ? WORDS.idle : 'Online';
  return street ? `${base} · ${street}` : base;
}

function drawBuddies() {
  const list = $('[data-cx-buddies]', main);
  if (!list) return;
  const items = [];
  const all = [...buddies.list].filter((b) => !blocked(b.id));
  all.sort((a, b) => Number(around.has(b.id)) - Number(around.has(a.id)) || a.name.localeCompare(b.name));
  for (const b of all) {
    const p = around.get(b.id);
    const li = el('li', { 'data-online': String(Boolean(p)), 'data-cx-buddy': String(b.id), 'data-name': b.name });
    const info = people.get(b.id);
    li.append(avatar(b.name, 'cx-av-lg', { tile: info?.tile || null, status: p ? p.status : '' }));
    const who = info?.profile ? el('a', { class: 'cx-who', href: info.profile }) : el('span', { class: 'cx-who' });
    who.append(el('span', { class: 'cx-label' }, b.name), el('small', {}, statusWords(p, { buddy: true })));
    li.append(who);
    const im = el('button', { type: 'button', class: 'cx-chip', 'aria-label': `IM ${b.name}` }, 'IM');
    im.addEventListener('click', () => openConv(`im:${b.id}`, { focus: true }));
    li.append(im);
    if (p && p.status !== 'web') li.append(el('a', { class: 'cx-chip', href: '/play/online/', 'aria-label': `Visit ${b.name} in the game` }, 'Visit'));
    items.push(li);
  }
  if (!items.length) items.push(el('li', { class: 'cx-empty' }, buddies.loaded ? WORDS.noBuddies : 'Loading your buddies…'));
  // The players you block, last and quiet, so their menu (Unblock) is still within reach.
  for (const b of [...blockedList].sort((x, y) => x.name.localeCompare(y.name))) {
    const li = el('li', { 'data-online': 'false', 'data-cx-buddy': String(b.id), 'data-name': b.name, 'data-blocked': '' });
    li.append(avatar(b.name, 'cx-av-lg'));
    const who = el('span', { class: 'cx-who' });
    who.append(el('span', { class: 'cx-label' }, b.name), el('small', {}, WORDS.blocked));
    li.append(who);
    items.push(li);
  }
  list.replaceChildren(...items);
}

async function loadBuddies() {
  const r = await call('buddies?seen=0');
  if (!r.ok) return null;
  const seen = new Map();
  for (const b of [...(r.data.buddies || []), ...(r.data.reverse || [])]) if (!seen.has(b.id)) seen.set(b.id, { id: b.id, name: b.name });
  buddies = { list: [...seen.values()], loaded: true };
  held.clear();
  for (const b of r.data.buddies || []) held.add(Number(b.id));
  blocks.clear();
  blockedList = [];
  for (const b of r.data.ignoring || []) {
    blocks.add(Number(b.id));
    blockedList.push({ id: Number(b.id), name: String(b.name || '') });
  }
  drawBuddies();
  return r.data.watch?.token || null;
}

// —— Who's around ——

function drawAround() {
  const list = $('[data-cx-people]', main);
  if (!list) return;
  const count = $('[data-cx-around-count]', main);
  if (!live.on) {
    list.replaceChildren(el('li', { class: 'cx-empty' }, WORDS.aroundDown));
    if (count) count.textContent = '';
    return;
  }
  const all = [...around.values()];
  all.sort((a, b) => Number(Boolean(b.me)) - Number(Boolean(a.me)) || Number(Boolean(b.buddy)) - Number(Boolean(a.buddy)) || a.name.localeCompare(b.name));
  const items = [];
  for (const p of all) {
    const info = people.get(p.id);
    const li = el('li', { 'data-cx-person': String(p.id), 'data-status': p.status, 'data-name': p.name, ...(p.me ? { 'data-me': '' } : {}) });
    li.append(avatar(p.name, 'cx-av-lg', { tile: info?.tile || null, status: p.me && off ? '' : p.status }));
    const who = !p.me && info?.profile ? el('a', { class: 'cx-who', href: info.profile }) : el('span', { class: 'cx-who' });
    who.append(el('span', { class: 'cx-label' }, p.me ? `${p.name} (${WORDS.you})` : p.name), el('small', {}, p.me && off ? WORDS.hidden : statusWords(p)));
    li.append(who);
    if (!p.me && p.buddy) {
      const im = el('button', { type: 'button', class: 'cx-chip', 'aria-label': `IM ${p.name}` }, 'IM');
      im.addEventListener('click', () => openConv(`im:${p.id}`, { focus: true }));
      li.append(im);
    }
    items.push(li);
  }
  if (all.filter((p) => !p.me).length === 0) items.push(el('li', { class: 'cx-empty' }, WORDS.aroundNone));
  list.replaceChildren(...items);
  if (count) count.textContent = String(all.filter((p) => !p.me).length);
  main.dataset.cxAroundN = String(all.filter((p) => !p.me).length);
}

let peopleWait = null;
function needPeople() {
  clearTimeout(peopleWait);
  peopleWait = setTimeout(async () => {
    const ids = [...new Set([...around.keys(), ...buddies.list.map((b) => b.id)])].filter((id) => !people.get(id)?.checked).slice(0, 100);
    const sts = [...new Set([...around.values()].map((p) => p.street).filter((s) => s && !streets.has(s)))].slice(0, 50);
    if (!ids.length && !sts.length) return;
    const r = await call(`chat/people?ids=${ids.join(',')}&streets=${sts.join(',')}`);
    if (!r.ok) return;
    for (const p of r.data.people || []) people.set(p.id, { ...p, checked: true });
    for (const id of ids) if (!people.has(id)) people.set(id, { id, checked: true });
    for (const [t, w] of Object.entries(r.data.streets || {})) streets.set(t, w);
    drawAround();
    drawBuddies();
  }, 150);
}

function aroundToggle(on) {
  const wide = matchMedia('(min-width: 1181px)').matches;
  const next = on ?? (main.dataset.cxAround === 'off' || (!wide && main.dataset.cxAround !== 'on'));
  main.dataset.cxAround = next ? 'on' : 'off';
  if (wide) try { localStorage.setItem('groddle-chat-around', main.dataset.cxAround); } catch { /* private window */ }
  for (const b of document.querySelectorAll('[data-cx-around-open]')) b.setAttribute('aria-expanded', String(next));
}

async function setOffline(next) {
  const box = $('[data-cx-offline]', main);
  if (box) box.disabled = true;
  const r = await call('account/presence', { method: 'PUT', body: { off: next } });
  if (box) box.disabled = false;
  if (r.ok) {
    off = Boolean(r.data.off);
    if (box) box.checked = off;
    drawAround();
  } else if (box) box.checked = off;
}

// —— Live: the socket, else polling ——

let ws = null;
let wait = 2000;
let watchTimer = null;
let pollTimer = null;

function send(t, payload = {}) {
  if (!ws || ws.readyState !== 1 || !live.on) return false;
  ws.send(JSON.stringify({ t, ...payload }));
  return true;
}

function setLive(on) {
  live.on = on;
  main.dataset.cxLive = on ? 'on' : 'off';
  if (on) {
    clearInterval(pollTimer);
    pollTimer = null;
  } else if (!pollTimer) pollTimer = setInterval(poll, POLL_MS);
  drawAround();
}

async function poll() {
  if (document.hidden || live.on || !open) return;
  const c = convOf(open);
  if (!c.loaded) return;
  if (c.kind === 'chan') {
    const r = await call(`chat/channels/${c.id}?after=${c.newest}&since=${c.since}`);
    if (!r.ok || live.on) return;
    keep(c, [...(r.data.changed || []), ...(r.data.lines || [])]);
    c.since = Number(r.data.now) || c.since;
    redraw(c);
  } else {
    const r = await call(`chat/ims/${c.id}?limit=50`);
    if (!r.ok || live.on) return;
    keep(c, r.data.lines);
    redraw(c);
  }
  if (thread) loadThread(convOf(thread.key), thread.parent);
}

async function watchTokens() {
  const [b, g] = await Promise.all([loadBuddies(), call('groups')]);
  return { buddies: b, groups: g.ok ? g.data?.watch?.token || null : null };
}

async function connect() {
  const t = await call('realtime', { method: 'POST', body: { via: 'web' } });
  const tokens = await watchTokens();
  if (!t.ok || !tokens.buddies || !tokens.groups) return retry();
  const url = t.data.url || `${location.protocol === 'https:' ? 'wss:' : 'ws:'}//${location.host}${t.data.path || '/ws'}`;
  let sock;
  try {
    sock = new WebSocket(url);
  } catch {
    return retry();
  }
  ws = sock;
  sock.addEventListener('open', () => sock.send(JSON.stringify({ t: 'auth', ticket: t.data.ticket })));
  sock.addEventListener('message', (e) => {
    let m;
    try { m = JSON.parse(e.data); } catch { return; }
    if (m.t === 'hello') {
      off = Boolean(m.off);
      const box = $('[data-cx-offline]', main);
      if (box) box.checked = off;
      sock.send(JSON.stringify({ t: 'web_watch', buddies: tokens.buddies, groups: tokens.groups }));
      return;
    }
    if (m.t === 'web_status') {
      wait = 2000;
      setLive(true);
      clearInterval(watchTimer);
      watchTimer = setInterval(async () => {
        const fresh = await watchTokens();
        if (fresh.buddies && fresh.groups) send('web_watch', fresh);
      }, WATCH_MS);
      // What was said while we were away.
      if (open) load(convOf(open));
      return;
    }
    onMessage(m);
  });
  sock.addEventListener('close', () => {
    if (ws !== sock) return;
    ws = null;
    clearInterval(watchTimer);
    setLive(false);
    retry();
  });
}

function retry() {
  setLive(false);
  setTimeout(connect, wait);
  wait = Math.min(30000, wait * 2);
}

/** QA: how many of each kind the socket brought. */
const heard = {};
function onMessage(m) {
  heard[m.t] = (heard[m.t] || 0) + 1;
  // Something that may be a notification (src/Notifications.php): the bell asks again in a moment.
  if (m.t === 'thread_said' || m.t === 'board_post' || m.t === 'buddy_added' || (m.t === 'im' && Number(m.line?.from) !== ME)) notif?.soon();
  switch (m.t) {
    case 'channel_said': {
      const key = `chan:${m.channel}`;
      if (!rows.has(key) && !convs.has(key)) return;
      const c = convOf(key);
      if (!c.loaded && open !== key) {
        if (Number(m.id) !== ME) c.unread += 1;
        markRail(c);
        lastOn(key, m);
        return;
      }
      keep(c, [m]);
      if (open === key) redraw(c);
      else if (Number(m.id) !== ME) {
        c.unread += 1;
        markRail(c);
      }
      lastOn(key, m);
      return;
    }
    case 'thread_said': {
      const c = convOf(`chan:${m.channel}`);
      addReply(c, Number(m.parent), m.line, { n: Number(m.n) || 1, last: m.last });
      // A reply under your own line, while that thread is not open: the bell has it at once.
      const parent = c.lines.get(Number(m.parent));
      const by = Number(m.line?.id);
      if (parent?.from === ME && by !== ME && !blocked(by) && !(thread && thread.key === c.key && thread.parent === Number(m.parent))) {
        notif?.hint({ key: `th:${m.channel}:${Number(m.parent)}`, kind: 'thread', at: Number(m.line?.at) || Date.now(), from: { id: by, name: String(m.line?.name || '') }, text: `${m.line?.name || 'Someone'} replied to your line in ${c.label}`, snippet: parent.text, url: `/chat/?c=${m.channel}#t${Number(m.parent)}`, open: { conv: c.key, thread: Number(m.parent) } });
      }
      return;
    }
    case 'reacted':
      setReactions(`l:${Number(m.line)}`, m.r || []);
      return;
    case 'im_reacted':
      setReactions(`im:${Number(m.with)}:${Number(m.line)}`, m.r || []);
      return;
    case 'im': {
      const w = Number(m.with?.id);
      if (!(w > 0) || blocked(w)) return;
      people.set(w, { ...(people.get(w) || {}), id: w, name: m.with.name || nameOf(w) });
      const c = convOf(`im:${w}`);
      c.label = m.with.name || c.label;
      const line = m.line || {};
      if (Number(line.parent) > 0) {
        addReply(c, Number(line.parent), line);
        return;
      }
      keep(c, [line]);
      const mine = Number(line.from) === ME;
      const at = ims.findIndex((x) => x.with.id === w);
      const entry = { with: { id: w, name: c.label, buddy: buddies.list.some((b) => b.id === w) }, last: { from: Number(line.from), text: String(line.text || ''), at: Number(line.at) || Date.now() } };
      if (at >= 0) ims.splice(at, 1);
      ims.unshift(entry);
      if (open === c.key) {
        redraw(c);
        if (!mine) send('im_read', { with: w });
      } else if (!mine) c.unread += 1;
      // An IM while you weren't looking (another conversation open, or the tab hidden): the bell has it at once.
      if (!mine && (open !== c.key || document.hidden)) {
        const name = c.label;
        notif?.hint({ key: c.key, kind: 'im', at: Number(line.at) || Date.now(), from: { id: w, name }, textFor: (n) => (n > 1 ? `${name} sent you ${n} IMs` : `${name} sent you an IM`), snippet: String(line.text || ''), url: `/chat/?im=${w}`, open: { conv: c.key } });
      }
      drawIms();
      return;
    }
    case 'presence':
      around.clear();
      for (const p of m.players || []) around.set(Number(p.id), p);
      off = Boolean(m.you?.off);
      needPeople();
      drawAround();
      drawBuddies();
      return;
    case 'presence_up':
      if (m.player) around.set(Number(m.player.id), m.player);
      needPeople();
      drawAround();
      drawBuddies();
      return;
    case 'presence_gone':
      around.delete(Number(m.id));
      drawAround();
      drawBuddies();
      return;
    case 'presence_you': {
      off = Boolean(m.off);
      const box = $('[data-cx-offline]', main);
      if (box) box.checked = off;
      drawAround();
      return;
    }
    case 'board_post':
      if (topicNow.thread && Number(m.thread) === topicNow.thread) topicNow.fn?.();
      else if (main.dataset.board === m.board || pane.querySelector(`[data-cx-board="${CSS.escape(String(m.board))}"]`)) boardNews(m);
      return;
    case 'booted':
      location.reload();
      return;
    default:
  }
}

function lastOn(key, m) {
  const row = rows.get(key);
  const last = row && $('[data-cx-last]', row);
  if (last) last.textContent = `${Number(m.id) === ME ? 'You' : m.name}: ${m.text}`;
}

/** A new topic or reply on the board being read: say so at the top of its list. */
function boardNews() {
  let note = pane.querySelector('[data-cx-board-news]');
  if (!note) {
    note = el('p', { class: 'cnew', 'data-cx-board-news': '' });
    const a = el('a', { href: location.pathname }, 'New posts: show them');
    note.append(a);
    pane.querySelector('.cx-list-log')?.prepend(note);
  }
}

// —— Wiring ——

export function start(helpers) {
  ({ main, call, say, SAID, openPicker, live, lineReact, topicNow, notif, nav } = helpers);
  ME = Number(main.dataset.me) || 0;
  try { muted = readMuted(localStorage.getItem(MUTED_KEY)); } catch { muted = new Set(); }
  pane = $('[data-cx-pane]', main);
  aroundBox = $('[data-cx-around]', main);
  threadBox = $('[data-cx-thread]', main);
  lineReact.fn = reactLine;
  try {
    for (const r of JSON.parse(main.dataset.reactions || '[]')) REACT_DEFS.set(r.key, { glyph: r.glyph, label: r.label, art: r.art });
  } catch { /* none */ }
  // The channels the page drew in the rail.
  for (const a of main.querySelectorAll('[data-cx-open^="chan:"]')) rows.set(a.dataset.cxOpen, a);
  main.addEventListener('click', (e) => {
    // A rail row: in place (Cmd, Ctrl, Shift, Alt and the middle button keep the link's new tab).
    const row = e.target.closest('[data-cx-open]');
    if (row && !e.defaultPrevented && !e.metaKey && !e.ctrlKey && !e.shiftKey && !e.altKey && e.button === 0) {
      e.preventDefault();
      openConv(row.dataset.cxOpen, { focus: false });
      return;
    }
    const th = e.target.closest('[data-cx-thread-open], [data-cx-replies]');
    if (th) {
      e.preventDefault();
      const key = th.closest('[data-cx-conv]')?.dataset.cxConv || open;
      openThread(key, Number(th.dataset.cxThreadOpen || th.dataset.cxReplies));
      return;
    }
    const chip = e.target.closest('.cchan-line button[data-react]');
    if (chip) {
      reactLine(chip.closest('[data-rk]'), chip.dataset.react, chip.getAttribute('aria-pressed') !== 'true');
      return;
    }
    const add = e.target.closest('.cchan-line [data-react-open]');
    if (add) {
      openPicker(add.closest('[data-rk]'), add);
      return;
    }
    if (e.target.closest('[data-cx-around-open]')) aroundToggle();
    if (e.target.closest('[data-cx-around-close]')) aroundToggle(false);
  });
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    if (thread) closeThread({ note: true });
    else if (main.dataset.cxAround === 'on' && !matchMedia('(min-width: 1181px)').matches) aroundToggle(false);
  });
  $('[data-cx-offline]', main)?.addEventListener('change', (e) => setOffline(e.target.checked));
  try {
    const saved = localStorage.getItem('groddle-chat-around');
    if (matchMedia('(min-width: 1181px)').matches) main.dataset.cxAround = saved === 'off' ? 'off' : 'on';
    else main.dataset.cxAround = 'off';
  } catch { main.dataset.cxAround = matchMedia('(min-width: 1181px)').matches ? 'on' : 'off'; }
  // Input keeps you from showing idle.
  let activeAt = 0;
  const active = () => {
    const now = Date.now();
    if (now - activeAt < ACTIVE_MS) return;
    if (send('active')) activeAt = now;
  };
  for (const k of ['keydown', 'pointerdown', 'wheel']) document.addEventListener(k, active, { passive: true });
  // Back and Forward, and every move inside /chat/, come through chat-nav.js.
  if (nav) {
    nav.app.show = showRoute;
    nav.app.leave = leaveConv;
  }

  loadIms();
  call('account/presence').then((r) => {
    if (!r.ok) return;
    off = Boolean(r.data.off);
    const box = $('[data-cx-offline]', main);
    if (box) {
      box.checked = off;
      box.disabled = false;
    }
  });
  setLive(false);
  connect();
  if (main.dataset.cxActive === 'chan') {
    const q = new URLSearchParams(location.search);
    const first = main.querySelector('[data-cx-open^="chan:"]')?.dataset.cxOpen || '';
    const key = q.get('im') ? `im:${Number(q.get('im'))}` : q.get('c') && rows.has(`chan:${q.get('c')}`) ? `chan:${q.get('c')}` : first;
    if (key) {
      // A link to a line (#l<id>, Copy link to this line) or to its thread (#t<id>).
      wanted = { key, ...lineHash(location.hash) };
      showConv(key);
      // A phone opens on the list unless a conversation was asked for.
      if (!q.get('im') && !q.get('c')) main.dataset.cxView = 'list';
    }
  }
  // The bell (community/notifications.js): what is being looked at is read, and a click opens in place.
  if (notif) {
    notif.app.looking = () => [
      ...(open.startsWith('im:') ? [open] : []),
      ...(thread && thread.key.startsWith('chan:') ? [`th:${thread.key.slice(5)}:${thread.parent}`] : []),
    ];
    notif.app.open = ({ conv, thread: lid }) => {
      if (!/^(?:chan:(?:global|help|trade)|im:\d+)$/.test(conv) || (conv.startsWith('chan:') && !rows.has(conv))) return false;
      const url = `${convPath(conv)}${lid ? `#t${Number(lid)}` : ''}`;
      if (nav) nav.go(url);
      else showRoute({ view: conv.startsWith('im:') ? 'im' : 'chan', key: conv, hash: lid ? `#t${Number(lid)}` : '' });
      return true;
    };
  }
  // Right-click menus (community/chat-menus.js, the game's context-menu component).
  import(`./chat-menus.js${new URL(import.meta.url).search}`).then((m) => m.startMenus(menuApi())).catch((e) => console.warn('chat menus', e));
  globalThis.groddleChat = {
    get live() { return live.on; },
    get open() { return open; },
    get thread() { return thread ? `${thread.key}#${thread.parent}` : ''; },
    around: () => [...around.values()].map((p) => ({ ...p })),
    heard: () => ({ ...heard }),
    convs: () => [...convs.values()].map((c) => ({ key: c.key, lines: c.lines.size, unread: c.unread })),
    show: (key) => openConv(key),
    openThread: (key, id) => openThread(key, Number(id)),
    send,
  };
}
