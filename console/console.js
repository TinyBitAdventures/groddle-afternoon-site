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
 * - Rook attacks: every street with a Rook over it now (admin/rooks, the realtime server's own
 *   list, polled with Online now), with its phase, health and wing beats and who is there, and
 *   End attack (a confirm step first; admin/rook_end): the Rook flies off as after its third wing
 *   beat, nobody gets iMG, what it rooked stays rooked (Austin 2026-11-03).
 * - Message everyone: one message to every player online now (admin/broadcast),
 *   with how many will see it and a confirm step; one a minute.
 * - Bans with Unban, and the activity log of every administrator's action.
 * - Reports (the second tab; src/AdminTickets.php): players' bug reports as
 *   threads, unread first, with a status filter; a thread shows what the game
 *   knew, the picture of the game when the report carries one (a click opens it
 *   full size in a popup, Cmd-click in a tab; pictureSrc), the messages, a reply box (the player is emailed, or with Don't
 *   email ticked it is a note they only see in the game; the box stays
 *   ticked from report to report, for closing many related ones), Mark working
 *   on it (and Not working on it: ticket 49, a status the player sees as its
 *   own chip; no email) and Close or Reopen. A first message the player
 *   changed before anyone read it says edited. Polled every TICKETS_POLL_MS
 *   while the tab is visible. #reports opens the tab, #report=<id> a report.
 * - Chat log (a tab of its own; src/AdminConsole.php chatlog, the realtime server's log): what
 *   players said on streets, in channels and in parties, newest first, refused lines marked
 *   (too fast, or silenced in a channel). Search by player (a name, or #id), words, kind, and
 *   days (the viewer's own days: chatlogParams), or refused lines only; Older pages back. Times
 *   show in the viewer's time zone. A player's menu has Chat history, which opens the tab
 *   filtered to them; a name in the log opens that player's menu. #chat opens it, #chat=<id>
 *   filtered to one player.
 * - Credits (src/AdminReportCredits.php): the points each report earned for the Contribute page's
 *   testers board, the board as it stands and the closed and answered reports not scored yet (a click
 *   opens the report). A report's thread has a Credit box: its score (kind, points, reason, refs, who
 *   scored it and when) and a form to set or change it (PUT admin/tickets/<id>/credit; picking a kind
 *   puts in its points). #credits opens the tab.
 * - Boards (src/AdminBoards.php, Austin 2026-11-04): the notes players pinned on the shared Notice
 *   Boards (src/LedgerBoard.php), reported ones first: where, the words, who wrote and who pinned it,
 *   how many reported it (a player's Report makes a ticket in Reports too). Show On the boards,
 *   Reported or All. Remove (a confirm step first; admin/board_remove) takes it off the board for
 *   everyone there; nothing goes back to anyone. #boards opens the tab.
 * - Community (src/AdminCommunity.php, Austin 2026-11-04): posts on the Community boards (/chat/,
 *   src/Community.php), Reported, Newest or Removed: the thread (a link to the post), the words, the
 *   writer, how many reported it. Remove and Restore (a confirm step first; removing a thread's first
 *   post takes the thread off its board), Pin and Lock on the thread, Stop posting on the writer (1, 3,
 *   7 or 30 days or until lifted, with a reason), and who is stopped with Lift. #community opens it.
 * - Builder (the last tab, only when the site option groddle_builder_enabled is on;
 *   src/AdminBuilder.php): the level builder's drafts with their streets, each opening the
 *   builder page (/console/builder/#draft=<id>), Archive, and a new draft (title, and an id
 *   made from it until you type your own). Loaded when the tab opens, then every
 *   DRAFTS_POLL_MS while it is shown. #builder opens it.
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
/** An administrator's reply (src/TicketStore.php MAX_ADMIN_MESSAGE). */
export const TICKET_MAX = 10000;
export const DRAFTS_POLL_MS = 15000;

/** A draft id from a title: lowercase words joined by hyphens, starting with a letter, 3 to 48 long (src/BuilderStore.php ID). */
export function draftIdFrom(title) {
  let id = String(title || '').normalize('NFKD').replace(/[\u0300-\u036f]/g, '').toLowerCase()
    .replace(/[^a-z0-9]+/g, '-').replace(/^[^a-z]+/, '').replace(/-+$/, '').slice(0, 48).replace(/-+$/, '');
  if (id.length > 0 && id.length < 3) id = `${id}-zone`.slice(0, 48);
  return id;
}

/** Whether an id is a draft id the site takes. */
export function draftIdOk(id) {
  return /^[a-z][a-z0-9-]{2,47}$/.test(String(id || ''));
}

/** The builder page's address for a draft (and one of its streets). */
export function builderHref(draft, street = '') {
  const id = encodeURIComponent(String(draft || ''));
  return `/console/builder/#draft=${id}${street ? `&street=${encodeURIComponent(street)}` : ''}`;
}

/** One draft's line under its title: rev, streets, when. */
export function draftWords(d, when = (iso) => iso) {
  const n = Array.isArray(d?.streets) ? d.streets.length : 0;
  const streets = n === 0 ? 'no streets yet' : n === 1 ? '1 street' : `${n} streets`;
  return `rev ${d?.rev ?? 0} · ${streets} · saved ${when(d?.updatedAt || '')}`;
}

/** The reply box's button and hint, emailing or not (Don't email ticked). */
export function replyFormWords(noEmail) {
  return noEmail
    ? { send: 'Add note', placeholder: 'Plain text. The player sees it in the game. No email goes.' }
    : { send: 'Send reply', placeholder: 'Plain text. The player sees it in the game and gets an email.' };
}

/** A report's picture address from the site (src/TicketPictures.php), or '' for anything else. */
export function pictureSrc(picture) {
  const url = typeof picture?.url === 'string' ? picture.url : '';
  return /^\/report-pictures\/\d{1,12}$/.test(url) ? url : '';
}

/** A ticket's status in words (src/TicketStore.php STATUSES). */
/** The kinds of change a report can lead to, with their points (src/ReportCredits.php KINDS, KIND_WORDS, CAP). */
export const CREDIT_KINDS = Object.freeze({ safety_fix: 5, bug_fixed: 3, improvement_shipped: 2, partial_or_clue: 1, none: 0 });
export const CREDIT_WORDS = Object.freeze({ safety_fix: 'Safety fix', bug_fixed: 'Bug fixed', improvement_shipped: 'Improvement shipped', partial_or_clue: 'Partial fix or clue', none: 'No change' });
export const CREDIT_CAP = 8;

/** Refs typed in one box: split on commas, trimmed, blanks and repeats dropped. */
export function refsFrom(text) {
  const out = [];
  for (const r of String(text || '').split(',')) {
    const t = r.trim().replace(/\s+/g, ' ');
    if (t && !out.includes(t)) out.push(t);
  }
  return out;
}

/** A credit in one line: "4 points, Bug fixed, by the agent". */
export function creditWords(c) {
  if (!c) return 'Not scored yet';
  const pts = c.points === 1 ? '1 point' : `${c.points ?? 0} points`;
  return `${pts}, ${CREDIT_WORDS[c.kind] || c.kind}${c.by === 'agent' ? ', by the agent' : ''}`;
}

/** The PUT body from the Credit box's fields. */
export function creditBody({ kind = '', points = '', reason = '', refs = '' } = {}) {
  const n = Number(points);
  return { kind, points: Number.isInteger(n) && String(points).trim() !== '' ? n : CREDIT_KINDS[kind] ?? 0, reason: String(reason).trim(), refs: refsFrom(refs) };
}

export const TICKET_STATUS = Object.freeze({ open: 'Open', answered: 'Answered', working: 'Working on it', closed: 'Closed' });

/**
 * An edited message's earlier texts (Austin 2026-11-02), oldest first, folded under the message:
 * each with when it was written and when the next edit replaced it. Null when it was never edited.
 */
export function messageHistory(m, when) {
  const revs = Array.isArray(m?.revisions) ? m.revisions : [];
  if (!revs.length) return null;
  const n = revs.length;
  return el('details', { class: 'console-msg-history' },
    el('summary', { text: `Edited ${n === 1 ? 'once' : `${n} times`}: earlier ${n === 1 ? 'version' : 'versions'}` }),
    el('ol', {}, ...revs.map((r, i) => el('li', {},
      el('p', { class: 'console-msg-who' }, el('time', { datetime: r.at, text: `Version ${i + 1} · written ${when(r.at)}, replaced ${when(r.replaced)}` })),
      el('p', { class: 'console-msg-body', text: r.body })))));
}

/** A message's byline time: when it was written, and edited when the player changed it (ticket 49). */
export function messageWhen(m, when) {
  const at = when(m?.at);
  return m?.edited ? `${at} · edited` : at;
}

/** What the game knew, in the order and words the old Bug report posts used (src/BugReports.php CONTEXT). */
export const CONTEXT_LABELS = Object.freeze([['streetName', 'Street'], ['street', 'Street id'], ['x', 'x'], ['y', 'y'], ['build', 'Build'], ['page', 'Page'], ['agent', 'Browser'], ['screen', 'Screen'], ['panels', 'Open windows']]);

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
  broadcastCount: (n) => (n === 0 ? 'Nobody is online to see it.' : n === 1 ? '1 player online will see it.' : `${n} players online will see it.`),
  broadcastConfirm: (n) => (n === 1 ? 'Send to 1 player?' : `Send to ${n} players?`),
  rookEndConfirm: (label) => `End the Rook attack on ${label}?`,
  rookEndNote: 'The Rook flies off as when it leaves after its third wing beat. Everyone there sees it go. Nobody gets iMG or a badge for it, and what it rooked stays rooked until revived.',
  boardRemoveConfirm: (label) => `Remove this note from the board in ${label}?`,
  boardRemoveNote: 'It comes off the board for everyone there. Nobody gets the note back, and the player who pinned it is not told.',
  communityNone: { reported: 'No post has been reported.', recent: 'Nobody has posted on the boards yet.', removed: 'No post has been removed.' },
  communityRemoveConfirm: (first) => (first ? 'Remove this thread from its board?' : 'Remove this post?'),
  communityRemoveNote: (first) => (first ? 'Its first post is removed and the whole thread leaves its board for everyone. Restore brings it back.' : 'Its words are hidden for everyone; the thread says a moderator removed it. Restore brings it back.'),
  communityStopTitle: (name) => `Stop ${name} posting on the boards`,
  communityStopNote: 'They can still read the boards and play. A suspended account can\'t post either.',
  boardNone: { pinned: 'No notes are pinned on the boards.', reported: 'No note on a board has been reported.', all: 'No player has pinned a note yet.' },
});

/** A board note's status in words (src/LedgerBoard.php). */
export const BOARD_STATUS = Object.freeze({ pinned: 'On the board', taken: 'Taken', removed: 'Removed' });

/** Who wrote a board note, and who pinned it when that is someone else. */
export function boardWho(n) {
  if (n?.remake) return 'The remake\'s own notice';
  const by = n?.author?.name || n?.poster?.name || 'Someone';
  return n?.poster && n?.author && n.poster.id !== n.author.id ? `${by}, pinned by ${n.poster.name}` : by;
}

/** "Reported by 2", or ''. */
export function boardReports(n) {
  const k = Math.max(0, Number(n?.reports) || 0);
  return k === 0 ? '' : k === 1 ? 'Reported once' : `Reported by ${k}`;
}

/** Whether Remove is offered: only while it is on its board. */
export function boardRemovable(n) {
  return n?.status === 'pinned';
}

/** A Community post's status in words (src/Community.php). */
export const COMMUNITY_STATUS = Object.freeze({ shown: 'Showing', deleted: 'Deleted by its writer', removed: 'Removed' });

/** The lengths a stop from posting can be (src/AdminCommunity.php STOP_DAYS), in words. */
export const COMMUNITY_STOP_DAYS = Object.freeze([[1, '1 day'], [3, '3 days'], [7, '7 days'], [30, '30 days'], [0, 'Until lifted']]);

/** "until Nov 9" or "until lifted" for a stop's end (unix seconds; 0 until lifted). */
export function stopUntil(until) {
  return until ? `until ${new Date(until * 1000).toLocaleDateString([], { month: 'short', day: 'numeric' })}` : 'until lifted';
}

/** A Rook attack's phase in words (groddle-afternoon-server rook.go phases). */
export const ROOK_PHASE = Object.freeze({
  herald: 'Coming: the herald is warning the street',
  build_up: 'Over the street',
  angry: 'Angry',
  stunned: 'Stunned',
  dead: 'Defeated, leaving',
  departed: 'Flying off',
});

/** One attack's Rook column: phase, health, stun when it has some, wing beats. */
export function rookWords(a) {
  const phase = a?.phase === 'angry' && a?.angry === 'angry3' ? 'Angry: about to strike' : ROOK_PHASE[a?.phase] || String(a?.phase || '');
  const parts = [phase, `health ${a?.health ?? 0} / ${a?.maxHealth ?? 0}`];
  if ((a?.stun ?? 0) > 0) parts.push(`stun ${a.stun} / ${a.maxStun ?? 0}`);
  const n = a?.wingBeats ?? 0;
  parts.push(n === 1 ? '1 wing beat of 3' : `${n} wing beats of 3`);
  return parts.join(' · ');
}

/** Whether End attack is offered: not while it is already leaving. */
export function rookEndable(a) {
  return Boolean(a) && a.phase !== 'dead' && a.phase !== 'departed';
}

/** "Ada, Bo and Cy", or "Nobody". */
export function rookPlayers(a) {
  const names = (Array.isArray(a?.players) ? a.players : []).map((p) => p.name);
  if (!names.length) return 'Nobody';
  return names.length === 1 ? names[0] : `${names.slice(0, -1).join(', ')} and ${names[names.length - 1]}`;
}

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
    { id: 'chatlog', label: 'Chat history', enabled: true },
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
    case 'reply': return `replied to ${who}'s report “${d.subject || ''}”${{ mailed: ' (emailed)', note: ' (a note, not emailed)', unread: ' (not emailed: an earlier reply is still unread)', busy: ' (not emailed: hourly email limit)', failed: ' (the email did not send)' }[line.delivery] ?? ''}`;
    case 'close': return `closed ${who}'s report “${d.subject || ''}”`;
    case 'reopen': return `reopened ${who}'s report “${d.subject || ''}”`;
    case 'working': return d.on === false ? `stopped marking ${who}'s report “${d.subject || ''}” working on it` : `marked ${who}'s report “${d.subject || ''}” working on it`;
    case 'broadcast': return `messaged everyone online (${d.count ?? 0}): “${d.text || ''}”${d.failed ? ` (${d.failed} did not get it)` : ''}`;
    case 'builder': {
      const title = d.title || d.draft || 'a draft';
      const by = d.key ? ` with key ${d.key}` : '';
      if (d.text === 'key made') return `made the builder key “${d.label || d.key}”`;
      if (d.text === 'key revoked') return `revoked the builder key “${d.label || d.key}”`;
      if (d.text === 'key deleted') return `deleted the builder key “${d.label || d.key}”`;
      if (d.text === 'archived') return `archived the draft “${title}”`;
      if (d.text === 'purged') return `deleted the archived draft “${title}” for good`;
      if (d.text === 'created') return `started the draft “${title}”`;
      if (String(d.text || '').startsWith('ready')) return `marked the draft “${title}” ready to publish${by}`;
      if (String(d.text || '').startsWith('new art ')) return `took in ${d.text.slice(8)} for “${title}”${by}`;
      const revs = d.from && d.from !== d.rev ? `revs ${d.from} to ${d.rev}` : `rev ${d.rev ?? '?'}`;
      return `edited the draft “${title}” (${revs}, ${d.ops ?? 0} ${d.ops === 1 ? 'change' : 'changes'})${by}`;
    }
    case 'rook_end': {
      const street = d.street?.label || d.street?.tsid || 'a street';
      if (line.delivery === 'none') return `tried to end a Rook attack on ${street}, but there was none`;
      if (line.delivery === 'ending') return `tried to end the Rook attack on ${street}, but it was already leaving`;
      if (line.delivery === 'down') return `tried to end the Rook attack on ${street}${how}`;
      return `ended the Rook attack on ${street}${typeof d.players === 'number' ? ` (${d.players === 1 ? '1 player' : `${d.players} players`} there)` : ''}`;
    }
    case 'board_remove': {
      const street = d.street?.label || d.street?.tsid || 'a board';
      const title = d.title ? `“${d.title}”` : 'a note';
      return `removed ${title}${d.by ? ` by ${d.by}` : ''} from the board in ${street}${line.delivery === 'down' ? ' (realtime server down: players there see it until it is taken or the server restarts)' : ''}`;
    }
    case 'comm_remove': return `removed ${who}'s ${d.first ? 'thread' : 'post in'} “${d.title || ''}” on the Community boards`;
    case 'comm_restore': return `restored ${who}'s ${d.first ? 'thread' : 'post in'} “${d.title || ''}” on the Community boards`;
    case 'comm_pin': return `pinned “${d.title || ''}” on the Community boards`;
    case 'comm_unpin': return `unpinned “${d.title || ''}” on the Community boards`;
    case 'comm_lock': return `locked “${d.title || ''}” on the Community boards`;
    case 'comm_unlock': return `unlocked “${d.title || ''}” on the Community boards`;
    case 'comm_stop': return `stopped ${who} posting on the Community boards ${d.days ? `for ${d.days === 1 ? '1 day' : `${d.days} days`}` : 'until lifted'}${d.reason ? `: ${d.reason}` : ''}`;
    case 'comm_unstop': return `let ${who} post on the Community boards again`;
    case 'credit': return `scored ${who}'s report “${d.subject || ''}”: ${creditWords({ points: d.points, kind: d.kind, by: d.by })}`;
    case 'migrate': return `brought in ${d.made ?? 0} old bug ${d.made === 1 ? 'report' : 'reports'} as tickets`;
    default: return `${line.kind} ${who}${how}`;
  }
}

