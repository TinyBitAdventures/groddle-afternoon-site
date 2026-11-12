/**
 * The console's Analytics tab (src/AdminConsole.php analytics, src/Analytics.php; Austin 2026-11-11: "Similar to the
 * new /console/ server tab, could we do one for Analytics? ... players over time, active players per day, pageviews
 * and even quirky things like trees harvested, mines mined, piggies nibbled, butterflies milked").
 *
 * - Today: players, active today, this week and this month, pageviews and play time today.
 * - Over time, on one time axis for every chart (30, 90 or 365 days, All; a bar a day, a week past 120 days, a month
 *   past two years): players and new players, active players with the week's and month's, new and returning, the
 *   most online at once, play time and pageviews. Hovering any chart moves one crosshair through them all and one
 *   tooltip lists every value of that day (or week, or month).
 * - Pages: the sections of the website read most in the range.
 * - Around Ur: the world's tallies from the realtime server (Piggies nibbled, Rocks mined …), each with today's
 *   number, its all-time total, a sparkline of the range and its best day.
 * - Records: the most online at once, the most active players, new players and pageviews in a day.
 *
 * Counts only, on UTC days: nothing here names a player. Drawn with the console's chart kit (console/charts.js, as
 * the Server tab) and server.css's chart styles; analytics.css adds the tallies' grid and the records.
 */

import { fmt, el, sv, createApi, lineChart, linkCharts } from './charts.js?v=charts-1';

/** admin/analytics's ranges, in the buttons' order. */
export const RANGES = Object.freeze([['30d', '30 days'], ['90d', '90 days'], ['365d', '365 days'], ['all', 'All']]);

const D = 86400;

/** PURE: a 'Y-m-d' day as unix seconds (its UTC midnight). */
export function dayTs(day) {
  const [y, m, d] = String(day).split('-').map(Number);
  return Date.UTC(y, m - 1, d) / 1000;
}

/** PURE: the first second after a bucket that starts on day (unit day, week or month). */
export function bucketEnd(day, unit) {
  const t = dayTs(day);
  if (unit === 'week') return t + 7 * D;
  if (unit === 'month') { const d = new Date(t * 1000); return Date.UTC(d.getUTCFullYear(), d.getUTCMonth() + 1, 1) / 1000; }
  return t + D;
}

/** PURE: a day in words, UTC ("Nov 9"; the year too when it is not this year's). */
export function dayWords(day, withYear = null) {
  const t = typeof day === 'number' ? day : dayTs(day);
  const d = new Date(t * 1000);
  const year = withYear ?? d.getUTCFullYear() !== new Date().getUTCFullYear();
  return d.toLocaleDateString([], { month: 'short', day: 'numeric', ...(year ? { year: 'numeric' } : {}), timeZone: 'UTC' });
}

/** PURE: a bucket in words: a day, "Week of Nov 3", "November 2026". */
export function bucketWords(day, unit) {
  if (unit === 'week') return `Week of ${dayWords(day)}`;
  if (unit === 'month') return new Date(dayTs(day) * 1000).toLocaleDateString([], { month: 'long', year: 'numeric', timeZone: 'UTC' });
  return new Date(dayTs(day) * 1000).toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' });
}

/** PURE: seconds of play as "12 min", "3.4 h", "1,204 h". */
export function playTime(s) {
  if (s == null) return '–';
  if (s < 3600) return `${fmt(Math.round(s / 60))} min`;
  const h = s / 3600;
  return `${fmt(h, h < 100 ? 1 : 0)} h`;
}

/** PURE: a section as the page's address ('home' is /). */
export function sectionPath(section) {
  if (section === 'home') return '/';
  if (section === 'other') return 'Other addresses';
  return `/${section}/`;
}

