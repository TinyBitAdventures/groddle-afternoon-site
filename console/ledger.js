/**
 * The console's Ledger tab (G335 E0, src/LedgerWatch.php over GET admin/ledger; glitch-audit research/coverage/
 * g335-slice2-plan.md section 3). A watch, read only: per character per UTC day, what the ledger credited by kind,
 * the budget's refill, clamps and the value sent to other characters, with a plain flag for anything past the honest
 * maxima. Flagged characters come first and open; Everyone shows the rest. #ledger opens it.
 */

import { fmt, el, createApi } from './charts.js?v=charts-1';

/** admin/ledger's ranges (days), in the buttons' order. */
export const RANGES = Object.freeze([[1, 'Today'], [7, '7 days'], [14, '14 days']]);

/** PURE: a 'Y-m-d' UTC day in words ("Fri, Nov 13"). */
export function dayWords(day) {
  const [y, m, d] = String(day).split('-').map(Number);
  return new Date(Date.UTC(y, m - 1, d)).toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric', timeZone: 'UTC' });
}

/** PURE: seconds connected as hours ("6.0 h", "–"). */
export function hours(s) {
  return s > 0 ? `${fmt(s / 3600, 1)} h` : '–';
}

/** PURE: a day's cells, in the table's column order. */
export function dayCells(x) {
  const c = x.credits;
  const o = x.outbound;
  const out = (k) => `${k}: gave ${fmt(o[k].gave)}, got ${fmt(o[k].got)}`;
  return [
    { text: dayWords(x.day) },
    { text: c.quests.n ? `${fmt(c.quests.n)} · ${fmt(c.quests.currants)}` : '–', num: true, title: c.quests.n ? `${c.quests.n} quests, ${c.quests.currants} currants; at most ${c.quests.write} in one save` : '' },
    { text: c.badges.n ? `${fmt(c.badges.n)} · ${fmt(c.badges.currants)}` : '–', num: true },
    { text: c.levels.gained ? `+${fmt(c.levels.gained)} · ${fmt(c.levels.currants)}` : '–', num: true, title: c.levels.gained ? `${c.levels.gained} levels (${c.levels.paid} paid), at most ${c.levels.jump} in one save` : '' },
    { text: c.cards.n ? `${fmt(c.cards.n)} · ${fmt(c.cards.value)}` : '–', num: true },
    { text: c.server.value ? fmt(c.server.value) : '–', num: true, title: c.server.n ? `${c.server.n} receipts from the realtime server` : '' },
    { text: x.budget.refill ? `+${fmt(x.budget.refill)} · ${hours(x.budget.online_s)}` : '–', num: true, title: x.budget.late ? `${fmt(x.budget.late)} given back for late receipts` : '' },
    { text: x.clamps.n ? `${fmt(x.clamps.n)} · ${fmt(x.clamps.value)}` : '–', num: true },
    { text: o.net ? fmt(o.net) : '–', num: true, title: ['trade', 'mail', 'auction'].map(out).join('; ') },
  ];
}

/** The table's columns: a heading and what it holds. */
const COLUMNS = [
  ['Day', ''], ['Quests', 'count and currants'], ['Badges', 'count and currants'], ['Levels', 'gained and currants paid'],
  ['Cards', 'items and their value'], ['Server', 'receipts, value'], ['Budget', 'refilled and hours connected'],
  ['Clamps', 'times and value taken back'], ['Sent out', 'net value to other characters'],
];

