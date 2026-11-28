/**
 * The Players page (/players/, src/PlayerPages.php index, G1298). The page works without this
 * script: every sort, filter, view and page is a link or a GET form the server draws. With it, a
 * filter applies as soon as it is ticked (no Show button), and the list swaps in place (the
 * same page fetched from the server) instead of a whole reload, with the address and Back kept.
 * "Visit a neighbour" is left a plain link (the server picks the house). A Snap's page loads it
 * too, for the larger view of its picture (the second part below).
 */
(() => {
  const SEL = '[data-players-list]';
  const list = document.querySelector(SEL);
  if (!list || !window.fetch || !window.DOMParser || !history.pushState) return;
  document.documentElement.classList.add('players-js');

  let pending = null;

  async function show(url, push) {
    pending?.abort();
    const ctl = new AbortController();
    pending = ctl;
    const box = document.querySelector(SEL);
    box.setAttribute('aria-busy', 'true');
    try {
      const res = await fetch(url, { signal: ctl.signal, credentials: 'same-origin', headers: { Accept: 'text/html' } });
      if (!res.ok) throw new Error(String(res.status));
      const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
      const fresh = doc.querySelector(SEL);
      if (!fresh) throw new Error('no list');
      box.replaceWith(fresh);
      if (push) history.pushState({ players: true }, '', url);
      const top = fresh.getBoundingClientRect().top;
      if (top < 0) fresh.scrollIntoView({ block: 'start' });
    } catch (err) {
      if (err?.name === 'AbortError') return;
      location.href = url;
    } finally {
      if (pending === ctl) pending = null;
      document.querySelector(SEL)?.removeAttribute('aria-busy');
    }
  }

  function formUrl(form) {
    const params = new URLSearchParams();
    for (const [k, v] of new FormData(form)) params.append(k, String(v));
    const q = params.toString();
    return `/players/${q ? `?${q}` : ''}`;
  }

  document.addEventListener('click', (e) => {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    const a = e.target.closest?.(`${SEL} a[data-players-nav], ${SEL} .players-pager a`);
    if (!a) return;
    e.preventDefault();
    show(a.getAttribute('href'), true);
  });

  document.addEventListener('change', (e) => {
    const form = e.target.closest?.(`${SEL} [data-players-filters]`);
    if (form) show(formUrl(form), true);
  });

  document.addEventListener('submit', (e) => {
    const form = e.target.closest?.(`${SEL} [data-players-filters]`);
    if (!form) return;
    e.preventDefault();
    show(formUrl(form), true);
  });

  window.addEventListener('popstate', () => {
    if (location.pathname === '/players/') show(location.pathname + location.search, false);
  });
})();

/**
 * A Snap's page (/players/{slug}/snaps/{n}/, src/PlayerPages.php snapPage; ours): a plain left
 * click (or Enter) on the picture opens it larger in a dialog over the page, with a Download and
 * a Close. Escape, a click on the dimmed page around it and Close shut it, and the focus goes back
 * to the picture. A middle click or a click with Ctrl, Cmd, Shift or Alt still opens the picture's
 * own address, and without this script (or without dialogs) the link does just that.
 */
(() => {
  const link = document.querySelector('[data-snap-open]');
  const dialog = document.querySelector('[data-snap-modal]');
  if (!link || !dialog || typeof dialog.showModal !== 'function') return;
  const root = document.documentElement;
  let downOnBackdrop = false;

  function open() {
    if (dialog.open) return;
    root.classList.add('snap-modal-open');
    dialog.showModal();
    dialog.querySelector('[data-snap-close]')?.focus();
  }

  link.addEventListener('click', (e) => {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    e.preventDefault();
    open();
  });

  dialog.querySelector('[data-snap-close]')?.addEventListener('click', () => dialog.close());
  dialog.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    e.preventDefault();
    dialog.close();
  });
  // Only a click that starts and ends on the backdrop (the dialog box itself has no padding of its own) closes it, so a drag out of the caption does not.
  dialog.addEventListener('pointerdown', (e) => { downOnBackdrop = e.target === dialog; });
  dialog.addEventListener('click', (e) => {
    if (e.target === dialog && downOnBackdrop) dialog.close();
    downOnBackdrop = false;
  });
  dialog.addEventListener('close', () => {
    root.classList.remove('snap-modal-open');
    link.focus();
  });
})();