/** PURE: UTC ticks between a and b (unix seconds), at most count: days, Mondays, every other Monday, or months. */
export function utcTicks(a, b, count) {
  const span = b - a;
  const step = [1, 2, 7, 14].find((s) => span / (s * D) <= count);
  const out = [];
  if (step) {
    let t = Math.ceil(a / D) * D;
    if (step >= 7) while (new Date(t * 1000).getUTCDay() !== 1) t += D; // Mondays
    for (; t <= b; t += step * D) out.push(t);
    return { ticks: out, unit: 'day' };
  }
  const months = [1, 2, 3, 6, 12].find((m) => span / (m * 30 * D) <= count) || 12;
  const d = new Date(a * 1000);
  let y = d.getUTCFullYear(); let m = d.getUTCMonth();
  if (Date.UTC(y, m, 1) / 1000 < a) m += 1;
  for (;;) {
    const at = Date.UTC(y, m, 1);
    const mm = new Date(at).getUTCMonth();
    if (at / 1000 > b) break;
    if (mm % months === 0) out.push(at / 1000);
    m += 1;
  }
  return { ticks: out, unit: 'month' };
}

/** A UTC tick's label. */
export function utcLabel(t, unit) {
  return new Date(t * 1000).toLocaleDateString([], unit === 'month' ? { month: 'short', year: '2-digit', timeZone: 'UTC' } : { month: 'short', day: 'numeric', timeZone: 'UTC' });
}

/** PURE: admin/analytics's buckets as rows {t, t2, day, …each series}. */
export function rowsOf(d) {
  return (d.days || []).map((day, i) => {
    const r = { t: dayTs(day), t2: bucketEnd(day, d.unit), day };
    for (const [k, list] of Object.entries(d.series || {})) r[k] = list?.[i] ?? null;
    r.play_hours = r.play_seconds == null ? null : r.play_seconds / 3600;
    return r;
  });
}