/** The chat log's kinds in words (the server's local, channel, party). */
export const CHAT_KIND = Object.freeze({ local: 'Street', channel: 'Channel', party: 'Party' });

/** Milliseconds at the start of a local day "YYYY-MM-DD", plus days; null for anything else. */
export function localDayStart(day, plus = 0) {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(day || ''));
  if (!m) return null;
  const t = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]) + plus).getTime();
  return Number.isFinite(t) ? t : null;
}

/**
 * The chat log search as admin/chatlog's query: player (a name, or #id), q, kind, refused, the cursor,
 * and From and To as the viewer's local days (since: the start of From; until: the start of the day after To).
 */
export function chatlogParams({ player = '', q = '', kind = '', from = '', to = '', refused = false, before = '' } = {}) {
  const p = new URLSearchParams();
  const trimmed = (s) => String(s || '').trim();
  if (trimmed(player)) p.set('player', trimmed(player));
  if (trimmed(q)) p.set('q', trimmed(q));
  if (CHAT_KIND[kind]) p.set('kind', kind);
  const since = localDayStart(from);
  const until = localDayStart(to, 1);
  if (since !== null) p.set('since', String(since));
  if (until !== null) p.set('until', String(until));
  if (refused) p.set('refused', '1');
  if (before) p.set('before', before);
  return p.toString();
}

