/**
 * The console's Server tab (src/AdminConsole.php health, the realtime server's health.go; Austin 2026-11-11:
 * "review the server health over time ... see the count of active players on the game at that same moment ...
 * capture at which point the server becomes under high load and upgrade proactively").
 *
 * - Now: players online, the machine's CPU, load per core, memory and disk from the newest sample, and the
 *   machine itself (CPUs, memory, disk, version, uptime).
 * - Over time, on one time axis for every chart (24h, 7d, 30d, 90d, All): players online, then CPU, load per
 *   core, memory, network and socket frames with the slowest frame. Players and CPU are two charts one above
 *   the other rather than one chart with two scales; hovering any chart moves one crosshair through them all
 *   and one tooltip lists every value at that moment, the player count first.
 * - Load against players: every sample (or bucket) as a dot, players across, CPU % (or load per core) up,
 *   with a least-squares line and what it says in words: how much CPU 10 more players cost, and at about how
 *   many players CPU would reach 70% (load 1.0 per core) if that holds.
 * - The numbers as a table, newest first.
 *
 * Its own module beside console.js (as moderation.js): it fills the Server tab's panel, keeps its own REST
 * nonce, and draws its charts as inline SVG (no chart library) with the console's chart kit (console/charts.js,
 * shared with the Analytics tab). Colours come from server.css's tokens, so the site's light and dark themes
 * switch them; text is always the page's text colours, never a series colour. Every name and value goes in with
 * textContent.
 */

import { round, fmt, niceTicks, bytes, duration, el, sv, createApi, lineChart, linkCharts } from './charts.js?v=charts-1';

export { niceTicks, timeTicks, bytes, duration } from './charts.js?v=charts-1';

/** admin/health's ranges, in the buttons' order. */
export const RANGES = Object.freeze([['24h', '24 hours'], ['7d', '7 days'], ['30d', '30 days'], ['90d', '90 days'], ['all', 'All']]);
const SPAN = { '24h': 86400, '7d': 604800, '30d': 2592000, '90d': 7776000 };

/** CPU % (and load per core) the readout counts as time to upgrade. */
export const CPU_LIMIT = 70;
export const LOAD_LIMIT = 1;

/** PURE: admin/health's rows as objects by field name (null where the server had no reading). */
export function rowsOf(fields, samples) {
  return (samples || []).map((row) => {
    const o = {};
    fields.forEach((f, i) => { o[f] = row[i] ?? null; });
    return o;
  });
}

/** PURE: least squares through points [x, y]; null with fewer than 2 distinct x. */
export function trend(points) {
  const n = points.length;
  if (n < 2) return null;
  let sx = 0; let sy = 0;
  for (const [x, y] of points) { sx += x; sy += y; }
  const mx = sx / n; const my = sy / n;
  let sxx = 0; let sxy = 0; let syy = 0;
  for (const [x, y] of points) { sxx += (x - mx) ** 2; sxy += (x - mx) * (y - my); syy += (y - my) ** 2; }
  if (sxx === 0) return null;
  const slope = sxy / sxx;
  return { slope, intercept: my - slope * mx, r2: syy === 0 ? 0 : (sxy * sxy) / (sxx * syy), n };
}


/**
 * PURE: the readout under the scatter: how the y measure grows with players and where it would reach its limit.
 * points [players, y]; kind 'cpu' (y CPU %) or 'load' (y load per core).
 */