function boot() {
  const panel = document.querySelector('[data-console-tabpanel="ledger"]');
  if (!panel || panel.querySelector('[data-lw-list]')) return;
  const api = createApi();
  const state = { days: 7, show: 'flagged', data: null, loading: false };

  const status = el('p', { class: 'console-sub hz-status', role: 'status', 'aria-live': 'polite', 'data-lw-status': '' });
  const rangeButtons = RANGES.map(([n, label]) => el('button', { type: 'button', class: 'console-small', 'data-lw-days': String(n), 'aria-pressed': String(n === state.days), onclick: () => setDays(n) }, label));
  const showButtons = [['flagged', 'Flagged'], ['all', 'Everyone']].map(([id, label]) => el('button', { type: 'button', 'data-lw-show': id, 'aria-pressed': String(id === state.show), onclick: () => { state.show = id; draw(); } }, label));
  const maxima = el('ul', { class: 'lw-maxima', 'data-lw-maxima': '' });
  const list = el('div', { class: 'lw-list', 'data-lw-list': '' });
  const empty = el('p', { class: 'console-empty', hidden: true, 'data-lw-empty': '' });
  panel.append(el('section', { class: 'console-panel lw-panel', 'aria-labelledby': 'console-lw-title' },
    el('div', { class: 'console-panel-head' },
      el('h2', { id: 'console-lw-title' }, 'Ledger watch ', el('span', { class: 'console-count', 'data-lw-count': '' })),
      status),
    el('p', { class: 'console-hint' }, 'What each character\'s ledger credited, clamped and sent to other characters, a UTC day at a time. A flag marks something past honest play\'s maxima: worth a look, not proof. Values are in currants at the economy table\'s prices. Nothing here changes a ledger.'),
    el('div', { class: 'hz-ranges', role: 'group', 'aria-label': 'Days' }, ...rangeButtons),
    el('div', { class: 'console-filter', role: 'group', 'aria-label': 'Show' }, ...showButtons),
    el('details', { class: 'lw-maxima-box' }, el('summary', {}, 'Honest maxima'), maxima,
      el('p', { class: 'console-hint', text: 'Estimates until a copy of the live tables measures them. The budget drawn is not logged per save, so a range shows what it drew at least: refilled and given back, less the budget now.' })),
    empty, list));

  function flagList(flags) {
    return el('ul', { class: 'lw-flags' }, ...flags.map((f) => el('li', { 'data-lw-flag': f.kind, text: f.text })));
  }

  function character(c) {
    const head = el('summary', { class: 'lw-summary' },
      el('span', { class: 'lw-name', text: c.player.name }),
      el('span', { class: 'console-sub', text: `#${c.player.id}` }),
      el('span', { class: c.flagged ? 'console-chip lw-chip-flag' : 'console-chip', text: c.flagged ? `${c.flagged} ${c.flagged === 1 ? 'flag' : 'flags'}` : 'No flags' }),
      el('span', { class: 'console-sub lw-sums', text: [
        `credited ${fmt(c.value)}`,
        c.budget == null ? null : `budget now ${fmt(c.budget)}`,
        c.drawn == null ? null : `drawn at least ${fmt(c.drawn)}`,
        c.online_s ? `connected ${hours(c.online_s)}` : null,
        c.created ? `made ${dayWords(c.created)}` : null,
      ].filter(Boolean).join(' · ') }));
    const body = el('tbody');
    for (const x of c.days) {
      const tr = el('tr', { 'data-lw-day': x.day, class: x.flags.length ? 'lw-flagged' : null });
      for (const cell of dayCells(x)) tr.append(el('td', { class: cell.num ? 'console-num' : null, title: cell.title || null, text: cell.text }));
      body.append(tr);
      if (x.flags.length) body.append(el('tr', { class: 'lw-flagrow' }, el('td', { colspan: String(COLUMNS.length) }, flagList(x.flags))));
    }
    const table = el('table', { class: 'console-table lw-table' },
      el('thead', {}, el('tr', {}, ...COLUMNS.map(([h, t], i) => el('th', { scope: 'col', class: i ? 'console-num' : null, title: t || null, text: h })))), body);
    return el('details', { class: 'lw-char', 'data-lw-player': String(c.player.id), open: c.flagged > 0 }, head,
      c.flags.length ? flagList(c.flags) : null,
      el('div', { class: 'lw-table-wrap' }, table));
  }

  function draw() {
    for (const b of rangeButtons) b.setAttribute('aria-pressed', String(Number(b.dataset.lwDays) === state.days));
    for (const b of showButtons) b.setAttribute('aria-pressed', String(b.dataset.lwShow === state.show));
    const d = state.data;
    list.replaceChildren();
    if (!d || d.failed) return;
    maxima.replaceChildren(...(d.maxima || []).map((m) => el('li', {}, el('b', { text: fmt(m.n, 1) }), ` ${m.words}`)));
    const chars = d.characters || [];
    const flagged = chars.filter((c) => c.flagged > 0);
    const shown = state.show === 'flagged' ? flagged : chars;
    panel.querySelector('[data-lw-count]').textContent = flagged.length ? String(flagged.length) : '';
    status.textContent = `${fmt(chars.length)} ${chars.length === 1 ? 'character' : 'characters'} with ledger activity since ${dayWords(d.since)} (UTC), ${fmt(flagged.length)} flagged${d.truncated ? '. The range was too big to read whole: choose fewer days.' : ''}`;
    empty.hidden = shown.length > 0;
    empty.textContent = chars.length ? 'Nobody is past the honest maxima in this range.' : 'No ledger activity in this range.';
    list.append(...shown.map(character));
  }

  async function load() {
    if (state.loading) return;
    state.loading = true;
    panel.classList.add('hz-loading');
    try {
      state.data = await api.call(`admin/ledger?days=${state.days}`);
    } catch (err) {
      state.data = { failed: true };
      status.textContent = err?.status === 401 ? 'Signed out. Reload the page to sign in.' : (err?.message || 'That did not work.');
    } finally {
      state.loading = false;
      panel.classList.remove('hz-loading');
    }
    draw();
  }
  function setDays(n) {
    if (state.days === n && state.data) return;
    state.days = n;
    try { localStorage.setItem('groddle.console.ledger.days', String(n)); } catch { /* a private window: the default */ }
    load();
  }
  try { const saved = Number(localStorage.getItem('groddle.console.ledger.days')); if (RANGES.some(([n]) => n === saved)) state.days = saved; } catch { /* no storage */ }

  const tab = document.querySelector('[data-console-tab="ledger"]');
  let loaded = false;
  const maybe = () => { if (!panel.hidden && !loaded) { loaded = true; load(); } };
  tab?.addEventListener('click', () => {
    setTimeout(maybe, 0);
    if (location.hash !== '#ledger') history.replaceState(null, '', '#ledger');
  });
  new MutationObserver(maybe).observe(panel, { attributes: true, attributeFilter: ['hidden'] });
  if (location.hash === '#ledger') tab?.click();
  addEventListener('hashchange', () => { if (location.hash === '#ledger' && panel.hidden) tab?.click(); });
  maybe();
}

if (typeof document !== 'undefined') {
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
}