/** Why a line did not reach anyone, in words ('' when it did). */
export function chatWhy(line) {
  if (line?.delivered) return '';
  const why = { slow_down: 'Refused: too fast', silenced: 'Refused: silenced in this channel' }[line?.why] || 'Refused';
  const n = Number(line?.skipped) || 0;
  return n > 0 ? `${why} (${n} more refused before it, not written)` : why;
}

/** The word filter's chip on a line it changed (the remake's own addition): its title is what players saw; null when it did not. */
export function chatFiltered(line) {
  if (!line?.filtered) return null;
  return { text: 'Filtered', title: `Players saw: ${line.shown || ''}` };
}

/** A chat line's time in the viewer's time zone: "Oct 31, 2:03:05 PM" in their own format. */
export function chatWhen(iso) {
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? '' : d.toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' });
}

/** The line under the chat log's search: how many, refusals, lines the server could not write, how long it keeps them. */
export function chatStatus({ server = 'up', shown = 0, more = false, dropped = 0, days = 0 } = {}) {
  if (server === 'off') return 'The realtime server keeps no chat log right now.';
  if (server === 'down') return COPY.serverDown;
  const parts = [shown === 0 ? 'No lines.' : `${shown} ${shown === 1 ? 'line' : 'lines'}, newest first${more ? ', more with Older' : ''}.`];
  if (days > 0) parts.push(`Kept ${days} days.`);
  if (dropped > 0) parts.push(`${dropped} ${dropped === 1 ? 'line was' : 'lines were'} too many to write at once and are not in the log.`);
  return parts.join(' ');
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
  const state = { online: [], onlineIds: new Set(), found: [], bans: [], rooks: [], streets: null, items: null, me: 0, logBefore: 0 };
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
  // == Rook attacks ==
  function renderRooks() {
    const table = $('[data-console-rooks]');
    table.hidden = state.rooks.length === 0;
    table.querySelector('tbody').replaceChildren(...state.rooks.map((a) => el('tr', { 'data-rook-street': a.street.tsid },
      el('td', { class: 'console-who' }, el('b', { text: a.street.label }), el('span', { class: 'console-sub', text: ` ${a.street.tsid}` })),
      el('td', { text: rookWords(a) }),
      el('td', { text: rookPlayers(a) }),
      el('td', { class: 'console-act' }, rookEndable(a)
        ? el('button', { type: 'button', class: 'console-small console-danger', text: 'End attack', 'aria-label': `End the Rook attack on ${a.street.label}`, onclick: () => endRook(a) })
        : el('span', { class: 'console-sub', text: 'Leaving' })))));
    $('[data-console-rooks-empty]').hidden = state.rooks.length > 0;
    $('[data-console-rooks-count]').textContent = state.rooks.length ? String(state.rooks.length) : '';
  }
  async function pollRooks() {
    try {
      const r = await api.call('admin/rooks');
      state.rooks = r.server === 'up' ? r.attacks || [] : [];
      $('[data-console-rooks-empty]').textContent = r.server === 'up' ? 'No Rook is over a street right now.' : COPY.serverDown;
    } catch {
      state.rooks = [];
    }
    renderRooks();
  }
  function endRook(a) {
    // Outward-facing: a confirm step, so nothing goes by accident.
    dialog(COPY.rookEndConfirm(a.street.label), [el('p', { class: 'console-quote', text: `${rookWords(a)}. There: ${rookPlayers(a)}.` }), el('p', { class: 'console-sub', text: COPY.rookEndNote })], 'End attack', async () => {
      const r = await api.call('admin/rook_end', { method: 'POST', body: { street: a.street.tsid } });
      say(r.text || 'Done.', r.delivery === 'live' ? 'ok' : 'bad');
      pollRooks();
      loadLog();
    }, { danger: true });
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
      renderBroadcast();
      pollRooks();
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

  // == Message everyone ==
  const cast = $('[data-console-broadcast]');
  const castCount = $('[data-console-broadcast-count]');
  const castSend = $('[data-console-broadcast-send]');
  const castLen = $('[data-console-broadcast-len]');
  function renderBroadcast() {
    const n = state.online.length;
    castCount.textContent = COPY.broadcastCount(n);
    castSend.disabled = n === 0;
  }
  cast.text.addEventListener('input', () => { castLen.textContent = `${cast.text.value.length} / ${TEXT_MAX}`; });
  cast.addEventListener('submit', (e) => {
    e.preventDefault();
    const text = cast.text.value.trim();
    if (!text) { cast.text.focus(); return; }
    const n = state.online.length;
    if (n === 0) return;
    // Outward-facing: a confirm step, so nothing goes by accident.
    dialog(COPY.broadcastConfirm(n), [el('p', { class: 'console-quote', text }), el('p', { class: 'console-sub', text: 'Each player online sees it in their game as "Message from an administrator". Nobody offline gets it.' })], 'Send', async () => {
      const r = await api.call('admin/broadcast', { method: 'POST', body: { text } });
      cast.text.value = '';
      castLen.textContent = '';
      say(r.text || 'Sent.', r.failed ? 'bad' : 'ok');
      loadLog();
    });
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
    } else if (kind === 'chatlog') {
      openChat(p);
    }
    void opener;
  }

  // == Chat log ==
  const chat = { query: null, next: '', shown: 0, loaded: false };
  const chatForm = $('[data-console-chat-form]');
  function chatRow(l) {
    const who = { id: l.player.id, name: l.player.name };
    const name = el('button', { type: 'button', class: 'console-link', 'aria-haspopup': 'menu', title: l.spokeAs && l.spokeAs !== l.player.name ? `Said as ${l.spokeAs}` : null, text: l.player.name, onclick: (e) => openMenu(who, e.currentTarget, null) });
    const said = el('td', { class: 'console-said' }, el('span', { text: l.text }));
    const filtered = chatFiltered(l);
    if (filtered) said.append(el('span', { class: 'console-tag console-tag-filtered', ...filtered }));
    const why = chatWhy(l);
    if (why) said.append(el('span', { class: 'console-tag console-tag-bad', text: why }));
    if (l.to?.length) said.append(el('span', { class: 'console-sub', text: ` To ${l.to.map((t) => t.name).join(', ')}` }));
    return el('tr', { 'data-delivered': l.delivered ? null : 'false' },
      el('td', { class: 'console-num' }, el('time', { datetime: l.at, text: chatWhen(l.at) })),
      el('td', {}, el('span', { class: `console-kind console-kind-chat-${l.kind}`, text: CHAT_KIND[l.kind] || l.kind }), el('span', { text: ` ${l.where?.label || ''}` })),
      el('td', { class: 'console-who' }, name, el('span', { class: 'console-sub', text: ` #${l.player.id}` })),
      said);
  }
  async function loadChat(older = false) {
    if (!older) chat.query = chatlogParams({ player: chatForm.player.value, q: chatForm.q.value, kind: chatForm.kind.value, from: chatForm.from.value, to: chatForm.to.value, refused: chatForm.refused.checked });
    const q = older && chat.next ? `${chat.query}${chat.query ? '&' : ''}before=${encodeURIComponent(chat.next)}` : chat.query;
    let r;
    try { r = await api.call(`admin/chatlog${q ? `?${q}` : ''}`); } catch (err) { return failed(err); }
    chat.loaded = true;
    const body = $('[data-console-chat] tbody');
    const rows = (r.lines || []).map(chatRow);
    if (older) body.append(...rows);
    else body.replaceChildren(...rows);
    chat.shown = body.children.length;
    chat.next = r.next || '';
    $('[data-console-chat]').hidden = chat.shown === 0;
    $('[data-console-chat-empty]').hidden = chat.shown > 0 || r.server !== 'up';
    $('[data-console-chat-more]').hidden = !chat.next;
    $('[data-console-chat-status]').textContent = chatStatus({ server: r.server, shown: chat.shown, more: Boolean(chat.next), dropped: r.dropped, days: r.days });
  }
  function openChat(player) {
    chatForm.reset();
    chatForm.player.value = `#${player.id}`;
    chat.loaded = true; // showTab would load the unfiltered log first
    showTab('chat');
    history.replaceState(null, '', `#chat=${player.id}`);
    loadChat();
    chatForm.player.focus();
  }
  chatForm.addEventListener('submit', (e) => { e.preventDefault(); loadChat(); });
  $('[data-console-chat-clear]').addEventListener('click', () => { chatForm.reset(); loadChat(); });
  $('[data-console-chat-more]').addEventListener('click', () => loadChat(true));

  // == Boards ==
  const boards = { show: 'pinned', list: [] };
  function renderBoards() {
    const table = $('[data-console-boards]');
    if (!table) return;
    table.hidden = boards.list.length === 0;
    table.querySelector('tbody').replaceChildren(...boards.list.map((n) => el('tr', { 'data-board-note': n.id, 'data-board-status': n.status, ...(n.reports > 0 ? { 'data-board-reported': '' } : {}) },
      el('td', { class: 'console-who' }, el('b', { text: n.street.label }), el('span', { class: 'console-sub', text: ` ${new Date(n.at).toLocaleString()}` })),
      el('td', { class: 'console-board-note' }, el('b', { text: n.title || '(no title)' }), el('p', { class: 'console-board-body', text: n.body || '(blank)' })),
      el('td', { text: boardWho(n) }),
      el('td', {}, el('span', { text: BOARD_STATUS[n.status] || n.status }), boardReports(n) ? el('span', { class: 'console-tag console-tag-bad', text: boardReports(n) }) : null),
      el('td', { class: 'console-act' }, boardRemovable(n)
        ? el('button', { type: 'button', class: 'console-small console-danger', text: 'Remove', 'aria-label': `Remove the note “${n.title || 'untitled'}” from the board in ${n.street.label}`, onclick: () => removeBoardNote(n) })
        : null))));
    const empty = $('[data-console-boards-empty]');
    empty.hidden = boards.list.length > 0;
    empty.textContent = COPY.boardNone[boards.show] || COPY.boardNone.pinned;
  }
  async function loadBoards() {
    let r;
    try { r = await api.call(`admin/board_notes?show=${boards.show}`); } catch (err) { return failed(err); }
    boards.list = r.notes || [];
    $('[data-console-boards-count]').textContent = r.counts?.pinned ? String(r.counts.pinned) : '';
    const rep = r.counts?.reported || 0;
    $('[data-console-boards-reported]').textContent = rep ? String(rep) : '';
    $('[data-console-tab="boards"]').setAttribute('aria-label', rep ? `Boards, ${rep} reported` : 'Boards');
    renderBoards();
  }
  function removeBoardNote(n) {
    // Outward-facing: a confirm step, so nothing goes by accident.
    dialog(COPY.boardRemoveConfirm(n.street.label), [el('p', { class: 'console-quote', text: `${n.title || '(no title)'}\n\n${n.body || '(blank)'}` }), el('p', { class: 'console-sub', text: `${boardWho(n)}. ${COPY.boardRemoveNote}` })], 'Remove', async () => {
      const r = await api.call('admin/board_remove', { method: 'POST', body: { id: n.id } });
      say(r.text || 'Removed.', r.delivery === 'down' ? 'bad' : 'ok');
      loadBoards();
      loadLog();
    }, { danger: true });
  }
  $('[data-console-board-filter]')?.addEventListener('click', (e) => {
    const b = e.target.closest('button[data-show]');
    if (!b) return;
    boards.show = b.dataset.show;
    for (const o of e.currentTarget.querySelectorAll('button[data-show]')) o.setAttribute('aria-pressed', String(o === b));
    loadBoards();
  });

  // == Community ==
  const community = { show: 'reported', list: [] };
  function renderCommunity() {
    const table = $('[data-console-community]');
    if (!table) return;
    table.hidden = community.list.length === 0;
    table.querySelector('tbody').replaceChildren(...community.list.map((p) => el('tr', { 'data-community-post': p.id, 'data-community-status': p.status, ...(p.reports > 0 && p.status === 'shown' ? { 'data-board-reported': '' } : {}) },
      el('td', { class: 'console-who' }, el('a', { href: `${p.thread.url}#post-${p.id}`, target: '_blank', rel: 'noopener', text: p.thread.title }),
        el('span', { class: 'console-sub', text: ` ${p.thread.board}, ${new Date(p.at * 1000).toLocaleString()}` }),
        p.thread.pinned ? el('span', { class: 'console-tag', text: 'Pinned' }) : null, p.thread.locked ? el('span', { class: 'console-tag', text: 'Locked' }) : null),
      el('td', {}, p.first ? el('b', { text: 'First post' }) : null, el('p', { class: 'console-board-body', text: p.body || '(blank)' })),
      el('td', { text: p.author.name }),
      el('td', {}, el('span', { text: COMMUNITY_STATUS[p.status] || p.status }), p.reports ? el('span', { class: 'console-tag console-tag-bad', text: p.reports === 1 ? 'Reported once' : `Reported by ${p.reports}` }) : null),
      el('td', { class: 'console-act' },
        p.status === 'shown' ? el('button', { type: 'button', class: 'console-small console-danger', text: 'Remove', 'aria-label': `Remove ${p.author.name}'s post in “${p.thread.title}”`, onclick: () => moderatePost(p, true) }) : null,
        p.status === 'removed' ? el('button', { type: 'button', class: 'console-small', text: 'Restore', 'aria-label': `Restore ${p.author.name}'s post in “${p.thread.title}”`, onclick: () => moderatePost(p, false) }) : null,
        el('button', { type: 'button', class: 'console-small', text: p.thread.pinned ? 'Unpin' : 'Pin', 'aria-label': `${p.thread.pinned ? 'Unpin' : 'Pin'} “${p.thread.title}”`, onclick: () => threadSet(p.thread, { pinned: !p.thread.pinned }) }),
        el('button', { type: 'button', class: 'console-small', text: p.thread.locked ? 'Unlock' : 'Lock', 'aria-label': `${p.thread.locked ? 'Unlock' : 'Lock'} “${p.thread.title}”`, onclick: () => threadSet(p.thread, { locked: !p.thread.locked }) }),
        el('button', { type: 'button', class: 'console-small', text: 'Stop posting', 'aria-label': `Stop ${p.author.name} posting on the boards`, onclick: () => stopPosting(p.author) })))));
    const empty = $('[data-console-community-empty]');
    empty.hidden = community.list.length > 0;
    empty.textContent = COPY.communityNone[community.show] || COPY.communityNone.reported;
  }
  function renderStops(stops) {
    $('[data-console-community-stops]').replaceChildren(...stops.map((b) => el('li', { class: 'console-ban' },
      el('div', {}, el('b', { text: b.player.name }), el('span', { class: 'console-sub', text: ` ${stopUntil(b.until)}, by ${b.by.name || 'an administrator'}` }),
        b.reason ? el('p', { class: 'console-reason', text: b.reason }) : null),
      el('button', { type: 'button', class: 'console-small', text: 'Lift', 'aria-label': `Let ${b.player.name} post again`, onclick: async () => {
        try {
          const r = await api.call('admin/community_unstop', { method: 'POST', body: { player: b.player.id } });
          say(r.text || 'Done.');
          renderStops(r.stopped || []);
          loadLog();
        } catch (err) { failed(err); }
      } }))));
    $('[data-console-community-stops-empty]').hidden = stops.length > 0;
  }
  async function loadCommunity() {
    let r;
    try { r = await api.call(`admin/community_posts?show=${community.show}`); } catch (err) { return failed(err); }
    community.list = r.posts || [];
    const rep = r.counts?.reported || 0;
    $('[data-console-community-reported]').textContent = rep ? String(rep) : '';
    $('[data-console-tab="community"]').setAttribute('aria-label', rep ? `Community, ${rep} reported` : 'Community');
    renderCommunity();
    renderStops(r.stopped || []);
  }
  function moderatePost(p, remove) {
    const run = async () => {
      const r = await api.call(remove ? 'admin/community_remove' : 'admin/community_restore', { method: 'POST', body: { post: p.id } });
      say(r.text || 'Done.');
      loadCommunity();
      loadLog();
    };
    if (!remove) return run().catch(failed);
    dialog(COPY.communityRemoveConfirm(p.first), [el('p', { class: 'console-quote', text: `${p.thread.title}\n\n${p.body || '(blank)'}` }), el('p', { class: 'console-sub', text: `${p.author.name}. ${COPY.communityRemoveNote(p.first)}` })], 'Remove', run, { danger: true });
  }
  async function threadSet(t, change) {
    try {
      const r = await api.call('admin/community_thread', { method: 'POST', body: { thread: t.id, ...change } });
      say(r.text || 'Done.');
      loadCommunity();
      loadLog();
    } catch (err) { failed(err); }
  }
  function stopPosting(who) {
    const days = el('select', { name: 'days' }, ...COMMUNITY_STOP_DAYS.map(([d, label]) => el('option', { value: String(d), ...(d === 3 ? { selected: true } : {}), text: label })));
    const reason = el('input', { type: 'text', name: 'reason', maxlength: '200', placeholder: 'The log keeps it' });
    dialog(COPY.communityStopTitle(who.name), [el('label', { text: 'How long' }, days), el('label', { text: 'Reason' }, reason), el('p', { class: 'console-sub', text: COPY.communityStopNote })], 'Stop posting', async () => {
      const r = await api.call('admin/community_stop', { method: 'POST', body: { player: who.id, days: Number(days.value), reason: reason.value } });
      say(r.text || 'Done.');
      renderStops(r.stopped || []);
      loadLog();
    }, { danger: true });
  }
  $('[data-console-community-filter]')?.addEventListener('click', (e) => {
    const b = e.target.closest('button[data-show]');
    if (!b) return;
    community.show = b.dataset.show;
    for (const o of e.currentTarget.querySelectorAll('button[data-show]')) o.setAttribute('aria-pressed', String(o === b));
    loadCommunity();
  });

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
    if (id === 'builder') loadDrafts();
    if (id === 'credits') loadCredits();
    if (id === 'credits' && location.hash !== '#credits') history.replaceState(null, '', '#credits');
    if (id === 'community') loadCommunity();
    if (id === 'community' && location.hash !== '#community') history.replaceState(null, '', '#community');
    if (id === 'boards') loadBoards();
    if (id === 'boards' && location.hash !== '#boards') history.replaceState(null, '', '#boards');
    if (id === 'chat' && !chat.loaded) loadChat();
    if (id === 'chat' && !/^#chat(=\d+)?$/.test(location.hash)) history.replaceState(null, '', '#chat');
    if (id === 'builder' && location.hash !== '#builder') history.replaceState(null, '', '#builder');
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

  // == Builder ==
  const drafts = { list: [], idTouched: false };
  const draftForm = $('[data-console-newdraft]');
  function renderDrafts() {
    const list = $('[data-console-drafts]');
    if (!list) return;
    list.replaceChildren(...drafts.list.map((d) => el('li', { class: 'console-draft', 'data-draft': d.id },
      el('div', { class: 'console-draft-top' },
        el('a', { href: builderHref(d.id), class: 'console-draft-title' }, el('b', { text: d.title || d.id })),
        el('span', { class: 'console-sub', text: ` ${d.id}` })),
      el('span', { class: 'console-sub', text: draftWords(d, when) }),
      d.streets?.length ? el('ul', { class: 'console-draft-streets', 'aria-label': `Streets in ${d.title || d.id}` },
        ...d.streets.map((s) => el('li', {}, el('a', { href: builderHref(d.id, s.key), text: s.label || s.key })))) : null,
      el('div', { class: 'console-draft-acts' },
        el('a', { href: builderHref(d.id), class: 'console-small console-link-btn', text: 'Open' }),
        el('button', { type: 'button', class: 'console-small', onclick: () => archiveDraft(d) , text: 'Archive' })))));
    $('[data-console-drafts-empty]').hidden = drafts.list.length > 0;
    $('[data-console-drafts-count]').textContent = drafts.list.length ? String(drafts.list.length) : '';
  }
  async function loadDrafts() {
    if (!$('[data-console-drafts]')) return;
    let r;
    try { r = await api.call('admin/builder/drafts'); } catch (err) { return failed(err); }
    drafts.list = r.drafts || [];
    renderDrafts();
  }
  async function archiveDraft(d) {
    if (!globalThis.confirm?.(`Archive “${d.title || d.id}”? It leaves this list; nothing is deleted.`)) return;
    try {
      await api.call(`admin/builder/drafts/${encodeURIComponent(d.id)}`, { method: 'DELETE' });
      say(`Archived “${d.title || d.id}”.`);
      loadDrafts();
      loadLog();
    } catch (err) { failed(err); }
  }
  if (draftForm) {
    draftForm.title.addEventListener('input', () => { if (!drafts.idTouched) draftForm.id.value = draftIdFrom(draftForm.title.value); });
    draftForm.id.addEventListener('input', () => { drafts.idTouched = draftForm.id.value !== ''; });
    draftForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const title = draftForm.title.value.trim();
      const id = draftForm.id.value.trim();
      if (!draftIdOk(id)) { say('An id is 3 to 48 lowercase letters, digits and hyphens, starting with a letter.', 'bad'); draftForm.id.focus(); return; }
      try {
        await api.call('admin/builder/drafts', { method: 'POST', body: { id, title } });
        location.href = builderHref(id);
      } catch (err) { failed(err); }
    });
  }

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
    for (const k of ['open', 'working', 'answered', 'closed']) $(`[data-console-status-count="${k}"]`).textContent = c[k] ? String(c[k]) : '';
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
    $('[data-console-thread-working]').hidden = t.status === 'closed' || t.status === 'working';
    $('[data-console-thread-unworking]').hidden = t.status !== 'working';
    const rows = contextRows(r.context);
    $('[data-console-context]').hidden = rows.length === 0;
    $('[data-console-context-list]').replaceChildren(...rows.flatMap(([k, v]) => [el('dt', { text: k }), el('dd', { text: v })]));
    const src = pictureSrc(r.picture);
    const fig = $('[data-console-picture]');
    const img = $('[data-console-picture-img]');
    fig.hidden = !src;
    fig.removeAttribute('data-failed');
    if (src) {
      $('[data-console-picture-link]').href = src;
      if (img.getAttribute('src') !== src) img.src = src;
      img.width = Number(r.picture.width) || 0;
      img.height = Number(r.picture.height) || 0;
    } else img.removeAttribute('src');
    $('[data-console-messages]').replaceChildren(...(r.messages || []).map((m) => el('li', { class: `console-msg console-msg-${m.author}` },
      el('p', { class: 'console-msg-who' }, el('b', { text: m.author === 'admin' ? 'Administrator' : t.player.name }), el('time', { datetime: m.at, text: ` ${messageWhen(m, when)}`, title: m.edited ? `Edited ${when(m.edited)}` : null })),
      el('p', { class: 'console-msg-body', text: m.body }),
      messageHistory(m, when))));
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
    try { renderThread(await api.call(`admin/tickets/${id}`), focus); } catch (err) { return failed(err); }
    loadCredit(id);
  }

  // == A report's credit (src/AdminReportCredits.php) ==
  const creditForm = $('[data-console-credit-form]');
  let creditFor = 0;
  function renderCredit(r) {
    const c = r.credit;
    $('[data-console-credit-summary]').textContent = creditWords(c);
    const now = $('[data-console-credit-now]');
    now.hidden = !c;
    now.textContent = c ? `${c.reason}${c.refs?.length ? ` (${c.refs.join(', ')})` : ''}, ${when(c.at)}` : '';
    creditForm.kind.value = c?.kind || 'bug_fixed';
    creditForm.points.value = String(c ? c.points : CREDIT_KINDS[creditForm.kind.value]);
    creditForm.reason.value = c?.reason || '';
    creditForm.refs.value = (c?.refs || []).join(', ');
  }
  async function loadCredit(id) {
    creditFor = id;
    $('[data-console-credit-summary]').textContent = '';
    try {
      const r = await api.call(`admin/tickets/${id}/credit`);
      if (creditFor === id) renderCredit(r);
    } catch (err) { failed(err); }
  }
  // Picking a kind puts in its points; change them for a report with several changes.
  creditForm.kind.addEventListener('change', () => { creditForm.points.value = String(CREDIT_KINDS[creditForm.kind.value] ?? 0); });
  creditForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!creditFor) return;
    const body = creditBody({ kind: creditForm.kind.value, points: creditForm.points.value, reason: creditForm.reason.value, refs: creditForm.refs.value });
    const save = $('[data-console-credit-save]');
    save.disabled = true;
    try {
      const r = await api.call(`admin/tickets/${creditFor}/credit`, { method: 'PUT', body });
      renderCredit(r);
      say(r.text || 'Scored.');
      loadLog();
      if (tab === 'credits') loadCredits(); else loadCreditCounts();
    } catch (err) { failed(err); } finally { save.disabled = false; }
  });

  // == Credits tab ==
  const credits = { loaded: false };
  function creditsUnscored(n) {
    $('[data-console-credits-unscored]').textContent = n ? String(n) : '';
    $('[data-console-tab="credits"]').setAttribute('aria-label', n ? `Credits, ${n} not scored yet` : 'Credits');
  }
  function openReport(id) {
    history.replaceState(null, '', `#report=${id}`);
    showTab('reports');
    openTicket(id);
  }
  async function loadCreditCounts() {
    try { creditsUnscored((await api.call('admin/credits')).unscored?.length || 0); } catch { /* the tab says what went wrong */ }
  }
  async function loadCredits() {
    let r;
    try { r = await api.call('admin/credits'); } catch (err) { return failed(err); }
    credits.loaded = true;
    const list = r.credits || [];
    const table = $('[data-console-credits]');
    table.hidden = list.length === 0;
    $('[data-console-credits-empty]').hidden = list.length > 0;
    $('[data-console-credits-count]').textContent = list.length ? String(list.length) : '';
    table.tBodies[0].replaceChildren(...list.map((c) => {
      const tr = el('tr', { tabindex: '0', 'data-ticket': c.ticket, onclick: () => openReport(c.ticket), onkeydown: (e) => { if (e.key === 'Enter') openReport(c.ticket); } },
        el('td', {}, el('b', { text: `#${c.ticket} ` }), document.createTextNode(c.subject || '(no words)')),
        el('td', { text: c.player?.name || '' }),
        el('td', { class: 'console-num', text: String(c.points) }),
        el('td', {}, el('span', { class: 'console-chip', text: CREDIT_WORDS[c.kind] || c.kind }), document.createTextNode(` ${c.reason}`), c.by === 'agent' ? el('span', { class: 'console-sub', text: ' (agent)' }) : null));
      return tr;
    }));
    const board = r.board || [];
    $('[data-console-board]').replaceChildren(...board.map((b, i) => el('li', { class: 'console-boardrow' },
      el('span', { class: 'console-num console-sub', text: `${i + 1}.` }),
      el('b', { text: b.player?.name || '' }),
      el('span', { class: 'console-sub', text: `${b.points} ${b.points === 1 ? 'point' : 'points'}, ${b.reports} ${b.reports === 1 ? 'report' : 'reports'}` }))));
    $('[data-console-board-empty]').hidden = board.length > 0;
    const unscored = r.unscored || [];
    $('[data-console-unscored]').replaceChildren(...unscored.map((t) => el('li', {},
      el('button', { type: 'button', class: 'console-ticket', onclick: () => openReport(t.id) },
        el('span', { class: 'console-ticket-top' }, el('b', { text: `#${t.id} ${t.subject || '(no words)'}` }), el('span', { class: `console-chip console-chip-${t.status}`, text: TICKET_STATUS[t.status] || t.status })),
        el('span', { class: 'console-sub', text: `${t.player?.name || ''} · ${when(t.createdAt)}` })))));
    $('[data-console-unscored-empty]').hidden = unscored.length > 0;
    $('[data-console-unscored-count]').textContent = unscored.length ? String(unscored.length) : '';
    creditsUnscored(unscored.length);
  }
  const reply = $('[data-console-reply]');
  const replyCount = $('[data-console-reply-count]');
  reply.message.addEventListener('input', () => { replyCount.textContent = `${reply.message.value.length} / ${TICKET_MAX}`; });
  const replySend = $('[data-console-reply-send]');
  const noEmail = $('[data-console-reply-noemail]');
  const replyWords = () => {
    const w = replyFormWords(noEmail.checked);
    replySend.textContent = w.send;
    reply.message.placeholder = w.placeholder;
  };
  noEmail.addEventListener('change', replyWords);
  replyWords();
  reply.addEventListener('submit', async (e) => {
    e.preventDefault();
    const text = reply.message.value.trim();
    if (!text || !tickets.open) return;
    const send = replySend;
    send.disabled = true;
    try {
      const r = await api.call(`admin/tickets/${tickets.open}/reply`, { method: 'POST', body: { message: text, email: !noEmail.checked } });
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
  // Working on it, and off again (ticket 49): a status, no email.
  for (const [sel, on] of [['[data-console-thread-working]', true], ['[data-console-thread-unworking]', false]]) {
    $(sel).addEventListener('click', async () => {
      if (!tickets.open) return;
      try {
        const r = await api.call(`admin/tickets/${tickets.open}/working`, { method: 'POST', body: { working: on } });
        renderThread(r);
        say(r.text || 'Done.');
        loadTickets();
        loadLog();
        $(on ? '[data-console-thread-unworking]' : '[data-console-thread-working]').focus();
      } catch (err) { failed(err); }
    });
  }
  function fromHash() {
    const m = /^#report=(\d+)$/.exec(location.hash);
    if (m) { showTab('reports'); openTicket(Number(m[1]), false); } else if (location.hash === '#reports') showTab('reports');
    else if (location.hash === '#builder' && $('[data-console-tab="builder"]')) showTab('builder');
    else if (/^#chat=\d+$/.test(location.hash)) openChat({ id: Number(location.hash.slice(6)) });
    else if (location.hash === '#chat') showTab('chat');
    else if (location.hash === '#credits') showTab('credits');
    else if (location.hash === '#boards') showTab('boards');
    else if (location.hash === '#community') showTab('community');
  }
  addEventListener('hashchange', fromHash);

  // == The picture, full size, in a popup over the console ==
  // A plain click opens it here; Cmd, Ctrl, Shift or a middle click still open the link in a tab.
  {
    const link = $('[data-console-picture-link]');
    const img = $('[data-console-picture-img]');
    img.addEventListener('error', () => { if (img.getAttribute('src')) $('[data-console-picture]').setAttribute('data-failed', ''); });
    img.addEventListener('load', () => $('[data-console-picture]').removeAttribute('data-failed'));
    link.addEventListener('click', (e) => {
      if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
      const src = pictureSrc({ url: link.getAttribute('href') });
      if (!src) return;
      e.preventDefault();
      openPicture(src, img.width, img.height);
    });
  }
  function openPicture(src, width, height) {
    const big = el('img', { src, alt: 'The game when the player reported it', width: width || null, height: height || null });
    const close = el('button', { type: 'button', class: 'console-lightbox-close', 'aria-label': 'Close', text: '\u00d7' });
    const tab = el('a', { href: src, target: '_blank', rel: 'noopener', text: 'Open in a new tab' });
    const dlg = el('dialog', { class: 'console-lightbox', 'aria-label': 'The picture of the game' },
      el('figure', {}, big, el('figcaption', {}, tab)), close);
    document.body.append(dlg);
    const opener = document.activeElement;
    let gone = false;
    const done = () => { if (gone) return; gone = true; if (dlg.open) dlg.close(); dlg.remove(); if (opener?.isConnected) opener.focus(); };
    close.addEventListener('click', done);
    dlg.addEventListener('close', done);
    // A click on the picture or the dark around it closes it; the tab link still works.
    dlg.addEventListener('click', (e) => { if (e.target !== tab) done(); });
    dlg.showModal();
    close.focus();
  }

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
    if (tab !== 'credits') loadCreditCounts();
    let timer = setInterval(() => pollOnline(), POLL_MS);
    let logTimer = setInterval(() => loadLog(), LOG_POLL_MS);
    let ticketTimer = setInterval(() => loadTickets(), TICKETS_POLL_MS);
    const draftsTick = () => { if (tab === 'builder') loadDrafts(); };
    let draftTimer = setInterval(draftsTick, DRAFTS_POLL_MS);
    document.addEventListener('visibilitychange', () => {
      clearInterval(timer);
      clearInterval(logTimer);
      clearInterval(ticketTimer);
      clearInterval(draftTimer);
      if (document.hidden) return;
      pollOnline();
      loadTickets();
      draftsTick();
      timer = setInterval(() => pollOnline(), POLL_MS);
      logTimer = setInterval(() => loadLog(), LOG_POLL_MS);
      ticketTimer = setInterval(() => loadTickets(), TICKETS_POLL_MS);
      draftTimer = setInterval(draftsTick, DRAFTS_POLL_MS);
    });
  })();
}

if (typeof document !== 'undefined') {
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
}