function boot() {
  const panel = document.querySelector('[data-console-tabpanel="analytics"]');
  if (!panel || panel.querySelector('[data-an-today]')) return;
  const api = createApi();
  const state = { range: '30d', data: null, rows: [], loading: false, timer: 0 };

  // —— The frame ——
  const status = el('p', { class: 'console-sub hz-status', role: 'status', 'aria-live': 'polite', 'data-an-status': '' });
  const intro = el('p', { class: 'console-empty an-intro', hidden: true, 'data-an-intro': '' });
  const tiles = el('div', { class: 'hz-tiles', 'data-an-today': '' });
  const todaySection = el('section', { class: 'console-panel', 'aria-labelledby': 'console-an-today-title' },
    el('div', { class: 'console-panel-head' }, el('h2', { id: 'console-an-today-title' }, 'Today'), status),
    intro, tiles,
    el('p', { class: 'console-hint an-privacy', text: 'Counts only, on UTC days. Players leave out administrators and test accounts. Nothing here names a player.' }));

  const rangeButtons = RANGES.map(([id, label]) => el('button', { type: 'button', class: 'console-small', 'data-an-range': id, 'aria-pressed': String(id === state.range), onclick: () => setRange(id) }, label));
  const span = el('p', { class: 'console-hint hz-span', 'data-an-span': '' });
  const notes = el('ul', { class: 'an-notes console-hint', 'data-an-notes': '' });
  const charts = el('div', { class: 'hz-charts', 'data-an-charts': '' });
  const tip = el('div', { class: 'hz-tip', hidden: true, role: 'status', 'aria-live': 'off' });
  const overTime = el('section', { class: 'console-panel hz-over', 'aria-labelledby': 'console-an-time-title' },
    el('div', { class: 'console-panel-head' }, el('h2', { id: 'console-an-time-title' }, 'Over time'),
      el('p', { class: 'console-hint' }, 'Hover or use the arrow keys on a chart to read every value of that day.')),
    el('div', { class: 'hz-ranges', role: 'group', 'aria-label': 'Time range' }, ...rangeButtons), span, notes, charts, tip);

  const pagesBody = el('tbody');
  const pagesEmpty = el('p', { class: 'console-empty', hidden: true });
  const pagesTable = el('table', { class: 'console-table an-pages', 'data-an-pages': '' },
    el('thead', {}, el('tr', {}, ...['Section', 'Pageviews', 'Share', 'Today'].map((h, i) => el('th', { scope: 'col', class: i ? 'console-num' : '', text: h })))), pagesBody);
  const pagesSection = el('section', { class: 'console-panel', 'aria-labelledby': 'console-an-pages-title' },
    el('div', { class: 'console-panel-head' }, el('h2', { id: 'console-an-pages-title' }, 'Pages read most'),
      el('p', { class: 'console-hint', 'data-an-pages-hint': '' })), pagesEmpty, el('div', { class: 'an-table-wrap' }, pagesTable));

  const urHint = el('p', { class: 'console-hint', 'data-an-ur-hint': '' });
  const urEmpty = el('p', { class: 'console-empty', hidden: true });
  const urGrid = el('ul', { class: 'an-ur', 'data-an-ur': '' });
  const urSection = el('section', { class: 'console-panel', 'aria-labelledby': 'console-an-ur-title' },
    el('div', { class: 'console-panel-head' }, el('h2', { id: 'console-an-ur-title' }, 'Around Ur'), urHint), urEmpty, urGrid);

  const recordList = el('dl', { class: 'an-records', 'data-an-records': '' });
  const recordSection = el('section', { class: 'console-panel', 'aria-labelledby': 'console-an-records-title' },
    el('div', { class: 'console-panel-head' }, el('h2', { id: 'console-an-records-title' }, 'Records'),
      el('p', { class: 'console-hint', text: 'Best days of all time.' })), recordList);

  panel.append(todaySection, overTime, pagesSection, urSection, recordSection);

  // —— Today ——
  function tile(label, value, sub) {
    return el('div', { class: 'hz-tile' }, el('span', { class: 'hz-tile-label', text: label }), el('span', { class: 'hz-tile-value', text: value }), el('span', { class: 'hz-tile-sub', text: sub }));
  }
  function drawToday() {
    const d = state.data;
    tiles.replaceChildren();
    intro.hidden = true;
    if (!d?.totals) return;
    const t = d.totals;
    const f = d.first || {};
    const counting = (first) => (first ? `counting since ${dayWords(first)}` : 'nothing counted yet');
    tiles.append(
      tile('Players', fmt(t.players), t.signups_today ? `${fmt(t.signups_today)} new today` : 'characters made, all time'),
      tile('Logins', fmt(d.logins?.total ?? t.players), f.active ? `${fmt(d.logins?.active_today ?? 0)} played today` : 'one email and password each'),
      tile('Active today', f.active ? fmt(t.active_today) : '–', f.active ? 'played today' : counting(f.active)),
      tile('Active this week', f.active ? fmt(t.active_week) : '–', f.active ? 'in the last 7 days' : counting(f.active)),
      tile('Active this month', f.active ? fmt(t.active_month) : '–', f.active ? 'in the last 30 days' : counting(f.active)),
      tile('Pageviews today', f.pageviews ? fmt(t.pageviews_today) : '–', f.pageviews ? `${fmt(t.pageviews_range)} in this range` : counting(f.pageviews)),
      tile('Play time today', f.active ? playTime(t.play_today) : '–', f.active ? (t.peak_today != null ? `${fmt(t.peak_today)} online at most` : 'all players together') : counting(f.active)),
    );
    if (!f.players && !f.active && !f.pageviews && !f.tally && !f.peak) {
      intro.hidden = false;
      intro.textContent = 'Nothing has been counted yet. Players come from the characters as they sign up. Active players and play time start with the realtime server\'s next play-time batch, pageviews with the next page read on the website, and the tallies Around Ur once a realtime server that sends them is running.';
    }
  }

  // —— Over time ——
  const unitWords = { day: 'a bar a day', week: 'a bar a week', month: 'a bar a month' };
  function specs(unit) {
    const per = unit === 'day' ? 'a day' : `a ${unit}`;
    return [
      { id: 'players', title: 'Players', note: 'all characters made by the end of each day', series: [{ key: 'players', label: 'Players', cls: 'hz-s2', area: true }], int: true },
      { id: 'signups', title: 'New players', note: `characters made ${per}`, series: [{ key: 'signups', label: 'New players', cls: 'hz-s2', bars: true }], int: true },
      { id: 'active', title: 'Active players', note: unit === 'day' ? 'who played that day, and in the 7 and 30 days to it' : `the mean a day in each ${unit}, and the 7 and 30 days to its last day`, series: [{ key: 'active', label: 'That day', cls: 'hz-s2' }, { key: 'wau', label: '7 days', cls: 'hz-s1' }, { key: 'mau', label: '30 days', cls: 'hz-s3' }], int: true },
      { id: 'newret', title: 'New and returning', note: `players who played, ${per}`, series: [{ key: 'returning', label: 'Returning', cls: 'hz-s1', bars: true, stack: true }, { key: 'active_new', label: 'New that day', cls: 'hz-s2', bars: true, stack: true }], int: true },
      { id: 'peak', title: 'Most online at once', note: `everyone in the game, the most ${per}`, series: [{ key: 'peak', label: 'Online', cls: 'hz-s2', bars: true }], int: true },
      { id: 'play', title: 'Play time', note: `hours of active play ${per}, all players together`, series: [{ key: 'play_hours', label: 'Hours', cls: 'hz-s1', bars: true }], tick: (v) => `${fmt(v, v < 10 && v % 1 ? 1 : 0)} h` },
      { id: 'pageviews', title: 'Pageviews', note: `pages of the website read ${per}`, series: [{ key: 'pageviews', label: 'Pageviews', cls: 'hz-s1', bars: true }], int: true },
    ];
  }
  function tipAt(r) {
    const d = state.data;
    const dash = (v, f = fmt) => (v == null ? '–' : f(v));
    const lines = [
      [dash(r.players), 'players', 'hz-s2'],
      [dash(r.signups), 'new players', ''],
      [dash(r.active, (v) => fmt(v, v % 1 ? 1 : 0)), d.unit === 'day' ? 'active that day' : 'active a day (mean)', 'hz-s2'],
      [`${dash(r.wau)} / ${dash(r.mau)}`, 'active in 7 / 30 days', 'hz-s1'],
      [`${dash(r.active_new)} / ${dash(r.returning)}`, 'new / returning', ''],
      [dash(r.peak), 'most online at once', ''],
      [playTime(r.play_seconds), 'play time', ''],
      [dash(r.pageviews), 'pageviews', ''],
    ];
    return { head: bucketWords(r.day, d.unit), lines };
  }
  let linked = { hide() {} };
  function drawCharts() {
    const d = state.data;
    charts.replaceChildren();
    notes.replaceChildren();
    linked.hide();
    linked = { hide() {} };
    if (!d?.days?.length) return;
    const rows = state.rows;
    const a = rows[0].t;
    const b = rows[rows.length - 1].t2;
    span.textContent = `${dayWords(d.since)} to ${dayWords(d.today)} · ${unitWords[d.unit]}${d.unit === 'day' ? '' : `, the last one the ${d.unit} so far`} · UTC days`;
    const f = d.first || {};
    const note = (first, what) => {
      if (!first) notes.append(el('li', { text: `${what}: nothing counted yet.` }));
      else if (first > d.since) notes.append(el('li', { text: `${what}: counted since ${dayWords(first)}.` }));
    };
    note(f.active, 'Active players and play time');
    note(f.peak, 'Most online at once (from the realtime server\'s health samples)');
    note(f.pageviews, 'Pageviews');
    if (d.server === 'down') notes.append(el('li', { text: 'The realtime server did not answer, so today\'s most online may be behind.' }));
    notes.hidden = !notes.children.length;
    const width = Math.max(280, Math.floor(charts.clientWidth || overTime.clientWidth - 28));
    const step = d.unit === 'week' ? 7 * D : (d.unit === 'month' ? 30 * D : D);
    const opts = { step, ticks: utcTicks, label: utcLabel, empty: 'Nothing counted in this range.', span: `${dayWords(d.since)} to ${dayWords(d.today)}` };
    const views = specs(d.unit).map((spec) => lineChart(spec, rows, [a, b], width, opts));
    charts.append(...views.map((v) => v.box));
    linked = linkCharts(views, rows, { tip, frame: overTime, content: tipAt });
  }

  // —— Pages ——
  function drawPages() {
    const d = state.data;
    pagesBody.replaceChildren();
    const pages = d?.pages || [];
    const total = d?.totals?.pageviews_range || 0;
    pagesSection.querySelector('[data-an-pages-hint]').textContent = d ? `${dayWords(d.since)} to ${dayWords(d.today)}, by the first folder of the address` : '';
    pagesEmpty.hidden = pages.length > 0;
    pagesEmpty.textContent = d?.first?.pageviews ? 'No pageviews in this range.' : 'No pageviews counted yet. The website\'s pages count themselves as they are read.';
    pagesTable.hidden = !pages.length;
    for (const p of pages) {
      pagesBody.append(el('tr', {},
        // A break may come after a slash, never inside a folder's name.
        el('td', {}, ...sectionPath(p.section).split(/(?<=\/)/).flatMap((part, i) => (i ? [el('wbr'), part] : [part]))),
        el('td', { class: 'console-num', text: fmt(p.views) }),
        el('td', { class: 'console-num', text: total ? `${fmt((p.views / total) * 100, p.views / total < 0.1 ? 1 : 0)}%` : '–' }),
        el('td', { class: 'console-num', text: fmt(p.today) })));
    }
  }

  // —— Around Ur ——
  function spark(series, label) {
    const W = 120; const H = 28;
    const vals = series.filter((v) => v != null);
    const svg = sv('svg', { viewBox: `0 0 ${W} ${H}`, preserveAspectRatio: 'none', class: 'an-spark', role: 'img', 'aria-label': label });
    if (!vals.length) return svg;
    const top = Math.max(1, ...vals);
    const n = series.length;
    const x = (i) => (n === 1 ? W / 2 : (i / (n - 1)) * W);
    const y = (v) => H - 2 - (v / top) * (H - 4);
    let line = ''; let area = ''; let run = [];
    const flush = () => {
      if (run.length) {
        line += run.map(([i, v], k) => `${k ? 'L' : 'M'}${x(i)},${y(v)}`).join('');
        area += `M${x(run[0][0])},${H}` + run.map(([i, v]) => `L${x(i)},${y(v)}`).join('') + `L${x(run[run.length - 1][0])},${H}Z`;
      }
      run = [];
    };
    series.forEach((v, i) => { if (v == null) flush(); else run.push([i, v]); });
    flush();
    svg.append(sv('path', { d: area, class: 'hz-area hz-s1' }), sv('path', { d: line, class: 'hz-line hz-s1 an-spark-line' }));
    return svg;
  }
  function drawUr() {
    const d = state.data;
    urGrid.replaceChildren();
    const first = d?.first?.tally;
    urHint.textContent = first ? `What everyone did on the world's shared streets, counted by the realtime server since ${dayWords(first)}. Today, all time, the range, and the best day.` : 'What everyone did on the world\'s shared streets, counted by the realtime server.';
    urEmpty.hidden = Boolean(first);
    urEmpty.textContent = 'No tallies yet. They start once a realtime server that counts them is running and sends its next play-time batch.';
    if (!d?.counters || !first) return; // nothing tallied yet: the words above, not two dozen empty tiles
    const counters = [...d.counters].sort((p, q) => q.total - p.total || p.label.localeCompare(q.label));
    for (const c of counters) {
      urGrid.append(el('li', { class: 'an-ur-tile', 'data-an-counter': c.key },
        el('span', { class: 'hz-tile-label', text: c.label }),
        el('span', { class: 'an-ur-today' }, el('b', { text: fmt(c.today) }), el('span', { text: ' today' })),
        spark(c.series || [], `${c.label}, ${dayWords(d.since)} to ${dayWords(d.today)}`),
        el('span', { class: 'hz-tile-sub', text: `${fmt(c.total)} all time` }),
        el('span', { class: 'hz-tile-sub', text: c.record ? `Best day: ${fmt(c.record.n)} on ${dayWords(c.record.day)}` : 'No best day yet' })));
    }
  }

  // —— Records ——
  function drawRecords() {
    const r = state.data?.records || {};
    recordList.replaceChildren();
    const at = (t) => new Date(t * 1000).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    const rows = [
      ['Most online at once', r.peak, (x) => `${fmt(x.n)} on ${dayWords(x.day)}${x.at ? ` at about ${at(x.at)}` : ''}`],
      ['Most active players in a day', r.active, (x) => `${fmt(x.n)} on ${dayWords(x.day)}`],
      ['Most new players in a day', r.signups, (x) => `${fmt(x.n)} on ${dayWords(x.day)}`],
      ['Most pageviews in a day', r.pageviews, (x) => `${fmt(x.n)} on ${dayWords(x.day)}`],
    ];
    const best = (state.data?.counters || []).filter((c) => c.record).sort((p, q) => q.record.n - p.record.n).slice(0, 3);
    for (const c of best) rows.push([`Most ${c.label.toLowerCase()} in a day`, c.record, (x) => `${fmt(x.n)} on ${dayWords(x.day)}`]);
    for (const [label, rec, words] of rows) {
      recordList.append(el('div', { class: 'an-record' }, el('dt', { text: label }), el('dd', { text: rec ? words(rec) : 'Not yet' })));
    }
  }

  // —— Loading ——
  function draw() {
    const d = state.data;
    for (const btn of rangeButtons) btn.setAttribute('aria-pressed', String(btn.dataset.anRange === state.range));
    if (!d) return;
    if (d.failed) {
      tiles.replaceChildren(); charts.replaceChildren(); notes.replaceChildren(); pagesBody.replaceChildren(); urGrid.replaceChildren(); recordList.replaceChildren();
      return;
    }
    status.textContent = `Updated ${new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })} · today is ${dayWords(d.today, false)} (UTC)`;
    drawToday();
    drawCharts();
    drawPages();
    drawUr();
    drawRecords();
  }
  async function load() {
    if (state.loading) return;
    state.loading = true;
    panel.classList.add('hz-loading');
    try {
      const d = await api.call(`admin/analytics?range=${encodeURIComponent(state.range)}`);
      state.data = d;
      state.rows = rowsOf(d);
    } catch (err) {
      state.data = { failed: true };
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
    try { localStorage.setItem('groddle.console.analytics.range', id); } catch { /* a private window: the default range */ }
    load();
  }
  try { const saved = localStorage.getItem('groddle.console.analytics.range'); if (RANGES.some(([id]) => id === saved)) state.range = saved; } catch { /* no storage */ }

  // Redrawn to the pane's width; refreshed every five minutes while the tab is open.
  let lastWidth = 0;
  new ResizeObserver(() => {
    const w = Math.round(panel.clientWidth);
    if (state.data && !panel.hidden && Math.abs(w - lastWidth) > 4) { lastWidth = w; drawCharts(); }
  }).observe(panel);
  const tick = () => { if (!panel.hidden && !document.hidden) load(); };

  const tab = document.querySelector('[data-console-tab="analytics"]');
  let loaded = false;
  const maybe = () => {
    if (panel.hidden) { clearInterval(state.timer); state.timer = 0; return; }
    if (!loaded) { loaded = true; load(); } else if (state.data) { lastWidth = Math.round(panel.clientWidth); drawCharts(); }
    if (!state.timer) state.timer = setInterval(tick, 300000);
  };
  tab?.addEventListener('click', () => {
    setTimeout(maybe, 0);
    if (location.hash !== '#analytics') history.replaceState(null, '', '#analytics');
  });
  new MutationObserver(maybe).observe(panel, { attributes: true, attributeFilter: ['hidden'] });
  if (location.hash === '#analytics') tab?.click();
  addEventListener('hashchange', () => { if (location.hash === '#analytics' && panel.hidden) tab?.click(); });
  maybe();
}

if (typeof document !== 'undefined') {
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
}
