/**
 * The console's Night sky panel (Austin 2026-11-27; src/AdminSky.php, the realtime server's meteor.go, the
 * game's js/meteor-shower.js): a meteor shower or a lone shooting star that every player online sees at once.
 * Ours: Glitch had neither, and it is never in a rotation: only these buttons start one.
 *
 * It sits on the Players tab, under Find a player: Ur's time now and how the clock's sky looks (the streaks show
 * where a street's sky follows the clock, at night, faintly at dusk and dawn, never by day), what is on in the
 * sky with the time left, and four buttons: Meteor shower now, Meteor shower in 2 minutes (with hint: every
 * player's activity feed says the sky feels restless first), Lone shooting star now, Stop shower. A shower asks
 * first. Each press is one line in the Activity log (kind meteor, console.js meteorLogWords).
 *
 * Its own module beside console.js, with its own REST nonce (as moderation.js). GET admin/sky every POLL_MS while
 * the Players tab shows; the clock line ticks each second from the last answer.
 */

/** How often the panel asks again while it shows. */
export const POLL_MS = 15000;

/** Ur's game minute in real ms (js/game-day.js GAME_MINUTE_MS). */
export const UR_MINUTE_MS = 10000;

/** The buttons: what each asks admin/meteor for, and whether it asks first. */
export const PRESSES = Object.freeze([
  Object.freeze({ key: 'now', label: 'Meteor shower now', body: Object.freeze({ op: 'shower', lead: 0 }), confirm: 'Start a meteor shower now for everyone online? It runs 3 to 4 minutes.' }),
  Object.freeze({ key: 'soon', label: 'Meteor shower in 2 minutes (with hint)', body: Object.freeze({ op: 'shower', lead: 120 }), confirm: 'Start a meteor shower in 2 minutes for everyone online? Their activity feeds get a hint now.' }),
  Object.freeze({ key: 'star', label: 'Lone shooting star now', body: Object.freeze({ op: 'star' }), confirm: '' }),
  Object.freeze({ key: 'stop', label: 'Stop shower', body: Object.freeze({ op: 'stop' }), confirm: '' }),
]);

/** PURE: how the clock's sky looks, in words (AdminSky::urClock sky). */
export function skyWords(sky) {
  return {
    night: 'Night: streaks show bright on every street whose sky follows the clock.',
    dusk: 'Dusk: streaks show only faintly.',
    dawn: 'Dawn: streaks show only faintly.',
    day: 'Day: nobody would see the streaks.',
  }[sky] || '';
}

/** PURE: m:ss for a span of ms (never under 0:00). */
export function clockSpan(ms) {
  const s = Math.max(0, Math.ceil((Number(ms) || 0) / 1000));
  return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
}

/** PURE: what is on in the sky at `now` (the server's clock): a line, and whether Stop has anything to stop. */
export function eventWords(event, now) {
  if (!event || !Number.isFinite(event.start) || !Number.isFinite(event.dur)) return { text: 'Nothing in the sky now.', on: false };
  const name = event.kind === 'star' ? 'A lone shooting star' : 'A meteor shower';
  if (now < event.start) return { text: `${name} starts in ${clockSpan(event.start - now)}${event.hint ? ' (the hint is out)' : ''}.`, on: true };
  const left = event.start + event.dur - now;
  if (left <= 0) return { text: 'Nothing in the sky now.', on: false };
  return { text: event.kind === 'star' ? `${name} is crossing the sky.` : `${name} is on: ${clockSpan(left)} left.`, on: true };
}

/** PURE: Ur's clock `ms` after an answer's: { time, sky } moved on by whole game minutes (sky as AdminSky::urClock). */
export function urClockAfter(ur, ms) {
  if (!ur || !Number.isFinite(ur.hour) || !Number.isFinite(ur.minute)) return null;
  const total = (ur.hour * 60 + ur.minute + Math.floor(Math.max(0, ms) / UR_MINUTE_MS)) % 1440;
  const hour = Math.floor(total / 60);
  const minute = total % 60;
  const h = hour + minute / 60;
  const sky = h < 4.75 || h >= 21.25 ? 'night' : h < 8.25 ? 'dawn' : h < 17.25 ? 'day' : 'dusk';
  const h12 = hour % 12 === 0 ? 12 : hour % 12;
  return { hour, minute, time: `${h12}:${String(minute).padStart(2, '0')} ${hour < 12 ? 'AM' : 'PM'}`, sky };
}

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
  }
  async function call(path, { method = 'GET', body } = {}, retried = false) {
    if (!nonce) await fresh();
    const r = await fetch(`/wp-json/groddle/v1/admin/${path}`, {
      method, credentials: 'same-origin', cache: 'no-store',
      headers: { 'X-WP-Nonce': nonce, ...(body ? { 'Content-Type': 'application/json' } : {}) },
      body: body ? JSON.stringify(body) : undefined,
    });
    const data = await r.json().catch(() => null);
    if (r.status === 403 && data?.code === 'rest_cookie_invalid_nonce' && !retried) {
      nonce = '';
      return call(path, { method, body }, true);
    }
    if (!r.ok) throw Object.assign(new Error(data?.message || 'That did not work.'), { status: r.status, code: data?.code });
    return data;
  }
  return { call };
}

