/**
 * Right-click menus throughout the /chat/ app (Austin 2026-11-04), the game's own component
 * (groddle-afternoon public/js/ui/context-menu.js, served at /js/ui/: the same rows, key caps,
 * footer line and keys; its card is drawn in community.css as the game's Modern one):
 *
 * - a player's name or avatar (in lines, Who's around, Buddies, a Chats row's avatar, board posts,
 *   the thread panel): View profile, Send IM, Add buddy / Remove buddy, Block / Unblock, Report;
 *   your own name has none (as the game's player menu);
 * - a line: Reply in thread, React, Copy text, Copy link to this line (/chat/?c=global#l123 opens
 *   the chat at it), Report (a ticket for the team, as Report on a post); for an administrator, on a
 *   channel's line or thread reply, Delete line, then a second menu to confirm it (Delete for
 *   everyone, Cancel: the page has no window.confirm), through api.deleteLine;
 * - a board post: its own actions (Quote, React, Edit, Delete, Report) and Copy text, Copy link;
 * - a channel, a chat or a board in the rail: Open, Mark as read, and for channels and chats Mute
 *   (no count for new lines, kept on this browser: chat-pure.js MUTED_KEY).
 *
 * Opened by the right button, Ctrl+click on a Mac, Shift+F10 or the Menu key on a focused element,
 * and a long press on touch (bindContextTrigger). The browser's own menu stays on text fields, on
 * selected text (so Copy works) and on links inside what was said.
 *
 * QA: main[data-cx-menu] names what is open ("player:7", "line:chan:global#12", "rail:chan:help").
 */
import { bindContextTrigger, createContextMenu } from '/js/ui/context-menu.js';
const { DELETE_CONFIRM, deletableKey, deleteConfirmRows, lineLink, lineRows, playerRows, postRows, railRows } = await import(`./chat-pure.js${new URL(import.meta.url).search}`);

const WORDS = {
  copied: 'Copied.',
  linkCopied: 'Link copied.',
  noCopy: 'Your browser would not copy that. Select the words and copy them instead.',
  added: (n) => `${n} is on your buddy list.`,
  removed: (n) => `${n} is off your buddy list.`,
  blocked: (n) => `You blocked ${n}.`,
  unblocked: (n) => `You unblocked ${n}.`,
  reportTitle: (n) => `Report ${n}`,
  reportLine: 'Report this line',
  why: 'What happened? (optional)',
  send: 'Send report',
  cancel: 'Cancel',
  reported: 'Thanks. The team will take a look.',
  deleted: 'Line deleted for everyone.',
  failed: 'That didn\'t work. Try again in a moment.',
  looking: 'Looking for their page…',
};

/** Whether text the reader selected covers target. */
function selectedOver(target) {
  const sel = globalThis.getSelection?.();
  if (!sel || sel.isCollapsed || !sel.rangeCount) return false;
  for (let i = 0; i < sel.rangeCount; i += 1) if (sel.getRangeAt(i).intersectsNode(target)) return true;
  return false;
}

/**
 * The selection as it was when the right button went down: Safari selects the word under the
 * pointer on a right-click before its contextmenu, which is not text the reader chose to copy.
 */
const press = { at: 0, had: false, target: null };
export const PRESS_MS = 1500;

/** Where the browser keeps its own menu: text fields, selected text, links in what was said. */
function browserMenu(target) {
  if (!target?.closest) return true;
  if (target.closest('input, textarea, select, [contenteditable=""], [contenteditable="true"]')) return true;
  if (target.closest('.cchan-text a, .cpost-body a, .cquote p a')) return true;
  if (press.target && Date.now() - press.at < PRESS_MS && press.target.contains?.(target)) return press.had;
  return selectedOver(target);
}

const PLAYER = '.cchan-name a, .cchan-who, [data-cx-person], [data-cx-buddy], .cx-rail [data-cx-open^="im:"] > .cx-av, .cpost-name, .cpost-face';

/** What a press is on: a player, a line, a post, a rail row, or nothing of ours. */
export function surfaceOf(target) {
  if (browserMenu(target)) return null;
  return target.closest(PLAYER) || target.closest('.cchan-line[data-line], .cpost[data-post], .cx-rail .cx-row') || null;
}

