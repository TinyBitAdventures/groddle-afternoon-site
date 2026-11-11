/**
 * The console's chart kit, shared by the Server tab (console/server.js) and the Analytics tab (console/analytics.js):
 * numbers and times in words, the DOM and SVG builders, a REST caller with its own nonce, and time charts as inline
 * SVG (no chart library) that several stack on one time axis with one crosshair and one tooltip through them all.
 * Colours come from server.css's tokens (.hz-s1 to .hz-s3), so the site's light and dark themes switch them; text is
 * always the page's text colours, never a series colour. Every name and value goes in with textContent.
 *
 * Imported as ./charts.js?v=<CHARTS_KEY> by both tabs: bump the key in both imports when this file changes.
 */

export const round = (v, d = 0) => { const p = 10 ** d; return Math.round(v * p) / p; };
export const fmt = (v, d = 0) => (v == null ? '–' : round(v, d).toLocaleString(undefined, { maximumFractionDigits: d, minimumFractionDigits: 0 }));

/** PURE: about n round ticks from 0 to at least max. */
export function niceTicks(max, n = 4) {
  if (!(max > 0)) return [0, 1];
  const raw = max / n;
  const mag = 10 ** Math.floor(Math.log10(raw));
  const step = [1, 2, 2.5, 5, 10].map((m) => m * mag).find((s) => s >= raw) || 10 * mag;
  const out = [];
  for (let v = 0; v < max + step * 0.999; v += step) out.push(round(v, 6));
  return out;
}

/** PURE: time ticks between a and b (unix seconds) at most every minGap seconds, on local hours, days or months. */
export function timeTicks(a, b, count) {
  const span = b - a;
  const H = 3600; const D = 86400;
  const steps = [H, 2 * H, 3 * H, 6 * H, 12 * H, D, 2 * D, 7 * D, 14 * D];
  const step = steps.find((s) => span / s <= count);
  const out = [];
  if (step) {
    const d = new Date(a * 1000);
    if (step < D) d.setMinutes(0, 0, 0); else d.setHours(0, 0, 0, 0);
    if (step === 7 * D || step === 14 * D) d.setDate(d.getDate() - ((d.getDay() + 6) % 7)); // Mondays
    while (d.getTime() / 1000 < a) step < D ? d.setHours(d.getHours() + 1) : d.setDate(d.getDate() + 1);
    // On the step's own grid (local hours divisible by it; days stepping from the first).
    if (step < D) while (d.getHours() % (step / H)) d.setHours(d.getHours() + 1);
    for (; d.getTime() / 1000 <= b; step < D ? d.setHours(d.getHours() + step / H) : d.setDate(d.getDate() + step / D)) out.push(d.getTime() / 1000);
    return { ticks: out, unit: step < D ? 'hour' : 'day' };
  }
  const months = [1, 2, 3, 6, 12].find((m) => span / (m * 30 * D) <= count) || 12;
  const d = new Date(a * 1000);
  d.setDate(1); d.setHours(0, 0, 0, 0);
  while (d.getTime() / 1000 < a || d.getMonth() % months) d.setMonth(d.getMonth() + 1);
  for (; d.getTime() / 1000 <= b; d.setMonth(d.getMonth() + months)) out.push(d.getTime() / 1000);
  return { ticks: out, unit: 'month' };
}

/** A tick's label in local time, for timeTicks' unit. */
export function tickLabel(t, unit) {
  return unit === 'hour'
    ? new Date(t * 1000).toLocaleTimeString([], { hour: 'numeric' })
    : new Date(t * 1000).toLocaleDateString([], unit === 'month' ? { month: 'short', year: '2-digit' } : { month: 'short', day: 'numeric' });
}

/** PURE: bytes as KB, MB or GB (decimal), per second when perSecond. */
export function bytes(v, perSecond = false) {
  if (v == null) return '–';
  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  let i = 0;
  while (Math.abs(v) >= 1000 && i < units.length - 1) { v /= 1000; i++; }
  return `${fmt(v, v < 10 && i > 0 ? 1 : 0)} ${units[i]}${perSecond ? '/s' : ''}`;
}

/** PURE: seconds as "3 days", "5 h", "12 min". */
export function duration(s) {
  if (s == null) return '–';
  if (s >= 2 * 86400) return `${Math.floor(s / 86400)} days`;
  if (s >= 86400) return '1 day';
  if (s >= 2 * 3600) return `${Math.floor(s / 3600)} h`;
  return `${Math.max(1, Math.floor(s / 60))} min`;
}

