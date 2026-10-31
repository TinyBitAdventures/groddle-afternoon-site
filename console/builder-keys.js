/**
 * Builder keys in the console's Builder tab (the game's docs/level-builder/spec.md 8.4;
 * src/AdminBuilderKeys.php): keys for agents on this computer, each with a label, scopes and an
 * expiry, made and revoked here with the session only (a key can never manage keys). A new key's
 * secret is shown once, as the two lines that go in ~/.config/groddle/builder.env.
 *
 * Its own module, loaded beside console.js when the builder is on; it adds its section to the
 * Builder tab's panel and keeps its own REST nonce.
 */

/** The scopes a key may hold, in the words the form shows. */
export const SCOPE_WORDS = Object.freeze({
  'drafts:read': 'Read drafts',
  'drafts:write': 'Edit drafts',
  'assets:write': 'Send new art',
  validate: 'Validate',
  render: 'Render pictures',
});

/** PURE: a key's line in words. */
export function keyWords(k, when = (iso) => iso) {
  const state = { active: `expires ${when(k.expiresAt)}`, expired: `expired ${when(k.expiresAt)}`, revoked: `revoked ${when(k.revokedAt)}` }[k.state] || k.state;
  const used = k.lastUsedAt ? `last used ${when(k.lastUsedAt)}` : 'never used';
  return `${k.scopes.map((s) => SCOPE_WORDS[s] || s).join(', ')}. ${state}; ${used}.`;
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
    const r = await fetch(`/wp-json/groddle/v1/admin/builder/${path}`, {
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
  const panel = document.querySelector('[data-console-tabpanel="builder"]');
  if (!panel || panel.querySelector('[data-builder-keys]')) return;
  const api = createApi();
  const when = (iso) => (iso ? new Date(iso).toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) : '');

  const list = el('ul', { class: 'console-list console-keylist', 'data-builder-keys-list': '', 'aria-label': 'Builder keys' });
  const empty = el('p', { class: 'console-empty', hidden: true }, 'No keys yet.');
  const status = el('p', { class: 'console-sub', role: 'status', 'aria-live': 'polite' });
  const label = el('input', { id: 'console-key-label', name: 'label', type: 'text', maxlength: '60', required: true, autocomplete: 'off', placeholder: 'My agent' });
  const days = el('input', { id: 'console-key-days', name: 'days', type: 'number', min: '1', max: '365', value: '30', required: true });
  const boxes = Object.entries(SCOPE_WORDS).map(([scope, words]) => el('label', { class: 'console-check console-key-scope' },
    el('input', { type: 'checkbox', name: 'scope', value: scope, checked: true }), ` ${words}`));
  const form = el('form', { class: 'console-reply console-newkey', 'data-builder-keys-form': '' },
    el('label', { for: 'console-key-label' }, 'Label'), label,
    el('fieldset', { class: 'console-key-scopes' }, el('legend', {}, 'What it may do'), ...boxes),
    el('label', { for: 'console-key-days' }, 'Days until it expires'), days,
    el('div', { class: 'console-reply-foot' }, el('span', { class: 'console-sub' }, 'No key can publish, manage keys or reach any other console page.'), el('button', { type: 'submit', class: 'console-primary' }, 'Make key')));
  const secretBox = el('textarea', { class: 'console-key-secret', readonly: true, rows: '3', 'aria-label': 'The new key, for builder.env', 'data-builder-keys-secret': '' });
  const copy = el('button', { type: 'button', class: 'console-small' }, 'Copy');
  const shown = el('div', { class: 'console-key-shown', hidden: true, role: 'region', 'aria-label': 'The new key' },
    el('p', {}, el('b', {}, 'Shown once. '), 'Put these lines in ~/.config/groddle/builder.env (never in a repo). The site keeps no copy.'),
    secretBox, el('div', { class: 'console-draft-acts' }, copy));
  const section = el('section', { class: 'console-panel console-keys', 'aria-labelledby': 'console-keys-title', 'data-builder-keys': '' },
    el('div', { class: 'console-panel-head' },
      el('h2', { id: 'console-keys-title' }, 'Builder keys'),
      el('p', { class: 'console-hint' }, 'For agents on this computer: the CLI (node tools/builder/cli.mjs) signs each request with a key. A key reaches the level builder only, with the scopes you give it.')),
    list, empty, status, shown, form);
  panel.append(section);

  let keys = [];
  function render() {
    list.replaceChildren(...keys.map((k) => el('li', { class: 'console-draft', 'data-key': k.id },
      el('div', { class: 'console-draft-top' }, el('b', { text: k.label }), el('span', { class: 'console-sub', text: ` ${k.id}` }), el('span', { class: `console-chip console-chip-${k.state === 'active' ? 'open' : 'closed'}`, text: k.state })),
      el('span', { class: 'console-sub', text: keyWords(k, when) }),
      k.state === 'active' ? el('div', { class: 'console-draft-acts' }, el('button', { type: 'button', class: 'console-small', 'data-key-revoke': k.id, onclick: () => revoke(k) }, 'Revoke')) : null)));
    empty.hidden = keys.length > 0;
  }
  async function load() {
    try {
      const r = await api.call('keys');
      keys = r.keys || [];
      if (!r.master) status.textContent = 'This site has no builder master secret yet, so keys cannot be made. Set the option groddle_builder_secret_file to a file outside the webroot.';
      render();
    } catch (err) {
      status.textContent = err.message;
    }
  }
  async function revoke(k) {
    if (!globalThis.confirm?.(`Revoke “${k.label}”? Agents using it stop at once.`)) return;
    try {
      await api.call(`keys/${k.id}`, { method: 'DELETE' });
      status.textContent = `Revoked “${k.label}”.`;
      load();
    } catch (err) { status.textContent = err.message; }
  }
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const scopes = boxes.map((b) => b.querySelector('input')).filter((i) => i.checked).map((i) => i.value);
    if (!scopes.length) { status.textContent = 'Give the key at least one thing it may do.'; return; }
    try {
      const made = await api.call('keys', { method: 'POST', body: { label: label.value.trim(), scopes, days: Number(days.value) || 30 } });
      secretBox.value = made.env;
      shown.hidden = false;
      secretBox.focus();
      secretBox.select();
      status.textContent = `Made “${made.key.label}”. Copy it now: it is not shown again.`;
      form.reset();
      load();
    } catch (err) { status.textContent = err.message; }
  });
  copy.addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(secretBox.value);
      status.textContent = 'Copied.';
    } catch {
      secretBox.select();
      status.textContent = 'Select the text and copy it.';
    }
  });
  // Load when the Builder tab first shows.
  const tab = document.querySelector('[data-console-tab="builder"]');
  let loaded = false;
  const maybe = () => { if (!panel.hidden && !loaded) { loaded = true; load(); } };
  tab?.addEventListener('click', () => setTimeout(maybe, 0));
  new MutationObserver(maybe).observe(panel, { attributes: true, attributeFilter: ['hidden'] });
  maybe();
}

if (typeof document !== 'undefined') {
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
}
