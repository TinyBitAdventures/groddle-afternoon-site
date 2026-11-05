/**
 * Moving around /chat/ without a reload (Austin 2026-11-05: "Can we make the /chat/ use pushState").
 * Every view of the app is a real address the server draws whole (src/CommunityPages.php), so a link
 * opened in a new tab, a reload and a page without JavaScript all keep working; with this, a plain
 * click on a link inside /chat/ (and the bell's rows, the menus, the app's own buttons through go())
 * changes the view in place and pushes the address, and Back and Forward bring the view back:
 *
 * - Conversations (/chat/, ?c=<channel>, ?im=<player>, #t<line> for a line's thread) are the chat
 *   app's (chat-app.js registers app.show/app.leave): drawn from what it keeps, the live socket stays
 *   connected, a conversation you come back to keeps its place.
 * - The boards, a board, a topic and the new-topic form (and a visitor's /chat/) are fetched as the
 *   page the server draws (the same address, no new routes), and the page's conversation pane is
 *   swapped in with main's data for that view; community.js wires its forms and polls again
 *   (hooks.leave / hooks.enter). A reload or a link opened later draws the same.
 * - Scroll: a fresh view starts where it naturally does (a list at its top, a topic at a #post-N
 *   it was asked for, a conversation at its newest line); Back and Forward put a page's list where it
 *   was (history.state.scroll, kept as you scroll).
 * - Focus and words: the new view's heading takes the focus (unless the move asked for the input),
 *   and a polite status line says what opened. The rail marks the view (aria-current).
 *
 * Anything this cannot draw in place (another page, an answer that is not a /chat/ page) loads as the
 * browser would. QA: window.groddleNav (here, go), main[data-cx-moving] while a page is fetched.
 */
// With this file's own ?v= (CommunityPages::ASSET_KEY), so a new release never pairs with an old copy.
const { chatRoute, plainClick, sameView } = await import(`./chat-pure.js${new URL(import.meta.url).search}`);

/** main's data that belongs to a view (CommunityPages::shell $extra and its cx-active / cx-view). */
const VIEW_DATA = ['thread', 'after', 'since', 'lastPage', 'board', 'cxActive', 'cxView'];
const CONV = new Set(['home', 'chan', 'im']);