const SVG = 'http://www.w3.org/2000/svg';
export function el(tag, attrs = {}, ...kids) {
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
export function sv(tag, attrs = {}, text) {
  const n = document.createElementNS(SVG, tag);
  for (const [k, v] of Object.entries(attrs)) if (v != null) n.setAttribute(k, String(v));
  if (text != null) n.textContent = String(text);
  return n;
}

/** GET groddle/v1/<path> with the REST nonce (fetched once, again when it goes stale). */
export function createApi() {
  let nonce = '';
  async function fresh() {
    const r = await fetch('/wp-admin/admin-ajax.php?action=rest-nonce', { credentials: 'same-origin', cache: 'no-store' });
    const t = (await r.text()).trim();
    nonce = r.ok && /^[0-9a-f]{10}$/.test(t) ? t : '';
  }
  async function call(path, retried = false) {
    if (!nonce) await fresh();
    const r = await fetch(`/wp-json/groddle/v1/${path}`, { credentials: 'same-origin', cache: 'no-store', headers: { 'X-WP-Nonce': nonce } });
    const data = await r.json().catch(() => null);
    if (r.status === 403 && data?.code === 'rest_cookie_invalid_nonce' && !retried) {
      nonce = '';
      return call(path, true);
    }
    if (!r.ok) throw Object.assign(new Error(data?.message || 'That did not work.'), { status: r.status });
    return data;
  }
  return { call };
}

/** The charts' margins and height (px). */
export const GEO = Object.freeze({ left: 46, right: 14, top: 10, bottom: 22, height: 128 });

/**
 * One time chart: a figure with its caption (title, note, a legend when it has two series or more) and an SVG of
 * the rows over [a, b] (unix seconds). Returns the view the crosshair moves through (x, y, the crosshair, a dot a
 * series and the hit area), or { box, x: null } when no row has a value.
 *
 * spec: { id, title, note, series: [{ key, label, cls, area?, band?, bars?, stack? }], unit?, max?, min?, int?, dp?,
 *         bytes?, ref?() → [{ y, label }] }
 * opts: { gap: a step longer than this (seconds) breaks a line; band: draw the series' bands;
 *         step: each row covers [t, t + step], or [t, t2] when it says (its point at the middle, its bar across it);
 *         ticks(a, b, count) → { ticks, unit } and label(t, unit) for the time axis (local time by default);
 *         empty: the words when no row has a value }
 */
export function lineChart(spec, rows, [a, b], width, opts = {}) {
  const geo = GEO;
  const h = geo.height;
  const iw = width - geo.left - geo.right;
  const ih = h - geo.top - geo.bottom;
  const step = opts.step || 0;
  // A row's point: the middle of its step (of [t, t2] when the row says where it ends, as a month does).
  const mid = (r) => (r.t2 != null ? (r.t + r.t2) / 2 : r.t + step / 2);
  const stacked = spec.series.filter((s) => s.stack);
  const vals = [];
  for (const r of rows) {
    let stack = 0;
    for (const s of spec.series) {
      const v = r[s.key];
      if (v != null) { if (s.stack) stack += v; else vals.push(v); }
      if (s.band && r[s.band] != null) vals.push(r[s.band]);
    }
    if (stacked.length && stack) vals.push(stack);
  }
  const box = el('figure', { class: 'hz-chart', 'data-hz-chart': spec.id });
  const legend = spec.series.length > 1 ? el('span', { class: 'hz-legend' }, ...spec.series.map((s) => el('span', { class: 'hz-key' }, el('i', { class: `hz-swatch ${s.bars ? 'hz-swatch-bar ' : ''}${s.cls}`, 'aria-hidden': 'true' }), s.label))) : null;
  box.append(el('figcaption', {}, el('b', { text: spec.title }), spec.note ? el('span', { class: 'hz-note', text: spec.note }) : null, legend));
  if (!vals.length) {
    box.append(el('p', { class: 'console-empty', text: opts.empty || 'Not read on this machine.' }));
    return { box, x: null };
  }
  let top = spec.max ?? Math.max(...vals, spec.min || 0, ...(spec.ref ? spec.ref().map((r) => r.y) : []));
  if (spec.int) top = Math.max(top, 4);
  const ticks = niceTicks(top, 4);
  top = spec.max ?? ticks[ticks.length - 1];
  const x = (t) => geo.left + ((t - a) / (b - a)) * iw;
  const y = (v) => geo.top + ih - (v / top) * ih;
  const span = (t) => new Date(t * 1000).toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
  const svg = sv('svg', { viewBox: `0 0 ${width} ${h}`, width, height: h, class: 'hz-svg', role: 'img', 'aria-label': `${spec.title}, ${opts.span || `${span(a)} to ${span(b)}`}` });
  for (const v of ticks) {
    svg.append(sv('line', { x1: geo.left, x2: width - geo.right, y1: y(v), y2: y(v), class: 'hz-grid' }));
    const label = spec.bytes ? bytes(v, true) : (spec.tick ? spec.tick(v) : `${fmt(v, spec.dp ?? 0)}${spec.unit === '%' ? '%' : ''}`);
    svg.append(sv('text', { x: geo.left - 6, y: y(v) + 4, class: 'hz-tick', 'text-anchor': 'end' }, label));
  }
  const tt = (opts.ticks || timeTicks)(a, b, Math.max(2, Math.floor(iw / 90)));
  for (const t of tt.ticks) {
    svg.append(sv('line', { x1: x(t), x2: x(t), y1: geo.top + ih, y2: geo.top + ih + 4, class: 'hz-axis' }));
    svg.append(sv('text', { x: x(t), y: h - 5, class: 'hz-tick', 'text-anchor': 'middle' }, (opts.label || tickLabel)(t, tt.unit)));
  }
  svg.append(sv('line', { x1: geo.left, x2: width - geo.right, y1: geo.top + ih, y2: geo.top + ih, class: 'hz-axis' }));
  for (const r of spec.ref ? spec.ref() : []) {
    if (r.y > top) continue;
    svg.append(sv('line', { x1: geo.left, x2: width - geo.right, y1: y(r.y), y2: y(r.y), class: 'hz-ref' }));
    svg.append(sv('text', { x: width - geo.right - 2, y: y(r.y) - 4, class: 'hz-ref-label', 'text-anchor': 'end' }, r.label));
  }
  // Bars (stacked in the series' order), each across its row's step with a pixel's gap; the stack's top per row.
  const tops = new Map();
  if (step) {
    const base = new Map();
    for (const s of spec.series.filter((q) => q.bars)) {
      let d = '';
      for (const r of rows) {
        const v = r[s.key];
        if (v == null || v <= 0) continue;
        const from = s.stack ? (base.get(r.t) || 0) : 0;
        const x0 = x(r.t) + 0.5; const x1 = Math.max(x0 + 1, x(r.t2 ?? r.t + step) - 0.5);
        const y0 = y(from); const y1 = y(Math.min(top, from + v));
        d += `M${x0},${y0}V${y1}H${x1}V${y0}Z`;
        if (s.stack) base.set(r.t, from + v);
        tops.set(`${s.key}@${r.t}`, from + v);
      }
      svg.append(sv('path', { d, class: `hz-bar ${s.cls}` }));
    }
  }
  // A step with no row breaks the line (the server was down, or nothing was counted).
  const gap = opts.gap ?? Infinity;
  for (const s of spec.series.filter((q) => !q.bars)) {
    let line = ''; let area = ''; let band = ''; let bandBack = []; let prev = null; let run = [];
    const flush = () => {
      if (run.length === 1) { const [t, v] = run[0]; svg.append(sv('circle', { cx: x(t), cy: y(v), r: 1.5, class: `hz-dot ${s.cls}` })); }
      if (run.length && s.area) area += `M${x(run[0][0])},${y(0)}` + run.map(([t, v]) => `L${x(t)},${y(v)}`).join('') + `L${x(run[run.length - 1][0])},${y(0)}Z`;
      if (bandBack.length) { band += bandBack.map((p, i) => `${i ? 'L' : 'M'}${p}`).join('') + run.slice().reverse().map(([t, v]) => `L${x(t)},${y(v)}`).join('') + 'Z'; }
      run = []; bandBack = [];
    };
    for (const r of rows) {
      const v = r[s.key];
      if (v == null) { flush(); prev = null; continue; }
      if (prev != null && r.t - prev > gap) flush();
      line += `${run.length ? 'L' : 'M'}${x(mid(r))},${y(v)}`;
      run.push([mid(r), v]);
      if (s.band && r[s.band] != null) bandBack.push(`${x(mid(r))},${y(Math.min(r[s.band], top))}`);
      prev = r.t;
    }
    flush();
    if (area) svg.append(sv('path', { d: area, class: `hz-area ${s.cls}` }));
    if (band && opts.band) svg.append(sv('path', { d: band, class: `hz-band ${s.cls}` }));
    svg.append(sv('path', { d: line, class: `hz-line ${s.cls}` }));
  }
  const cross = sv('line', { x1: 0, x2: 0, y1: geo.top, y2: geo.top + ih, class: 'hz-cross', visibility: 'hidden' });
  const dots = spec.series.map((s) => { const c = sv('circle', { r: 4, class: `hz-mark ${s.cls}`, visibility: 'hidden' }); return { c, s }; });
  svg.append(cross, ...dots.map((d) => d.c));
  const hit = sv('rect', { x: geo.left, y: 0, width: iw, height: h, class: 'hz-hit', tabindex: '0', 'aria-label': `${spec.title}: arrow keys step through the values` });
  svg.append(hit);
  box.append(svg);
  // Where a row's value sits: its point, or the top of its bar (its stack's).
  const at = (r, s) => (s.bars ? tops.get(`${s.key}@${r.t}`) ?? null : r[s.key]);
  return { box, svg, x, y, iw, top, cross, dots, hit, a, b, width, mid, half: step / 2, at };
}

/**
 * One crosshair and one tooltip through stacked charts: hover or the arrow keys on any chart move it through all of
 * them, and tip(row) gives the tooltip's head and lines ([value, label, series class or '']). Returns { hide }.
 * frame: the element the tooltip sits in (position: relative).
 */
export function linkCharts(views, rows, { tip, frame, content }) {
  let hoverT = null;
  function nearest(t) {
    let lo = 0; let hi = rows.length - 1;
    while (hi - lo > 1) { const m = (lo + hi) >> 1; if (rows[m].t < t) lo = m; else hi = m; }
    return Math.abs(rows[lo].t - t) <= Math.abs(rows[hi].t - t) ? lo : hi;
  }
  function hide() {
    tip.hidden = true;
    hoverT = null;
    for (const v of views) { if (!v.x) continue; v.cross.setAttribute('visibility', 'hidden'); for (const d of v.dots) d.c.setAttribute('visibility', 'hidden'); }
  }
  function showAt(i, anchor) {
    const r = rows[i];
    if (!r) return hide();
    hoverT = r.t;
    for (const v of views) {
      if (!v.x) continue;
      const cx = v.x(v.mid(r));
      v.cross.setAttribute('x1', cx); v.cross.setAttribute('x2', cx); v.cross.setAttribute('visibility', 'visible');
      for (const d of v.dots) {
        const val = v.at(r, d.s);
        if (val == null) { d.c.setAttribute('visibility', 'hidden'); continue; }
        d.c.setAttribute('cx', cx); d.c.setAttribute('cy', v.y(Math.min(val, v.top))); d.c.setAttribute('visibility', 'visible');
      }
    }
    const { head, lines } = content(r);
    const row = ([value, label, cls]) => el('div', { class: 'hz-tip-row' }, el('i', { class: `hz-key-line${cls ? ` ${cls}` : ''}`, 'aria-hidden': 'true' }), el('b', { text: value }), el('span', { text: label }));
    tip.replaceChildren(el('div', { class: 'hz-tip-head', text: head }), ...lines.map(row));
    tip.hidden = false;
    // Beside the pointer (or the focused chart), kept inside the frame.
    const box = frame.getBoundingClientRect();
    const ax = anchor?.x ?? box.left + box.width / 2;
    const ay = anchor?.y ?? box.top + 60;
    const tw = tip.offsetWidth; const th = tip.offsetHeight;
    let left = ax - box.left + 16;
    if (left + tw > box.width - 8) left = ax - box.left - tw - 16;
    tip.style.left = `${Math.max(8, left)}px`;
    tip.style.top = `${Math.min(Math.max(8, ay - box.top - th / 2), box.height - th - 8)}px`;
  }
  for (const v of views) {
    if (!v.x) continue;
    const at = (e) => {
      const r = v.svg.getBoundingClientRect();
      const px = ((e.clientX - r.left) / r.width) * v.width;
      return v.a + ((px - GEO.left) / v.iw) * (v.b - v.a) - v.half;
    };
    v.hit.addEventListener('pointermove', (e) => showAt(nearest(at(e)), { x: e.clientX, y: e.clientY }));
    v.hit.addEventListener('pointerleave', hide);
    v.hit.addEventListener('blur', hide);
    v.hit.addEventListener('focus', () => { const r = v.svg.getBoundingClientRect(); showAt(hoverT == null ? rows.length - 1 : nearest(hoverT), { x: r.left + r.width / 2, y: r.top + r.height / 2 }); });
    v.hit.addEventListener('keydown', (e) => {
      const step = { ArrowLeft: -1, ArrowRight: 1, PageUp: -10, PageDown: 10 }[e.key];
      const jump = { Home: 0, End: rows.length - 1 }[e.key];
      if (step === undefined && jump === undefined) { if (e.key === 'Escape') hide(); return; }
      e.preventDefault();
      const i = jump ?? Math.min(rows.length - 1, Math.max(0, (hoverT == null ? rows.length - 1 : nearest(hoverT)) + step));
      const r = v.svg.getBoundingClientRect();
      showAt(i, { x: r.left + (v.x(v.mid(rows[i])) / v.width) * r.width, y: r.top + r.height / 2 });
    });
  }
  return { hide };
}
