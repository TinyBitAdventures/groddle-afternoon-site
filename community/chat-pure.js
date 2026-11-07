/**
 * The /chat/ app's pure parts (no page, no network), so the game's tests can run them: the rows of
 * the right-click menus (community/chat-menus.js), the notifications panel's order, counts and
 * sample set (community/notifications.js, src/Notifications.php), the link to a line, the
 * local-only preview flag, and what an address inside /chat/ shows (community/chat-nav.js).
 */

/** The menus' rows. Labels are the drawer's words; `tip` is the footer line, `reason` why a row can't be done. */
export function playerRows({ me = 0, id = 0, profile = null, buddy = false, blocked = false, canIm = true } = {}) {
  if (!(id > 0) || id === me) return [];
  return [
    { id: 'profile', label: 'View profile', tip: 'Their page on the website.', disabled: !profile, reason: 'Their profile page is not public yet.' },
    { id: 'im', label: 'Send IM', tip: 'A private message, here and in the game.', disabled: blocked || !canIm, reason: blocked ? 'Unblock them to send an IM.' : 'Only players can send IMs.' },
    buddy
      ? { id: 'unbuddy', label: 'Remove buddy', tip: 'Take them off your buddy list.' }
      : { id: 'buddy', label: 'Add buddy', tip: 'Put them on your buddy list.', disabled: blocked, reason: 'Unblock them first.' },
    blocked
      ? { id: 'unblock', label: 'Unblock', tip: 'See their lines again.' }
      : { id: 'block', label: 'Block', tip: 'Hide their lines and posts, and stop their IMs.' },
    { id: 'report', label: 'Report', tip: 'Tell the team about this player.' },
  ];
}

/** del: the reader is an administrator and the line is a channel's (src/ChatHistory.php deleteLine checks it again). */
export function lineRows({ thread = true, react = true, mine = false, copy = true, del = false } = {}) {
  return [
    ...(thread ? [{ id: 'thread', label: 'Reply in thread', tip: 'Answer under this line.' }] : []),
    ...(react ? [{ id: 'react', label: 'React', tip: 'Add a reaction.' }] : []),
    ...(copy ? [{ id: 'copy', label: 'Copy text', tip: 'The words of this line.' }] : []),
    { id: 'link', label: 'Copy link to this line', tip: 'A link that opens the chat at this line.' },
    ...(mine ? [] : [{ id: 'report', label: 'Report', tip: 'Tell the team about this line.' }]),
    ...(del ? [{ id: 'delete', label: 'Delete line', tip: 'Take it away for everyone. The team\'s chat log keeps it.', focusBack: false }] : []),
  ];
}

/** The confirm step after Delete line (the page has no window.confirm): Cancel is the one focused. */
export const DELETE_CONFIRM = Object.freeze({ title: 'Delete this line for everyone?', focus: 1 });
export function deleteConfirmRows() {
  return [
    { id: 'confirm', label: 'Delete for everyone', tip: 'It disappears from the game and this page, and does not come back.' },
    { id: 'cancel', label: 'Cancel', tip: 'Keep the line.' },
  ];
}

/** The channel key ('global', 'help', 'trade') of a conversation an administrator may delete lines in, or ''. */
export function deletableKey(conv) {
  const m = /^chan:(global|help|trade)$/.exec(String(conv || ''));
  return m ? m[1] : '';
}

/** A board post's rows: its own actions (shown under it) and the copies. */
export function postRows({ quote = false, react = false, edit = false, del = false, report = false } = {}) {
  return [
    ...(quote ? [{ id: 'quote', label: 'Quote', tip: 'Reply with their words.' }] : []),
    ...(react ? [{ id: 'react', label: 'React', tip: 'Add a reaction.' }] : []),
    { id: 'copy', label: 'Copy text', tip: 'The words of this post.' },
    { id: 'link', label: 'Copy link to this post', tip: 'A link to this post.' },
    ...(edit ? [{ id: 'edit', label: 'Edit', tip: 'Change your words (15 minutes).' }] : []),
    ...(del ? [{ id: 'delete', label: 'Delete', tip: 'It shows as deleted in the topic.' }] : []),
    ...(report ? [{ id: 'report', label: 'Report', tip: 'Tell the team about this post.' }] : []),
  ];
}