export function readout(points, kind = 'cpu') {
  const xs = points.map((p) => p[0]);
  const distinct = new Set(xs.map((x) => Math.round(x))).size;
  const maxX = xs.length ? Math.max(...xs) : 0;
  const limit = kind === 'cpu' ? CPU_LIMIT : LOAD_LIMIT;
  const what = kind === 'cpu' ? 'CPU' : 'Load per core';
  const unit = (v) => (kind === 'cpu' ? `${fmt(v, 1)}%` : fmt(v, 2));
  if (points.length < 10 || distinct < 3) {
    return [`Not enough spread in player counts yet to say how ${what === 'CPU' ? 'CPU' : 'load'} grows with players. It needs samples at three or more different player counts.`];
  }
  const t = trend(points);
  const busiest = points.reduce((a, b) => (b[1] > a[1] ? b : a));
  const lines = [];
  if (!t || t.slope <= 0) {
    lines.push(`${what} does not rise with players so far (${points.length.toLocaleString()} samples, up to ${fmt(maxX)} players).`);
  } else {
    const per10 = t.slope * 10;
    lines.push(kind === 'cpu'
      ? `CPU averages about ${fmt(per10, per10 < 1 ? 2 : 1)}% more per 10 players, from about ${fmt(Math.max(0, t.intercept), 1)}% with nobody on.`
      : `Load per core rises about ${fmt(per10, 2)} per 10 players, from about ${fmt(Math.max(0, t.intercept), 2)} with nobody on.`);
    const at = (limit - t.intercept) / t.slope;
    if (at <= 0) lines.push(`It is already at ${unit(limit)} or more with few players: the machine is busy with something else.`);
    else lines.push(`At this rate it would reach ${unit(limit)} at about ${fmt(at < 50 ? at : round(at / 5) * 5)} players (the most so far is ${fmt(maxX)}).`);
    if (t.r2 < 0.3) lines.push('Players explain little of the swing so far, so treat that as rough.');
  }
  lines.push(`Busiest: ${unit(busiest[1])} with ${fmt(busiest[0], busiest[0] % 1 ? 1 : 0)} players.`);
  return lines;
}


// —— What each chart plots ——
const perSecond = (v) => (v == null ? null : v / 60);
const pct = (a, b) => (a == null || !b ? null : (a / b) * 100);
const CHARTS = [
  { id: 'players', title: 'Players online', note: 'the most in each step', series: [{ key: 'p', label: 'Players', cls: 'hz-s2', area: true }], int: true },
  { id: 'cpu', title: 'CPU, whole machine', note: 'mean; the band reaches the busiest minute', unit: '%', max: 100, series: [{ key: 'c', label: 'CPU', cls: 'hz-s1', band: 'cx' }], ref: () => [{ y: CPU_LIMIT, label: `${CPU_LIMIT}%` }] },
  { id: 'load', title: 'Load per core', note: '1-minute load average ÷ CPUs; 1.0 is every core busy', series: [{ key: 'lpc', label: 'Load per core', cls: 'hz-s1', band: 'lpcx' }], ref: () => [{ y: LOAD_LIMIT, label: '1.0 per core' }], min: 1.2, dp: 2 },
  { id: 'mem', title: 'Memory used', note: 'of the machine; this server\'s own share in the tooltip', unit: '%', max: 100, series: [{ key: 'memp', label: 'Memory', cls: 'hz-s1' }] },
  { id: 'net', title: 'Network', note: 'every interface but loopback', bytes: true, series: [{ key: 'nis', label: 'In', cls: 'hz-s1' }, { key: 'nos', label: 'Out', cls: 'hz-s3' }] },
  { id: 'frames', title: 'Socket frames a minute', note: 'read from and written to players\' games and /chat/ tabs', series: [{ key: 'mi', label: 'In', cls: 'hz-s1' }, { key: 'mo', label: 'Out', cls: 'hz-s3' }], int: true },
  { id: 'lag', title: 'Slowest frame', note: 'the longest a frame waited for and held the server, each step\'s worst', unit: ' ms', series: [{ key: 'lag', label: 'Slowest', cls: 'hz-s1' }], dp: 1 },
];

/** Adds what the charts plot that the server does not send as such. */
function derive(r) {
  r.lpc = r.l1 != null && r.n ? r.l1 / r.n : null;
  r.lpcx = r.l1x != null && r.n ? r.l1x / r.n : null;
  r.memp = pct(r.mu, r.mt);
  r.nis = perSecond(r.ni);
  r.nos = perSecond(r.no);
  return r;
}

