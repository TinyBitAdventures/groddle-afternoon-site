/**
 * The Maps page's compare tools (src/RestorationMaps.php draws every map
 * server-side, each pair as two figures side by side; this only adds the
 * tools of the compare page Austin asked to keep, 2026-11-14):
 * - Wipe, the mode each pair opens in (the pair's data-mode, drawn from
 *   maps.json's "view"; Austin 2026-11-14: "I want the 'wipe' option to be
 *   the default"): the picture before (Tiny Speck's 2012 map) on the left and
 *   the one now on the right, the line between them dragged (or moved with
 *   the slider and its arrow keys).
 * - Flip: the pair stacked in one box, the picture in the game now first; a
 *   click, Enter or Space on the picture (or the Now and Before buttons)
 *   flips to the other, so the difference shows in place.
 * - Side by side: the two figures as drawn without JavaScript.
 * - Enlarge: a larger preview in a dialog, with Wipe and Flip, zoom 1x, 2x
 *   and 3x (the 2x pictures), and the next or previous map; keys 1 and 2 pick
 *   a picture, the arrow keys change map, Escape closes.
 * Styles: the theme's style.css (.maps-*), colours from restoration.css tokens.
 */
(() => {
  const pairs = [...document.querySelectorAll('[data-maps-pair]')];
  if (!pairs.length) return;
  /** The mode a pair opens in: the one the page drew on it, else Wipe. */
  const MODES = ['wipe', 'flip', 'side'];
  const startMode = (pair, allowed = MODES) => (allowed.includes(pair.dataset.mode) ? pair.dataset.mode : 'wipe');
  /** The larger preview opens in the page's mode too (read before any pair changes its own). */
  const previewMode = startMode(pairs[0], ['wipe', 'flip']);

  const el = (tag, attrs = {}, ...kids) => {
    const node = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) {
      if (v == null || v === false) continue;
      if (k === 'class') node.className = v;
      else if (k === 'text') node.textContent = v;
      else node.setAttribute(k, v === true ? '' : v);
    }
    node.append(...kids.filter(Boolean));
    return node;
  };
  const button = (label, attrs = {}) => el('button', { type: 'button', ...attrs }, label);
  const pressed = (buttons, on) => buttons.forEach((b) => b.setAttribute('aria-pressed', String(on(b))));

  /** A pair's two pictures as drawn: { src, srcset, src2, label, w, h }. */
  const picsOf = (pair) => Object.fromEntries([...pair.querySelectorAll('figure[data-pic]')].map((fig) => {
    const img = fig.querySelector('img');
    const srcset = img.getAttribute('srcset') || '';
    const two = srcset.split(',').map((s) => s.trim().split(/\s+/)).find((p) => p[1] === '2x');
    return [fig.dataset.pic, { src: img.getAttribute('src'), srcset, src2: two ? two[0] : img.getAttribute('src'), label: fig.querySelector('figcaption')?.textContent || '', w: Number(img.getAttribute('width')), h: Number(img.getAttribute('height')) }];
  }));

  /**
   * A stage: the two pictures stacked in one box, flipped or wiped. `big` uses the 2x pictures.
   * Returns { node, inner, show(pic), mode(m), cut(pct), state }.
   */
  function stage(pics, big) {
    const box = pics.after;
    // Lazy like the figures, so a page of fifty maps only fetches the ones scrolled to.
    const img = (p, cls) => el('img', { class: cls, src: big ? p.src2 : p.src, srcset: big ? null : p.srcset || null, alt: '', draggable: 'false', loading: big ? null : 'lazy', decoding: 'async' });
    const under = img(pics.before, 'maps-stage-under');
    const over = img(pics.after, 'maps-stage-over');
    const line = el('span', { class: 'maps-stage-line', hidden: true });
    const tagL = el('span', { class: 'maps-stage-tag maps-stage-tag-l' });
    const tagR = el('span', { class: 'maps-stage-tag maps-stage-tag-r', hidden: true });
    const inner = el('div', { class: 'maps-stage-inner' }, under, over, line, tagL, tagR);
    inner.style.aspectRatio = `${box.w} / ${box.h}`;
    inner.style.setProperty('--ar', String(box.w / box.h));
    const node = el('div', { class: 'maps-stage' }, inner);
    const state = { pic: 'after', mode: 'flip', cut: 50 };
    const draw = () => {
      const flip = state.mode === 'flip';
      over.hidden = flip && state.pic !== 'after';
      over.style.clipPath = flip ? '' : `inset(0 0 0 ${state.cut}%)`;
      line.hidden = flip;
      line.style.left = `${state.cut}%`;
      tagR.hidden = flip;
      tagL.textContent = flip ? pics[state.pic].label : pics.before.label;
      tagR.textContent = pics.after.label;
      node.dataset.mode = state.mode;
    };
    // Drag the wipe's line across the picture.
    let drag = false;
    const move = (e) => {
      const r = inner.getBoundingClientRect();
      state.cut = Math.round(Math.max(0, Math.min(100, ((e.clientX - r.left) / r.width) * 100)));
      draw();
      node.dispatchEvent(new CustomEvent('maps-cut', { detail: state.cut }));
    };
    inner.addEventListener('pointerdown', (e) => {
      if (state.mode !== 'wipe') return;
      drag = true;
      inner.setPointerCapture?.(e.pointerId);
      move(e);
    });
    inner.addEventListener('pointermove', (e) => drag && move(e));
    inner.addEventListener('pointerup', () => { drag = false; });
    inner.addEventListener('pointercancel', () => { drag = false; });
    draw();
    return {
      node,
      inner,
      state,
      show(pic) { state.pic = pic; draw(); },
      mode(m) { state.mode = m; draw(); },
      cut(pct) { state.cut = pct; draw(); },
    };
  }

  /** The tools row: modes, pictures (flip), the wipe's slider; calls back on each change. */
  function tools(pics, { modes, onMode, onPic, onCut, extra = [] }) {
    const modeButtons = modes.map(([id, label]) => button(label, { 'data-mode': id, 'aria-pressed': 'false' }));
    const picButtons = [['after', 'Now'], ['before', 'Before']].map(([id, label]) => button(label, { 'data-pic': id, 'aria-pressed': 'false', title: pics[id].label }));
    const slider = el('input', { type: 'range', min: '0', max: '100', value: '50', class: 'maps-cut', 'aria-label': 'Where the wipe divides the two pictures' });
    const picGroup = el('div', { class: 'maps-seg', role: 'group', 'aria-label': 'Picture' }, ...picButtons);
    const row = el('div', { class: 'maps-tools' },
      el('div', { class: 'maps-seg', role: 'group', 'aria-label': 'Compare' }, ...modeButtons),
      picGroup, slider, ...extra);
    modeButtons.forEach((b) => b.addEventListener('click', () => onMode(b.dataset.mode)));
    picButtons.forEach((b) => b.addEventListener('click', () => onPic(b.dataset.pic)));
    slider.addEventListener('input', () => onCut(Number(slider.value)));
    return {
      row,
      sync({ mode, pic, cut }) {
        pressed(modeButtons, (b) => b.dataset.mode === mode);
        pressed(picButtons, (b) => b.dataset.pic === pic);
        picGroup.hidden = mode !== 'flip';
        slider.hidden = mode !== 'wipe';
        slider.value = String(cut);
      },
    };
  }

  // The larger preview, made once and shared by every map.
  let modal = null;
  function preview() {
    if (modal) return modal;
    const title = el('h2', { class: 'maps-modal-title', id: 'maps-modal-title' });
    const close = button('×', { class: 'maps-modal-close', 'aria-label': 'Close' });
    const prev = button('‹ Previous', { class: 'maps-modal-step' });
    const next = button('Next ›', { class: 'maps-modal-step' });
    const zooms = [1, 2, 3].map((z) => button(`${z}×`, { 'data-zoom': String(z), 'aria-pressed': 'false' }));
    const holder = el('div', { class: 'maps-modal-body' });
    const hint = el('p', { class: 'maps-modal-hint', text: 'Keys: 1 now, 2 before, F flips, arrow keys change map, Escape closes. Click the picture to flip it.' });
    const toolsSlot = el('div', { class: 'maps-modal-tools' });
    const dialog = el('dialog', { class: 'maps-modal', 'aria-labelledby': 'maps-modal-title' },
      el('div', { class: 'maps-modal-head' }, title, close),
      toolsSlot, holder,
      el('div', { class: 'maps-modal-foot' }, prev, el('div', { class: 'maps-seg', role: 'group', 'aria-label': 'Zoom' }, ...zooms), next),
      hint);
    document.body.append(dialog);
    const m = { dialog, at: 0, zoom: 1, view: null, bar: null, state: { mode: previewMode, pic: 'after', cut: 50 } };
    const sync = () => { m.bar.sync(m.state); pressed(zooms, (b) => Number(b.dataset.zoom) === m.zoom); };
    m.open = (index) => {
      m.at = (index + pairs.length) % pairs.length;
      const pair = pairs[m.at];
      const pics = picsOf(pair);
      title.textContent = pair.closest('[data-maps-card]')?.querySelector('h3')?.textContent || '';
      m.view = stage(pics, true);
      m.view.inner.style.setProperty('--zoom', String(m.zoom));
      m.view.node.classList.add('maps-stage-big');
      m.view.inner.addEventListener('click', () => { if (m.state.mode === 'flip') m.pick(m.state.pic === 'after' ? 'before' : 'after'); });
      m.view.node.addEventListener('maps-cut', (e) => { m.state.cut = e.detail; sync(); });
      m.bar = tools(pics, {
        modes: [['wipe', 'Wipe'], ['flip', 'Flip']],
        onMode: (mode) => { m.state.mode = mode; m.view.mode(mode); sync(); },
        onPic: (pic) => m.pick(pic),
        onCut: (cut) => { m.state.cut = cut; m.view.cut(cut); },
      });
      holder.replaceChildren(m.view.node);
      toolsSlot.replaceChildren(m.bar.row);
      m.view.mode(m.state.mode);
      m.view.cut(m.state.cut);
      m.view.show(m.state.pic);
      sync();
      if (!dialog.open) dialog.showModal();
    };
    m.pick = (pic) => { m.state.pic = pic; m.state.mode = 'flip'; m.view.mode('flip'); m.view.show(pic); sync(); };
    zooms.forEach((b) => b.addEventListener('click', () => { m.zoom = Number(b.dataset.zoom); m.view.inner.style.setProperty('--zoom', String(m.zoom)); sync(); }));
    prev.addEventListener('click', () => m.open(m.at - 1));
    next.addEventListener('click', () => m.open(m.at + 1));
    close.addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', (e) => { if (e.target === dialog) dialog.close(); });
    dialog.addEventListener('keydown', (e) => {
      if (e.target.closest('input')) return;
      if (e.key === '1') m.pick('after');
      else if (e.key === '2') m.pick('before');
      else if (e.key === 'f' || e.key === 'F') m.pick(m.state.pic === 'after' ? 'before' : 'after');
      else if (e.key === 'ArrowRight') m.open(m.at + 1);
      else if (e.key === 'ArrowLeft') m.open(m.at - 1);
      else return;
      e.preventDefault();
    });
    modal = m;
    return m;
  }

  pairs.forEach((pair, index) => {
    const pics = picsOf(pair);
    if (!pics.before || !pics.after) return;
    const name = pair.closest('[data-maps-card]')?.querySelector('h3')?.textContent || 'this map';
    const view = stage(pics, false);
    const state = { mode: startMode(pair), pic: 'after', cut: 50 };
    const flip = () => { state.pic = state.pic === 'after' ? 'before' : 'after'; view.show(state.pic); bar.sync(state); };
    view.inner.addEventListener('click', () => { if (state.mode === 'flip') flip(); });
    view.inner.addEventListener('keydown', (e) => {
      if (state.mode !== 'flip' || (e.key !== 'Enter' && e.key !== ' ')) return;
      e.preventDefault();
      flip();
    });
    view.node.addEventListener('maps-cut', (e) => { state.cut = e.detail; bar.sync(state); });
    const enlarge = button('Enlarge', { class: 'maps-enlarge', 'aria-label': `Enlarge ${name}` });
    enlarge.addEventListener('click', () => preview().open(index));
    const setMode = (mode) => {
      state.mode = mode;
      pair.dataset.mode = mode;
      if (mode !== 'side') view.mode(mode);
      view.inner.setAttribute('role', mode === 'flip' ? 'button' : 'img');
      view.inner.setAttribute('aria-label', mode === 'flip' ? `Flip ${name} between the picture now and the picture before` : `${name}: the picture before on the left, the picture now on the right`);
      view.inner.tabIndex = mode === 'flip' ? 0 : -1;
      bar.sync(state);
    };
    const bar = tools(pics, {
      modes: [['wipe', 'Wipe'], ['flip', 'Flip'], ['side', 'Side by side']],
      onMode: setMode,
      onPic: (pic) => { state.pic = pic; view.show(pic); bar.sync(state); },
      onCut: (cut) => { state.cut = cut; view.cut(cut); },
      extra: [enlarge],
    });
    pair.prepend(bar.row, view.node);
    pair.classList.add('maps-pair-live');
    setMode(state.mode);
  });
})();