/** A rail row's rows: a channel or a chat can be read and muted (muting keeps its count quiet on this browser); a board can be read. */
export function railRows({ kind = 'chan', unread = 0, muted = false } = {}) {
  const read = { id: 'read', label: 'Mark as read', tip: kind === 'board' ? 'Its notifications too.' : 'Clear its count.', disabled: !(unread > 0), reason: 'Nothing new here.' };
  if (kind === 'board') return [{ id: 'open', label: 'Open', tip: 'Its topics.' }, read];
  return [
    { id: 'open', label: 'Open', tip: kind === 'im' ? 'The conversation.' : 'The channel.' },
    read,
    muted ? { id: 'unmute', label: 'Unmute', tip: 'Count new lines again.' } : { id: 'mute', label: 'Mute', tip: 'No count for new lines, on this browser.' },
  ];
}

// —— Links to a line ——

/** The link that opens /chat/ at a line: ?c=<channel> or ?im=<player>, then #l<line>. */
export function lineLink(origin, conv, line) {
  const [kind, id] = String(conv || '').split(':');
  const q = kind === 'im' ? `im=${Number(id) || 0}` : `c=${encodeURIComponent(id || '')}`;
  return `${String(origin || '').replace(/\/$/, '')}/chat/?${q}#l${Math.floor(Number(line) || 0)}`;
}

/** What a /chat/ hash asks for: #l<id> a line, #t<id> its thread. */
export function lineHash(hash) {
  const m = /^#([lt])([1-9]\d{0,18})$/.exec(String(hash || ''));
  return m ? { [m[1] === 'l' ? 'line' : 'thread']: Number(m[2]) } : {};
}

// —— Muted rows (this browser only) ——

export const MUTED_KEY = 'groddle-chat-muted';
const ROW_KEY = /^(?:chan:(?:global|help|trade)|im:[1-9]\d{0,18})$/;

export function readMuted(json) {
  try {
    const list = JSON.parse(json || '[]');
    return new Set(Array.isArray(list) ? list.filter((k) => typeof k === 'string' && ROW_KEY.test(k)).slice(0, 200) : []);
  } catch {
    return new Set();
  }
}

// —— Notifications ——

/** Newest first, then by key (src/Notifications.php merge). */
export function sortNotifications(items) {
  return [...(items || [])].sort((a, b) => (Number(b.at) || 0) - (Number(a.at) || 0) || String(a.key).localeCompare(String(b.key)));
}

export function unreadCount(items) {
  return (items || []).filter((i) => i && i.unread === true).length;
}

/** The items after reading one key, or all of them (null). */
export function markRead(items, key = null) {
  return (items || []).map((i) => (key === null || i.key === key ? { ...i, unread: false } : i));
}

/** The count as the bell and the link show it. */
export const countLabel = (n) => (n > 9 ? '9+' : n > 0 ? String(n) : '');

/** "now", "5 min", "3 h", "2 d", then the date. */
export function agoLabel(at, now) {
  const s = Math.max(0, Math.round(((Number(now) || 0) - (Number(at) || 0)) / 1000));
  if (s < 60) return 'now';
  if (s < 3600) return `${Math.floor(s / 60)} min`;
  if (s < 86400) return `${Math.floor(s / 3600)} h`;
  if (s < 7 * 86400) return `${Math.floor(s / 86400)} d`;
  return new Date(Number(at)).toLocaleDateString([], { month: 'short', day: 'numeric' });
}

/** Whether the sample notifications show: the page asked (?notifdemo=1, drawn only for a .localhost site) and this is one. */
export function demoAllowed(hostname, pageFlag) {
  return pageFlag === true && /(?:^|\.)localhost$/i.test(String(hostname || ''));
}

/**
 * The sample set for the local preview (Austin 2026-11-04: "so Austin can see it locally"): one of
 * each kind, some read, made up names, nothing written anywhere. Each is marked sample.
 */