function boot() {
  const panel = document.querySelector('[data-console-tabpanel="players"]');
  if (!panel || panel.querySelector('[data-console-sky]')) return;
  const api = createApi();
  const clock = el('p', { class: 'console-sub', 'data-console-sky-clock': '' }, 'Ur time: asking the server.');
  const sky = el('p', { class: 'console-sub', 'data-console-sky-look': '' });
  const now = el('p', { class: 'console-quote', 'data-console-sky-event': '' }, 'Nothing in the sky now.');
  const status = el('p', { class: 'console-sub', role: 'status', 'aria-live': 'polite', 'data-console-sky-status': '' });
  const buttons = new Map();
  const row = el('div', { class: 'console-reply-foot console-sky-buttons' });
  for (const p of PRESSES) {
    const b = el('button', { type: 'button', class: p.key === 'stop' ? '' : 'console-primary', 'data-console-sky-press': p.key }, p.label);
    b.addEventListener('click', () => press(p));
    buttons.set(p.key, b);
    row.append(b);
  }
  const section = el('section', { class: 'console-panel console-sky', 'aria-labelledby': 'console-sky-title', 'data-console-sky': '' },
    el('div', { class: 'console-panel-head' },
      el('h2', { id: 'console-sky-title' }, 'Night sky'),
      el('p', { class: 'console-hint' }, 'A meteor shower (3 to 4 minutes, a streak every 6 to 12 seconds) or one shooting star, for everyone online at once. Players can snap them.')),
    clock, sky, now, row, status);
  const cols = panel.querySelectorAll('.console-col');
  const col = cols[cols.length - 1] || panel;
  const bans = col.querySelector('.console-bans');
  col.insertBefore(section, bans || null);

  let last = null; // { at: local ms, data }
  let busy = false;

  function render() {
    if (!last) return;
    const since = Date.now() - last.at;
    const ur = urClockAfter(last.data.ur, since);
    clock.textContent = ur ? `Ur time ${ur.time}` : 'Ur time unknown.';
    sky.textContent = ur ? skyWords(ur.sky) : '';
    const words = last.data.server === 'up' ? eventWords(last.data.event, (Number(last.data.now) || last.at) + since) : { text: 'The realtime server is not answering.', on: false };
    now.textContent = words.text;
    for (const [key, b] of buttons) b.disabled = busy || last.data.server !== 'up' || (key === 'stop' ? !words.on : words.on);
  }

  async function load() {
    try {
      last = { at: Date.now(), data: await api.call('sky') };
    } catch (err) {
      status.textContent = err.message;
    }
    render();
  }

  async function press(p) {
    if (busy) return;
    if (p.confirm && !globalThis.confirm?.(p.confirm)) return;
    busy = true;
    render();
    try {
      const r = await api.call('meteor', { method: 'POST', body: p.body });
      status.textContent = r.text || '';
    } catch (err) {
      status.textContent = err.message;
    }
    busy = false;
    await load();
  }

  let poll = 0;
  let tick = 0;
  const showing = () => !panel.hidden && document.visibilityState !== 'hidden';
  const wake = () => {
    if (showing()) {
      if (!poll) { load(); poll = setInterval(load, POLL_MS); }
      if (!tick) tick = setInterval(render, 1000);
    } else {
      clearInterval(poll);
      clearInterval(tick);
      poll = 0;
      tick = 0;
    }
  };
  new MutationObserver(wake).observe(panel, { attributes: true, attributeFilter: ['hidden'] });
  document.addEventListener('visibilitychange', wake);
  wake();
}

if (typeof document !== 'undefined') {
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
}