export function startNav({ main, hooks = {} }) {
  const pane = main.querySelector('[data-cx-pane]');
  if (!pane || !history.pushState) return null;
  const origin = location.origin;
  /** The view on screen now (chatRoute). */
  let here = chatRoute(location.href, origin);
  let seq = 0;
  let saving = null;
  /** chat-app.js: show(route, opts) -> true when it drew a conversation; leave() when a page takes the pane. */
  const app = { show: null, leave: null };

  const said = document.createElement('p');
  said.className = 'visually-hidden';
  said.setAttribute('role', 'status');
  said.setAttribute('aria-live', 'polite');
  said.dataset.cxAnnounce = '';
  main.append(said);

  try { history.scrollRestoration = 'manual'; } catch { /* older browsers */ }
  history.replaceState({ ...(history.state || {}), cx: 1 }, '');

  const logOf = () => pane.querySelector('.cx-log');
  // A page's list remembers where it was (for Back and Forward), a moment after you stop scrolling.
  pane.addEventListener('scroll', (e) => {
    if (!e.target.matches?.('.cx-log')) return;
    clearTimeout(saving);
    const at = e.target.scrollTop;
    saving = setTimeout(() => {
      try { history.replaceState({ ...(history.state || {}), cx: 1, scroll: at }, ''); } catch { /* the browser's own limit on how often */ }
    }, 250);
  }, { capture: true, passive: true });

  /** The rail marks the board being read (its topics, a topic, the new-topic form); the app marks its own rows. */
  function markRail(to) {
    const board = to && !CONV.has(to.view) ? `/chat/${to.board}/` : '';
    for (const a of main.querySelectorAll('.cx-rail a.cx-row:not([data-cx-open])')) {
      if (board && a.getAttribute('href') === board) a.setAttribute('aria-current', 'page');
      else if (a.getAttribute('aria-current') === 'page') a.removeAttribute('aria-current');
    }
  }

  function commit(to, { push, replace, pop }) {
    const url = to.path + to.hash;
    if (!pop) {
      const same = here && here.path + here.hash === url;
      if (replace) history.replaceState({ cx: 1 }, '', url);
      else if (push && !same) history.pushState({ cx: 1 }, '', url);
    }
    here = to;
    markRail(to);
  }

  /** The heading takes the focus and the status line says what opened. */
  function arrive(focus) {
    const h = pane.querySelector('#cx-conv-title, .cx-head-name, h2');
    if (h && focus === 'heading') {
      h.tabIndex = -1;
      h.focus({ preventScroll: true });
    }
    const words = (h?.textContent || '').trim();
    said.textContent = '';
    if (words) setTimeout(() => { said.textContent = `${words} opened`; }, 60);
  }

  function jump(hash) {
    const id = decodeURIComponent(String(hash || '').slice(1));
    const target = id && (document.getElementById(id) || pane.querySelector(`[name="${CSS.escape(id)}"]`));
    if (target && pane.contains(target)) {
      target.scrollIntoView({ block: 'start' });
      return true;
    }
    return false;
  }

  /** Main's data for the view: what the drawn page carried (none for a conversation). */
  function adopt(next) {
    for (const k of VIEW_DATA) delete main.dataset[k];
    if (next) for (const k of VIEW_DATA) if (next.dataset[k] !== undefined) main.dataset[k] = next.dataset[k];
  }

  /**
   * Shows an address: in place when it is one of the app's views, else as the browser loads it.
   * push (default) adds it to the history, replace swaps the current entry, pop is Back/Forward.
   * focus: 'heading' (default), 'input' (the conversation's box, the app's) or false.
   */
  async function go(href, { push = true, replace = false, pop = false, focus = 'heading' } = {}) {
    const to = chatRoute(href, origin);
    if (!to) {
      if (pop) location.reload();
      else location.assign(href);
      return false;
    }
    const n = ++seq;
    clearTimeout(saving);
    const saved = pop ? Number(history.state?.scroll) : NaN;
    if (CONV.has(to.view) && app.show) {
      hooks.leave?.();
      adopt(null);
      main.dataset.cxActive = 'chan';
      if (app.show(to, { focus: focus === 'input' ? 'input' : false })) {
        delete main.dataset.cxMoving;
        commit(to, { push, replace, pop });
        if (focus === 'heading') arrive('heading');
        else arrive(false);
        return true;
      }
    }
    // A page the server draws: fetched, and its conversation pane swapped in.
    main.dataset.cxMoving = '';
    pane.setAttribute('aria-busy', 'true');
    let r = null;
    let doc = null;
    try {
      r = await fetch(to.path, { credentials: 'same-origin', headers: { Accept: 'text/html' } });
      if (r.ok && (r.headers.get('content-type') || '').includes('text/html')) doc = new DOMParser().parseFromString(await r.text(), 'text/html');
    } catch { doc = null; }
    if (n !== seq) return false; // a newer move won
    delete main.dataset.cxMoving;
    pane.removeAttribute('aria-busy');
    const next = doc?.querySelector('main[data-community]');
    const nextPane = next?.querySelector('[data-cx-pane]');
    const landed = r?.url ? chatRoute(r.url + to.hash, origin) : to;
    if (!nextPane || !landed || CONV.has(landed.view) !== CONV.has(to.view)) {
      if (pop) location.reload();
      else location.assign(href);
      return false;
    }
    app.leave?.();
    hooks.leave?.();
    adopt(next);
    pane.replaceChildren(...[...nextPane.childNodes].map((node) => document.importNode(node, true)));
    if (doc.title) document.title = doc.title;
    hooks.enter?.(pane);
    // A redirect (a topic's address without its words, an old slug) lands on the address the server gave.
    commit(landed, { push, replace, pop });
    if (pop && landed.path !== to.path) history.replaceState(history.state, '', landed.path + landed.hash);
    const log = logOf();
    if (pop && Number.isFinite(saved) && log) log.scrollTop = saved;
    else if (!(landed.hash && jump(landed.hash)) && log) log.scrollTop = 0;
    arrive(focus === false ? false : 'heading');
    return true;
  }

  // A plain click on a link to one of the app's views; Cmd, Ctrl, Shift, Alt and the middle button keep theirs.
  document.addEventListener('click', (e) => {
    const a = e.target.closest?.('a[href]');
    if (!a || !plainClick(e, { target: a.getAttribute('target'), download: a.hasAttribute('download') })) return;
    const to = chatRoute(a.href, origin);
    if (!to) return;
    // Only a place on the page being read (#post-3, #reply): the browser's own jump.
    if (sameView(here, to) && to.hash && !CONV.has(to.view)) return;
    e.preventDefault();
    go(a.href);
  });

  window.addEventListener('popstate', () => {
    const to = chatRoute(location.href, origin);
    if (!to) return void location.reload();
    if (sameView(here, to) && !CONV.has(to.view)) {
      // Only the hash moved on a page: go to it.
      here = to;
      jump(to.hash);
      return;
    }
    go(location.href, { pop: true });
  });

  /** The app's own small moves (a thread opened or closed): the address follows, nothing is drawn. */
  function note(href) {
    const to = chatRoute(href, origin);
    if (!to) return;
    commit(to, { push: true });
  }

  const api = { go, note, app, get here() { return here ? { ...here } : null; } };
  globalThis.groddleNav = api;
  return api;
}
