/**
 * The site's picture pop-up (ours: Glitch had no pop-up viewer on its photo pages or its bug
 * reports). A plain left click (or Enter) on a picture opens it larger in a dialog over the page,
 * fitted to the window, with a line under it, a Download and a Close; when the page has more than
 * one picture, Previous and Next (and the left and right arrow keys) go between them. Escape, a
 * click on the dimmed page around it and Close shut it, and the focus goes back to the picture
 * last shown. A middle click or a click with Ctrl, Cmd, Shift or Alt still opens the picture's own
 * address, and without this script (or without dialogs) the link does just that.
 *
 * The page draws the dialog closed (data-snap-modal) and marks each picture's link (data-snap-open):
 * - a Snap's page (src/PlayerPages.php snapModal): one picture, its dialog drawn whole. The page
 *   loads this file as its module script, and it starts itself there (the last line).
 * - a Support report (src/SupportPages.php pictureDialog): every picture in the report, in order.
 *   Each link carries its line (data-snap-line: who sent it and when) and its download name
 *   (data-snap-name); the dialog's picture, count, line and Download follow the one shown.
 *   community.js wires it for each view (in-place moves around /chat/ bring a new one).
 * - a profile's snaps grid and its All snaps page (src/PlayerPages.php snapsDialog): every snap on
 *   the page, in order. Each card links its snap's page (no script, or a modifier click, goes there),
 *   so it carries its full picture (data-snap-src), its download route (data-snap-download), its
 *   caption (data-snap-caption) and line (data-snap-line: street, date, by-name); the dialog's caption
 *   hides when a snap has none, and its "Open snap page" (data-snap-page) follows the one shown.
 *   The page loads this file as its module script, as a Snap's page does.
 * The look is style.css's .snap-modal, the same on both.
 */

/** The keys that step between pictures. */
const STEP = { ArrowLeft: -1, ArrowRight: 1 };

/** A click the pop-up takes: the main button, no modifier (those keep their new tab or window), not already handled. */
export function plainClick(e) {
  return !(e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey);
}

/** The dialog's words for the picture at `i` of `n`. */
export function countWords(i, n) {
  return `Picture ${i + 1} of ${n}`;
}

/**
 * Wires the dialog and the picture links under `root` (a document or a view's element). Returns a
 * stop that unhooks it and closes the dialog (a view that goes away while it is open).
 */
export function wirePictureDialog(root) {
  const none = () => {};
  const dialog = root?.querySelector('[data-snap-modal]');
  if (!dialog || typeof dialog.showModal !== 'function') return none;
  const links = [...root.querySelectorAll('[data-snap-open]')];
  if (!links.length) return none;
  const html = (root.ownerDocument || root).documentElement;
  const part = (name) => dialog.querySelector(`[data-snap-${name}]`);
  const many = links.length > 1;
  const offs = [];
  const on = (el, type, fn) => {
    if (!el) return;
    el.addEventListener(type, fn);
    offs.push(() => el.removeEventListener?.(type, fn));
  };
  let at = 0;
  let downOnBackdrop = false;

  /** The picture at `i` (round the ends) into the dialog. One picture's dialog is drawn whole, so it is left as it is. */
  function show(i) {
    at = (i + links.length) % links.length;
    if (!many) return;
    const link = links[at];
    const thumb = link.querySelector('img');
    const href = link.getAttribute('href');
    // A snap card links its page; its picture is its data-snap-src. A report's thumbnail links the picture itself.
    const src = link.getAttribute('data-snap-src') || href;
    const img = part('img');
    if (img) {
      img.src = src;
      for (const k of ['width', 'height']) {
        const v = thumb?.getAttribute(k);
        if (v) img.setAttribute(k, v); else img.removeAttribute(k);
      }
      img.alt = thumb?.getAttribute('alt') || '';
    }
    const count = part('count');
    if (count) count.textContent = countWords(at, links.length);
    const caption = part('caption');
    if (caption) {
      caption.textContent = link.getAttribute('data-snap-caption') || '';
      caption.hidden = caption.textContent === '';
    }
    const line = part('meta');
    if (line) line.textContent = link.getAttribute('data-snap-line') || '';
    const download = part('download');
    if (download) {
      download.href = link.getAttribute('data-snap-download') || src;
      download.setAttribute('download', link.getAttribute('data-snap-name') || '');
    }
    const page = part('page');
    if (page) page.href = href;
    // A report's pictures are named by their place; a snap by its caption (its picture's words).
    dialog.setAttribute('aria-label', count ? countWords(at, links.length) : thumb?.getAttribute('alt') || '');
  }

  function open(i) {
    if (dialog.open) return;
    show(i);
    html.classList.add('snap-modal-open');
    dialog.showModal();
    part('close')?.focus();
  }

  links.forEach((link, i) => on(link, 'click', (e) => {
    if (!plainClick(e)) return;
    e.preventDefault();
    open(i);
  }));
  on(part('close'), 'click', () => dialog.close());
  on(part('prev'), 'click', () => show(at - 1));
  on(part('next'), 'click', () => show(at + 1));
  on(dialog, 'keydown', (e) => {
    if (e.key === 'Escape') {
      e.preventDefault();
      dialog.close();
      return;
    }
    if (!many || !STEP[e.key] || e.altKey || e.ctrlKey || e.metaKey || e.shiftKey) return;
    // The page behind (the chat app's own keys) never sees an arrow the pop-up used.
    e.preventDefault();
    e.stopPropagation?.();
    show(at + STEP[e.key]);
  });
  // Only a click that starts and ends on the backdrop (the dialog box itself has no padding of its own) closes it, so a drag out of the words does not.
  on(dialog, 'pointerdown', (e) => { downOnBackdrop = e.target === dialog; });
  on(dialog, 'click', (e) => {
    if (e.target === dialog && downOnBackdrop) dialog.close();
    downOnBackdrop = false;
  });
  on(dialog, 'close', () => {
    html.classList.remove('snap-modal-open');
    links[at].focus();
  });

  return () => {
    for (const off of offs.splice(0)) off();
    if (dialog.open) dialog.close();
    html.classList.remove('snap-modal-open');
  };
}

// A page that loads this file itself (a Snap's page) starts here; the Community app wires each of its views (community.js).
if (typeof document !== 'undefined' && !document.querySelector('main[data-community]')) wirePictureDialog(document);