/**
 * api (chat-app.js): main, call, me, flash(text, bad), person(id), lookUp(id), isBuddy(id),
 * isBlocked(id), canIm, openIm(id), lineOf(li), openThread(conv, id), react(li), reloadBuddies(),
 * railUnread(key), markRailRead(key), isMuted(key), setMuted(key, on), notif, admin (the reader is an
 * administrator), deleteLine(conv, id, parent) -> { ok, message }.
 */
export function startMenus(api) {
  const { main, call } = api;
  const menu = createContextMenu({ doc: document, host: main, id: 'cx-ctx', className: 'cx-ctx', fixed: true, setData: (k, v) => { if (k === 'contextMenu') main.dataset.cxMenu = v; } });

  /** The player a surface names: { id, name }. */
  function playerOf(node) {
    if (node.matches('[data-cx-person]')) return { id: Number(node.dataset.cxPerson), name: node.dataset.name || '' };
    if (node.matches('[data-cx-buddy]')) return { id: Number(node.dataset.cxBuddy), name: node.dataset.name || '' };
    const row = node.closest('[data-cx-open^="im:"]');
    if (row) return { id: Number(row.dataset.cxOpen.slice(3)), name: row.querySelector('.cx-label')?.textContent || '' };
    const post = node.closest('.cpost');
    if (post) return { id: Number(post.dataset.author) || 0, name: post.dataset.authorName || '' };
    const line = node.closest('.cchan-line');
    if (line) return { id: Number(line.dataset.from) || 0, name: line.dataset.name || '' };
    return { id: 0, name: '' };
  }

  function openPlayer(node, at, keys) {
    const { id, name } = playerOf(node);
    const rows = () => playerRows({ me: api.me, id, profile: api.person(id)?.profile || null, buddy: api.isBuddy(id), blocked: api.isBlocked(id), canIm: api.canIm });
    const list = rows();
    if (!list.length) return;
    const known = api.person(id)?.checked;
    if (!known) list[0] = { ...list[0], disabled: true, reason: WORDS.looking };
    menu.open({
      title: name,
      rows: list,
      at,
      anchor: node,
      keys,
      opener: node.closest('a, button') || node,
      owner: `player:${id}`,
      onPick: (row) => playerPick(row, id, name, node.closest('.cchan-line')),
    });
    if (!known) api.lookUp(id).then(() => { if (menu.isOpenFor(`player:${id}`)) menu.update(rows()); });
  }

  async function playerPick(row, id, name, line) {
    const said = (text, bad = false) => api.flash(text, bad);
    if (row === 'profile') {
      const url = api.person(id)?.profile;
      if (url) location.href = url;
      return;
    }
    if (row === 'im') return void api.openIm(id);
    if (row === 'buddy' || row === 'unbuddy') {
      const r = row === 'buddy'
        ? await call('buddies?keepNew=1', { method: 'POST', body: { id } })
        : await call(`buddies/${id}?keepNew=1`, { method: 'DELETE' });
      if (!r.ok) return void said(r.data?.message || WORDS.failed, true);
      await api.reloadBuddies();
      return void said(row === 'buddy' ? WORDS.added(name) : WORDS.removed(name));
    }
    if (row === 'block' || row === 'unblock') {
      const r = row === 'block'
        ? await call('buddies/ignores', { method: 'POST', body: { id } })
        : await call(`buddies/ignores/${id}`, { method: 'DELETE' });
      if (!r.ok) return void said(r.data?.message || WORDS.failed, true);
      await api.reloadBuddies();
      return void said(row === 'block' ? WORDS.blocked(name) : WORDS.unblocked(name));
    }
    if (row === 'report') report({ title: WORDS.reportTitle(name), about: `${name} (player ${id})`, line: line ? lineFacts(line) : null });
  }

  /** A line's conversation, id, words and writer. */
  function lineFacts(li) {
    const of = api.lineOf(li);
    const id = Number(li.dataset.line);
    // A reply in a thread has no place of its own in the conversation: its link opens the thread.
    const link = of.parent ? lineLink(location.origin, of.conv, of.parent).replace(/#l(\d+)$/, '#t$1') : lineLink(location.origin, of.conv, id);
    return { conv: of.conv, id, link, from: Number(li.dataset.from) || 0, name: li.dataset.name || '', text: li.querySelector('.cchan-text')?.textContent || '', label: of.label, inThread: of.inThread };
  }

  function openLine(li, at, keys) {
    const f = lineFacts(li);
    const del = Boolean(api.admin) && deletableKey(f.conv) !== '';
    menu.open({
      title: f.from === api.me ? 'Your line' : `${f.name}'s line`,
      rows: lineRows({ thread: !f.inThread && Boolean(li.querySelector('[data-cx-thread-open]')), react: Boolean(li.querySelector('[data-react-open]')), mine: f.from === api.me, del }),
      at,
      anchor: li,
      keys,
      opener: li.querySelector('[data-react-open]') || li,
      owner: `line:${f.conv}#${f.id}`,
      onPick: (row) => {
        if (row === 'thread') api.openThread(f.conv, f.id);
        else if (row === 'react') api.react(li);
        else if (row === 'copy') copy(f.text, WORDS.copied);
        else if (row === 'link') copy(f.link, WORDS.linkCopied);
        else if (row === 'report') report({ title: WORDS.reportLine, about: `${f.name} (player ${f.from})`, line: f });
        else if (row === 'delete' && del) confirmDelete(li, f, at, keys);
      },
    });
  }

  /** Delete line's confirm step: a second menu in the same place, Cancel focused. */
  function confirmDelete(li, f, at, keys) {
    const parent = api.lineOf(li).parent || 0;
    menu.open({
      title: DELETE_CONFIRM.title,
      rows: deleteConfirmRows(),
      at,
      anchor: li,
      keys,
      opener: li.querySelector('[data-react-open]') || li,
      owner: `delete:${f.conv}#${f.id}`,
      focusIndex: DELETE_CONFIRM.focus,
      onPick: async (row) => {
        if (row !== 'confirm') return;
        const r = await api.deleteLine(f.conv, f.id, parent);
        api.flash(r.ok ? WORDS.deleted : r.message || WORDS.failed, !r.ok);
      },
    });
  }

  function openPost(li, at, keys) {
    const has = (sel) => li.querySelector(sel);
    menu.open({
      title: li.dataset.authorName ? `${li.dataset.authorName}'s post` : 'A post',
      rows: postRows({ quote: Boolean(has('[data-quote]')), react: Boolean(has('[data-react-open]')), edit: Boolean(has('[data-edit]')), del: Boolean(has('[data-delete]')), report: Boolean(has('[data-report]')) }),
      at,
      anchor: li,
      keys,
      opener: has('.cpost-time') || li,
      owner: `post:${li.dataset.post}`,
      onPick: (row) => {
        const press = { quote: '[data-quote]', react: '[data-react-open]', edit: '[data-edit]', delete: '[data-delete]', report: '[data-report]' }[row];
        if (press) return void has(press)?.click();
        if (row === 'copy') copy([...li.querySelectorAll('[data-post-body] p')].map((p) => p.innerText).join('\n\n'), WORDS.copied);
        if (row === 'link') copy(`${location.origin}${location.pathname}#post-${li.dataset.post}`, WORDS.linkCopied);
      },
    });
  }

  function openRail(row, at, keys) {
    const board = /^\/chat\/([a-z-]+)\/$/.exec(row.getAttribute('href') || '')?.[1] || '';
    const key = row.dataset.cxOpen || '';
    const kind = key.startsWith('im:') ? 'im' : key.startsWith('chan:') ? 'chan' : 'board';
    if (kind === 'board' && !board) return;
    const boardKeys = () => (api.notif?.items() || []).filter((i) => i.unread && String(i.url || '').startsWith(`/chat/${board}/`)).map((i) => i.key);
    const unread = kind === 'board' ? boardKeys().length : api.railUnread(key);
    menu.open({
      title: row.querySelector('.cx-label')?.textContent || '',
      rows: railRows({ kind, unread, muted: kind !== 'board' && api.isMuted(key) }),
      at,
      anchor: row,
      keys,
      opener: row,
      owner: `rail:${key || `board:${board}`}`,
      onPick: (pick) => {
        if (pick === 'open') row.click();
        else if (pick === 'read' && kind === 'board') for (const k of boardKeys()) api.notif.read(k);
        else if (pick === 'read') api.markRailRead(key);
        else if (pick === 'mute' || pick === 'unmute') api.setMuted(key, pick === 'mute');
      },
    });
  }

  function open(node, { at, keys }) {
    if (node.matches(PLAYER)) return openPlayer(node, at, keys);
    if (node.matches('.cchan-line')) return openLine(node, at, keys);
    if (node.matches('.cpost')) return openPost(node, at, keys);
    if (node.matches('.cx-row')) return openRail(node, at, keys);
    return undefined;
  }

  const ownerOf = (node) => {
    if (node.matches(PLAYER)) return `player:${playerOf(node).id}`;
    if (node.matches('.cchan-line')) return `line:${api.lineOf(node).conv}#${node.dataset.line}`;
    if (node.matches('.cpost')) return `post:${node.dataset.post}`;
    return `rail:${node.dataset.cxOpen || ''}`;
  };
  // Before the browser's own handling of a right press (Safari's word selection), what was selected.
  main.addEventListener('mousedown', (e) => {
    if (e.button !== 2 && !(e.button === 0 && e.ctrlKey)) return;
    press.at = Date.now();
    press.target = e.target;
    press.had = selectedOver(e.target);
  }, true);
  bindContextTrigger(main, {
    rowOf: surfaceOf,
    open: (node, o) => {
      // The word a right-click selected on its own goes again, so the menu is about the line.
      if (press.target && !press.had && Date.now() - press.at < PRESS_MS) globalThis.getSelection?.()?.removeAllRanges();
      press.target = null;
      open(node, o);
    },
    isOpenFor: (node) => menu.isOpenFor(ownerOf(node)),
    longPress: true,
  });
  // A name without a profile page is a button: a click opens its menu, as the game's chat names do.
  main.addEventListener('click', (e) => {
    const who = e.target.closest('.cchan-who');
    if (!who) return;
    e.preventDefault();
    openPlayer(who, e.clientX || e.clientY ? { x: e.clientX, y: e.clientY } : null, e.detail === 0);
  });

  async function copy(text, done) {
    try {
      await navigator.clipboard.writeText(String(text || ''));
      api.flash(done);
    } catch {
      api.flash(WORDS.noCopy, true);
    }
  }

  // —— Report: a small box over the app, then a ticket the team reads in the console ——

  let box = null;
  function report({ title, about, line }) {
    box?.remove();
    const back = document.activeElement;
    box = document.createElement('form');
    box.className = 'cx-report';
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-labelledby', 'cx-report-title');
    box.innerHTML = '<h2 class="cx-report-title" id="cx-report-title"></h2><p class="cx-report-line" hidden></p>'
      + '<label class="clabel" for="cx-report-why"></label><textarea class="ctext" id="cx-report-why" name="why" rows="3" maxlength="600"></textarea>'
      + '<div class="cedit-row"><button class="cbutton" type="submit"></button><button class="cbutton cbutton-quiet" type="button" data-cancel></button></div><p class="csaid" role="status" aria-live="polite"></p>';
    box.querySelector('h2').textContent = title;
    box.querySelector('label').textContent = WORDS.why;
    box.querySelector('[type="submit"]').textContent = WORDS.send;
    box.querySelector('[data-cancel]').textContent = WORDS.cancel;
    if (line?.text) {
      const p = box.querySelector('.cx-report-line');
      p.hidden = false;
      p.textContent = `${line.name}: ${line.text}`;
    }
    main.append(box);
    const close = () => { box?.remove(); box = null; back?.focus?.({ preventScroll: true }); };
    box.querySelector('[data-cancel]').addEventListener('click', close);
    box.addEventListener('keydown', (e) => { if (e.key === 'Escape') { e.preventDefault(); close(); } });
    box.addEventListener('submit', async (e) => {
      e.preventDefault();
      const why = box.why.value.trim();
      const where = line ? `${line.label}: ${line.link}` : location.href;
      const message = [`Chat report: ${about}.`, line ? `They said in ${line.label}: "${line.text}"` : '', `Where: ${where}`, why ? `Why: ${why}` : ''].filter(Boolean).join('\n');
      const button = box.querySelector('[type="submit"]');
      button.disabled = true;
      const r = await call('tickets', { method: 'POST', body: { message, context: { page: location.pathname + location.search } } });
      button.disabled = false;
      if (!r.ok) {
        const s = box.querySelector('.csaid');
        s.textContent = r.data?.message || WORDS.failed;
        s.dataset.tone = 'bad';
        return;
      }
      close();
      api.flash(WORDS.reported);
    });
    box.why.focus();
  }

  return { menu, surfaceOf };
}