function boot() {
  const panel = document.querySelector('[data-console-tabpanel="server"]');
  if (!panel || panel.querySelector('[data-hz-now]')) return;
  const api = createApi();
  const state = { range: '24h', data: null, rows: [], loading: false, hoverT: null, scatter: 'cpu', timer: 0 };

  // —— The frame ——
  const status = el('p', { class: 'console-sub hz-status', role: 'status', 'aria-live': 'polite', 'data-hz-status': '' });
  const tiles = el('div', { class: 'hz-tiles', 'data-hz-now': '' });
  const machine = el('p', { class: 'hz-machine console-sub', 'data-hz-machine': '' });
  const nowSection = el('section', { class: 'console-panel', 'aria-labelledby': 'console-hz-now-title' },
    el('div', { class: 'console-panel-head' }, el('h2', { id: 'console-hz-now-title' }, 'Now'), status),
    tiles, machine);

  const rangeButtons = RANGES.map(([id, label]) => el('button', { type: 'button', class: 'console-small', 'data-hz-range': id, 'aria-pressed': String(id === state.range), onclick: () => setRange(id) }, label));
  const rangeRow = el('div', { class: 'hz-ranges', role: 'group', 'aria-label': 'Time range' }, ...rangeButtons);
  const span = el('p', { class: 'console-hint hz-span', 'data-hz-span': '' });
  const empty = el('p', { class: 'console-empty', hidden: true, 'data-hz-empty': '' });
  const charts = el('div', { class: 'hz-charts', 'data-hz-charts': '' });
  const tip = el('div', { class: 'hz-tip', hidden: true, role: 'status', 'aria-live': 'off', 'data-hz-tip': '' });
  const overTime = el('section', { class: 'console-panel hz-over', 'aria-labelledby': 'console-hz-time-title' },
    el('div', { class: 'console-panel-head' }, el('h2', { id: 'console-hz-time-title' }, 'Over time'),
      el('p', { class: 'console-hint' }, 'Hover or use the arrow keys on a chart to read every value at that moment.')),
    rangeRow, span, empty, charts, tip);

  const scatterToggle = [['cpu', 'CPU %'], ['load', 'Load per core']].map(([id, label]) => el('button', { type: 'button', class: 'console-small', 'data-hz-scatter': id, 'aria-pressed': String(id === state.scatter), onclick: () => { state.scatter = id; drawScatter(); } }, label));
  const scatterBox = el('div', { class: 'hz-scatter', 'data-hz-scatter-chart': '' });
  const words = el('div', { class: 'hz-readout', 'data-hz-readout': '' });
  const scatterSection = el('section', { class: 'console-panel', 'aria-labelledby': 'console-hz-scatter-title' },
    el('div', { class: 'console-panel-head' }, el('h2', { id: 'console-hz-scatter-title' }, 'Load against players'),
      el('p', { class: 'console-hint' }, 'Each dot is one step of the range above. The line is the trend.')),
    el('div', { class: 'hz-ranges', role: 'group', 'aria-label': 'Measure' }, ...scatterToggle), words, scatterBox);

  const tableBody = el('tbody');
  const table = el('table', { class: 'console-table hz-table', 'data-hz-table': '' },
    el('thead', {}, el('tr', {}, ...['Time', 'Players', 'Streets', 'CPU %', 'Busiest CPU %', 'Load / core', 'Memory %', 'Net in', 'Net out', 'Frames in', 'Frames out', 'Slowest ms'].map((h) => el('th', { scope: 'col', text: h })))),
    tableBody);
  const details = el('details', { class: 'hz-details' }, el('summary', {}, 'The numbers, newest first'), el('div', { class: 'hz-table-wrap' }, table));
  details.addEventListener('toggle', () => { if (details.open) drawTable(); });
  const tableSection = el('section', { class: 'console-panel' }, details);

  panel.append(nowSection, overTime, scatterSection, tableSection);

  const when = (t, withDate = true) => new Date(t * 1000).toLocaleString([], withDate ? { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' } : { hour: '2-digit', minute: '2-digit' });

  // —— Now ——
  function tile(label, value, sub) {
    return el('div', { class: 'hz-tile' }, el('span', { class: 'hz-tile-label', text: label }), el('span', { class: 'hz-tile-value', text: value }), el('span', { class: 'hz-tile-sub', text: sub }));
  }
  function drawNow() {
    const d = state.data;
    const n = d?.now;
    const m = d?.machine || {};
    tiles.replaceChildren();
    machine.textContent = '';
    if (!d || d.server !== 'up') return;
    if (!n) {
      tiles.append(tile('Players online', '–', 'The first sample is written within a minute of a start.'));
    } else {
      const cores = n.n || m.cpus;
      const unread = 'Not read on this machine';
      tiles.append(
        tile('Players online', fmt(n.p), `${fmt(n.s)} street${n.s === 1 ? '' : 's'} · ${fmt(n.w)} /chat/ tab${n.w === 1 ? '' : 's'}`),
        tile('CPU', n.c == null ? '–' : `${fmt(n.c, 1)}%`, n.c == null ? unread : `this server ${fmt(n.pc, 1)}%${n.st >= 1 ? ` · stolen ${fmt(n.st, 1)}%` : ''}`),
        tile('Load per core', n.l1 == null ? '–' : fmt(n.l1 / cores, 2), n.l1 == null ? unread : `load ${fmt(n.l1, 2)} on ${cores} CPU${cores === 1 ? '' : 's'}`),
        tile('Memory used', n.mu == null ? '–' : `${fmt(pct(n.mu, n.mt), 0)}%`, n.mu == null ? `this server ${fmt(n.hp, 0)} MB heap` : `${bytes(n.mu * 1048576)} of ${bytes(n.mt * 1048576)} · this server ${n.rss == null ? '–' : bytes(n.rss * 1048576)}`),
        tile('Disk used', n.du == null ? '–' : `${fmt(pct(n.du, n.du + n.df), 0)}%`, n.du == null ? unread : `${fmt(n.df, 1)} GB free`),
      );
    }
    const bits = [m.host, m.os, m.cpus ? `${m.cpus} CPU${m.cpus === 1 ? '' : 's'}` : null, m.mem ? `${bytes(m.mem * 1048576)} memory` : null, m.disk ? `${bytes(m.disk * 1073741824)} disk` : null,
      m.version ? `server ${m.version}` : null, m.uptime != null ? `up ${duration(m.uptime)}` : null, m.boot != null ? `machine up ${duration(m.boot)}` : null];
    machine.textContent = bits.filter(Boolean).join(' · ');
  }

  // —— Over time ——
  let linked = { hide() {} }; // the crosshair and tooltip through the charts (charts.js linkCharts)
  function domain() {
    const d = state.data;
    const rows = state.rows;
    const until = Math.min(d.until, Math.floor(Date.now() / 1000));
    let since = state.range === 'all' ? (d.first || rows[0]?.t || until - 86400) : until - SPAN[state.range];
    if (rows.length && rows[0].t > since) since = rows[0].t; // history starts later: the charts start with it
    return [since, Math.max(until, since + 60)];
  }

  /** The tooltip at one row: every value at that moment, the player count first. */
  function tipAt(r) {
    const b = state.data.bucket;
    const head = b > 60 ? `${when(r.t)} – ${when(r.t + b, new Date(r.t * 1000).toDateString() !== new Date((r.t + b) * 1000).toDateString())}` : when(r.t);
    return {
      head,
      lines: [
        [fmt(r.p), b > 60 ? `players at most (mean ${fmt(r.pa, 1)})` : 'players online', 'hz-s2'],
        [r.c == null ? '–' : `${fmt(r.c, 1)}%`, b > 60 && r.cx != null ? `CPU (busiest ${fmt(r.cx, 1)}%)` : 'CPU', 'hz-s1'],
        [r.lpc == null ? '–' : fmt(r.lpc, 2), r.l1 == null ? 'load per core' : `load per core (load ${fmt(r.l1, 2)})`, ''],
        [r.memp == null ? '–' : `${fmt(r.memp, 0)}%`, `memory${r.rss != null ? ` (this server ${bytes(r.rss * 1048576)})` : ''}`, ''],
        [`${bytes(r.nis, true)} / ${bytes(r.nos, true)}`, 'network in / out', ''],
        [`${fmt(r.mi)} / ${fmt(r.mo)}`, 'frames a minute in / out', ''],
        [r.lag == null ? '–' : `${fmt(r.lag, 1)} ms`, 'slowest frame', ''],
        [`${fmt(r.s)}`, 'streets with players', ''],
      ],
    };
  }

  function drawCharts() {
    const d = state.data;
    charts.replaceChildren();
    linked.hide();
    linked = { hide() {} };
    if (!d || d.server !== 'up' || !state.rows.length) return;
    const [a, b] = domain();
    span.textContent = `${when(a)} to ${when(b)} · ${d.bucket > 60 ? `each point is ${duration(d.bucket)}` : 'a point a minute'} · times in your time zone`;
    const width = Math.max(280, Math.floor(charts.clientWidth || overTime.clientWidth - 28));
    // A step with no sample breaks the line (the server was down or not yet sampling).
    const opts = { gap: Math.max(d.bucket * 2.5, 180), band: d.bucket > 60 };
    const views = CHARTS.map((spec) => lineChart(spec, state.rows, [a, b], width, opts));
    charts.append(...views.map((v) => v.box));
    linked = linkCharts(views, state.rows, { tip, frame: overTime, content: tipAt });
  }

  // —— Load against players ——
  function drawScatter() {
    for (const btn of scatterToggle) btn.setAttribute('aria-pressed', String(btn.dataset.hzScatter === state.scatter));
    scatterBox.replaceChildren();
    words.replaceChildren();
    const d = state.data;
    if (!d || d.server !== 'up') return;
    const cpu = state.scatter === 'cpu';
    const pts = [];
    for (const r of state.rows) {
      const yv = cpu ? r.c : r.lpc;
      if (yv != null && r.pa != null) pts.push([r.pa, yv, r.t]);
    }
    for (const line of readout(pts, cpu ? 'cpu' : 'load')) words.append(el('p', { text: line }));
    if (!pts.length) return;
    const width = Math.max(280, Math.floor(scatterBox.clientWidth || 600));
    const h = Math.min(320, Math.max(220, Math.round(width * 0.42)));
    const L = 46; const R = 14; const T = 12; const B = 34;
    const iw = width - L - R; const ih = h - T - B;
    const limit = cpu ? CPU_LIMIT : LOAD_LIMIT;
    const t = trend(pts.map(([x, y]) => [x, y]));
    const xMax = niceTicks(Math.max(4, ...pts.map((p) => p[0])), 5);
    const xTop = xMax[xMax.length - 1];
    const yTicks = cpu ? [0, 25, 50, 75, 100] : niceTicks(Math.max(limit * 1.1, ...pts.map((p) => p[1])), 4);
    const yTop = yTicks[yTicks.length - 1];
    const x = (v) => L + (v / xTop) * iw;
    const y = (v) => T + ih - (Math.min(v, yTop) / yTop) * ih;
    const svg = sv('svg', { viewBox: `0 0 ${width} ${h}`, width, height: h, class: 'hz-svg', role: 'img', 'aria-label': `${cpu ? 'CPU %' : 'Load per core'} against players online, ${pts.length} points` });
    for (const v of yTicks) {
      svg.append(sv('line', { x1: L, x2: width - R, y1: y(v), y2: y(v), class: 'hz-grid' }));
      svg.append(sv('text', { x: L - 6, y: y(v) + 4, class: 'hz-tick', 'text-anchor': 'end' }, cpu ? `${v}%` : fmt(v, 2)));
    }
    for (const v of xMax) svg.append(sv('text', { x: x(v), y: T + ih + 16, class: 'hz-tick', 'text-anchor': 'middle' }, fmt(v)));
    svg.append(sv('text', { x: L + iw / 2, y: h - 3, class: 'hz-tick hz-axis-title', 'text-anchor': 'middle' }, 'Players online'));
    svg.append(sv('line', { x1: L, x2: width - R, y1: T + ih, y2: T + ih, class: 'hz-axis' }));
    if (limit <= yTop) {
      svg.append(sv('line', { x1: L, x2: width - R, y1: y(limit), y2: y(limit), class: 'hz-ref' }));
      svg.append(sv('text', { x: width - R - 2, y: y(limit) - 4, class: 'hz-ref-label', 'text-anchor': 'end' }, cpu ? `${limit}%` : '1.0 per core'));
    }
    for (const [px, py] of pts) svg.append(sv('circle', { cx: x(px), cy: y(py), r: 3, class: 'hz-pt hz-s1' }));
    if (t && t.slope > 0) {
      const x2 = Math.min(xTop, t.slope > 0 ? (yTop - t.intercept) / t.slope : xTop);
      svg.append(sv('line', { x1: x(0), y1: y(Math.max(0, t.intercept)), x2: x(Math.max(0, x2)), y2: y(t.intercept + t.slope * Math.max(0, x2)), class: 'hz-trend' }));
    }
    const mark = sv('circle', { r: 5, class: 'hz-mark hz-s1', visibility: 'hidden' });
    const hit = sv('rect', { x: L, y: T, width: iw, height: ih, class: 'hz-hit' });
    svg.append(mark, hit);
    const stip = el('div', { class: 'hz-tip', hidden: true });
    hit.addEventListener('pointermove', (e) => {
      const r = svg.getBoundingClientRect();
      const mx = ((e.clientX - r.left) / r.width) * width; const my = ((e.clientY - r.top) / r.height) * h;
      let best = null; let bd = Infinity;
      for (const p of pts) { const dd = (x(p[0]) - mx) ** 2 + (y(p[1]) - my) ** 2; if (dd < bd) { bd = dd; best = p; } }
      if (!best || bd > 900) { mark.setAttribute('visibility', 'hidden'); stip.hidden = true; return; }
      mark.setAttribute('cx', x(best[0])); mark.setAttribute('cy', y(best[1])); mark.setAttribute('visibility', 'visible');
      stip.replaceChildren(el('div', { class: 'hz-tip-head', text: when(best[2]) }),
        el('div', { class: 'hz-tip-row' }, el('i', { class: 'hz-key-line hz-s2', 'aria-hidden': 'true' }), el('b', { text: fmt(best[0], best[0] % 1 ? 1 : 0) }), el('span', { text: 'players (mean)' })),
        el('div', { class: 'hz-tip-row' }, el('i', { class: 'hz-key-line hz-s1', 'aria-hidden': 'true' }), el('b', { text: cpu ? `${fmt(best[1], 1)}%` : fmt(best[1], 2) }), el('span', { text: cpu ? 'CPU' : 'load per core' })));
      stip.hidden = false;
      const box = scatterBox.getBoundingClientRect();
      let left = e.clientX - box.left + 14;
      if (left + stip.offsetWidth > box.width - 4) left = e.clientX - box.left - stip.offsetWidth - 14;
      stip.style.left = `${Math.max(4, left)}px`;
      stip.style.top = `${Math.max(4, e.clientY - box.top - stip.offsetHeight - 10)}px`;
    });
    hit.addEventListener('pointerleave', () => { mark.setAttribute('visibility', 'hidden'); stip.hidden = true; });
    scatterBox.append(el('p', { class: 'hz-axis-y', text: cpu ? 'CPU, whole machine' : 'Load per core' }), svg, stip);
  }

  // —— The table ——
  function drawTable() {
    tableBody.replaceChildren();
    const rows = state.rows.slice(-500).reverse();
    for (const r of rows) {
      const cells = [when(r.t), fmt(r.p), fmt(r.s), fmt(r.c, 1), fmt(r.cx, 1), fmt(r.lpc, 2), fmt(r.memp, 0), bytes(r.nis, true), bytes(r.nos, true), fmt(r.mi), fmt(r.mo), fmt(r.lag, 1)];
      tableBody.append(el('tr', {}, ...cells.map((c, i) => el('td', { class: i ? 'console-num' : '', text: c }))));
    }
  }

  // —— Loading ——
  function draw() {
    const d = state.data;
    for (const btn of rangeButtons) btn.setAttribute('aria-pressed', String(btn.dataset.hzRange === state.range));
    empty.hidden = true;
    if (!d) return;
    if (d.server === 'down') {
      status.textContent = 'The realtime server did not answer.';
      empty.hidden = false;
      empty.textContent = 'The history lives on the realtime server, so there is nothing to show until it answers again.';
    } else if (d.server === 'off') {
      status.textContent = 'This server keeps no health samples.';
      empty.hidden = false;
      empty.textContent = 'Start it with -health-dir (or leave it at its default, health/ beside the secret) to keep a sample a minute.';
    } else {
      status.textContent = d.now ? `Sampled ${when(d.now.t)}` : 'No sample yet';
      if (!state.rows.length) {
        empty.hidden = false;
        empty.textContent = d.first ? 'No samples in this range. Try All.' : 'No samples yet. The server writes one a minute, the first within a minute of a start.';
      }
    }
    drawNow();
    drawCharts();
    drawScatter();
    if (details.open) drawTable();
  }
  async function load() {
    if (state.loading) return;
    state.loading = true;
    panel.classList.add('hz-loading');
    try {
      const d = await api.call(`admin/health?range=${encodeURIComponent(state.range)}`);
      state.data = d;
      state.rows = d.server === 'up' ? rowsOf(d.fields, d.samples).map(derive) : [];
    } catch (err) {
      state.data = { server: 'down' };
      state.rows = [];
      status.textContent = err?.status === 401 ? 'Signed out. Reload the page to sign in.' : (err?.message || 'That did not work.');
    } finally {
      state.loading = false;
      panel.classList.remove('hz-loading');
    }
    draw();
  }
  function setRange(id) {
    if (state.range === id && state.data) return;
    state.range = id;
    try { localStorage.setItem('groddle.console.health.range', id); } catch { /* a private window: the default range */ }
    load();
  }
  try { const saved = localStorage.getItem('groddle.console.health.range'); if (RANGES.some(([id]) => id === saved)) state.range = saved; } catch { /* no storage */ }

  // Redrawn to the pane's width; refreshed every minute while the tab is open (a sample a minute).
  let lastWidth = 0;
  new ResizeObserver(() => {
    const w = Math.round(panel.clientWidth);
    if (state.data && !panel.hidden && Math.abs(w - lastWidth) > 4) { lastWidth = w; drawCharts(); drawScatter(); }
  }).observe(panel);
  const tick = () => { if (!panel.hidden && !document.hidden) load(); };

  const tab = document.querySelector('[data-console-tab="server"]');
  let loaded = false;
  const maybe = () => {
    if (panel.hidden) { clearInterval(state.timer); state.timer = 0; return; }
    if (!loaded) { loaded = true; load(); } else if (state.data) { lastWidth = Math.round(panel.clientWidth); drawCharts(); drawScatter(); }
    if (!state.timer) state.timer = setInterval(tick, 60000);
  };
  tab?.addEventListener('click', () => {
    setTimeout(maybe, 0);
    if (location.hash !== '#server') history.replaceState(null, '', '#server');
  });
  new MutationObserver(maybe).observe(panel, { attributes: true, attributeFilter: ['hidden'] });
  if (location.hash === '#server') tab?.click();
  addEventListener('hashchange', () => { if (location.hash === '#server' && panel.hidden) tab?.click(); });
  maybe();
}

if (typeof document !== 'undefined') {
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
}