export function demoNotifications(now) {
  const m = 60 * 1000;
  const h = 60 * m;
  const list = [
    { key: 'im:900001', kind: 'im', at: now - 2 * m, unread: true, from: { id: 900001, name: 'Juniper Fizz' }, count: 3, text: 'Juniper Fizz sent you 3 IMs', snippet: 'Are you coming to the party on Groddle Meadow? Bring a piggy.', url: '/chat/?im=900001' },
    { key: 'th:global:900002', kind: 'thread', at: now - 9 * m, unread: true, from: { id: 900002, name: 'Bramble' }, count: 2, text: 'Bramble and 1 other replied to your line in Global Chat', snippet: 'Anyone know where the Rook will hit next?', url: '/chat/?c=global#t900002' },
    { key: 'n:900003', kind: 'quote', at: now - 40 * m, unread: true, from: { id: 900003, name: 'Moss Weaver' }, count: 1, text: 'Moss Weaver quoted you in “Best way to level Gardening”', snippet: '', url: '/chat/general/' },
    { key: 'bud:900004', kind: 'buddy', at: now - 2 * h, unread: true, from: { id: 900004, name: 'Pickle Owens' }, count: 1, text: 'Added you: Pickle Owens', snippet: 'Add them back from their menu.', url: '/players/' },
    { key: 'tk:900005', kind: 'ticket', at: now - 5 * h, unread: false, from: null, count: 1, text: 'The team replied to your report “Stuck on the ladder in Ix”', snippet: 'Thanks for the report! That ladder is fixed now.', url: '/play/online/' },
    { key: 'n:900006', kind: 'reply', at: now - 26 * h, unread: false, from: { id: 900006, name: 'Tangerine' }, count: 4, text: '4 new replies to your topic “Show us your home street”', snippet: '', url: '/chat/off-topic/' },
    { key: 'bud:900007', kind: 'buddy', at: now - 3 * 24 * h, unread: false, from: { id: 900007, name: 'Wren Hollow' }, count: 1, text: 'Added you: Wren Hollow', snippet: 'You are buddies now.', url: '/players/' },
  ];
  return sortNotifications(list.map((i) => ({ ...i, sample: true })));
}

// —— Moving around /chat/ without a reload (community/chat-nav.js) ——

/** The boards' slugs as the routes take them (src/CommunityPages.php {board:[a-z-]+}). */
const BOARD = /^[a-z-]+$/;
const CHANNEL_KEY = /^[a-z0-9-]{1,40}$/;

/**
 * What an address shows in the /chat/ app, or null when it is not one of the app's views (another
 * page, a form's post address, another site): the browser loads those itself.
 *   /chat/                 { view: 'home' }
 *   /chat/?c=<key>         { view: 'chan', key: 'chan:<key>' }
 *   /chat/?im=<id>         { view: 'im', key: 'im:<id>' }
 *   /chat/<board>/         { view: 'board', board, page }
 *   /chat/<board>/new/     { view: 'new', board }
 *   /chat/<board>/<id>-<slug>/  { view: 'thread', board, thread }
 * Each also carries path (path and query, the view's address without the hash) and hash.
 */
export function chatRoute(href, origin) {
  let u;
  try { u = new URL(String(href || ''), origin); } catch { return null; }
  if (u.origin !== new URL(origin).origin || !u.pathname.startsWith('/chat/')) return null;
  const base = { path: u.pathname + u.search, hash: u.hash };
  const rest = u.pathname.slice('/chat/'.length);
  if (rest === '') {
    const im = u.searchParams.get('im');
    const c = u.searchParams.get('c');
    if (im !== null && /^[1-9]\d{0,18}$/.test(im)) return { ...base, view: 'im', key: `im:${im}` };
    if (c !== null && CHANNEL_KEY.test(c)) return { ...base, view: 'chan', key: `chan:${c}` };
    return { ...base, view: 'home' };
  }
  const parts = rest.split('/');
  if (parts.at(-1) !== '' || !BOARD.test(parts[0])) return null;
  if (parts.length === 2) return { ...base, view: 'board', board: parts[0], page: Math.max(1, Number(u.searchParams.get('page')) || 1) };
  if (parts.length === 3 && parts[1] === 'new') return { ...base, view: 'new', board: parts[0] };
  const m = parts.length === 3 ? /^([1-9]\d{0,18})(?:-[a-z0-9-]+)?$/.exec(parts[1]) : null;
  if (m) return { ...base, view: 'thread', board: parts[0], thread: Number(m[1]) };
  return null;
}

/** The address of a conversation: /chat/?c=<channel> or /chat/?im=<player>. */
export function convPath(key) {
  const [kind, id] = String(key || '').split(':');
  return kind === 'im' ? `/chat/?im=${Number(id) || 0}` : `/chat/?c=${encodeURIComponent(id || '')}`;
}

/**
 * Whether a click on a link is the plain kind the app takes over: the main button, no modifier
 * (Cmd, Ctrl, Shift and Alt keep their new tab, new window and download), not already handled, and
 * a link meant for this tab.
 */
export function plainClick(e, link = {}) {
  if (!e || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return false;
  const target = String(link.target || '');
  return (target === '' || target === '_self') && !link.download;
}

/** Whether going from one address to another only moves to a place on the same view (#post-3, #reply). */
export function sameView(from, to) {
  return Boolean(from && to && from.path === to.path);
}
