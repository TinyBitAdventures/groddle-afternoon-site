/**
 * The console's Moderation tab (src/Moderation.php, ModReports.php, ModTokens.php; Austin 2026-11-09:
 * "Put Grok Bot in charge of keeping the game safe"): what players reported and the game's signals
 * flagged, what the moderators did (Grok Bot's lines marked, with Undo where there is one), the
 * restrictions that stand (Lift), and Grok Bot's token (made and revoked here, shown once).
 *
 * Its own module beside console.js: it fills the Moderation tab's panel and keeps its own REST nonce.
 * Everything goes through groddle/v1/mod/* with the session, as Grok Bot's token does with its bearer
 * secret. A reported IM shows the lines the reporter's report carries, and only those: no route gives
 * an IM's words otherwise.
 */

/** The outcomes a report resolves with (Moderation::OUTCOMES), in words. */
export const OUTCOME_WORDS = Object.freeze({
  no_action: 'No action needed',
  warned: 'Warned',
  silenced: 'Silenced',
  im_restricted: 'IMs paused',
  purged: 'Purged',
  line_deleted: 'Line deleted',
  banned: 'Banned',
  escalated: 'Escalated to Austin',
  duplicate: 'Duplicate',
});

/** What an action line says, in words. */
export const ACTION_WORDS = Object.freeze({
  silence: 'Silenced', im_restrict: 'Restricted IMs', lift: 'Lifted', ban: 'Banned', unban: 'Unbanned', delete_line: 'Deleted a line',
  purge: 'Purged', notice: 'Sent a notice', resolve: 'Resolved a report', undo: 'Undid', token_create: 'Made a token', token_revoke: 'Revoked a token',
});

/** PURE: an action line's words. */
export function actionWords(a) {
  const d = a.detail || {};
  const bits = [ACTION_WORDS[a.action] || a.action];
  if (a.player) bits.push(a.player.name);
  if (d.scope) bits.push(`(${String(d.scope).replace(',', ' and ')})`);
  if (d.hours === 0 && (a.action === 'silence' || a.action === 'im_restrict')) bits.push('with no end');
  else if (d.hours) bits.push(`for ${d.hours} h`);
  if (a.action === 'purge') bits.push(d.hours ? `(last ${d.hours} h)` : '(everything)');
  if (a.action === 'resolve' && d.outcome) bits.push(`#${d.report}: ${OUTCOME_WORDS[d.outcome] || d.outcome}`);
  if (a.action === 'undo' && d.undid) bits.push(`(${ACTION_WORDS[d.undid] || d.undid})`);
  if (d.refused) bits.push(`refused: ${d.refused}`);
  return bits.join(' ');
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
    const r = await fetch(`/wp-json/groddle/v1/mod/${path}`, {
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
  const panel = document.querySelector('[data-console-tabpanel="moderation"]');
  if (!panel || panel.querySelector('[data-mod-reports]')) return;
  const api = createApi();
  const when = (iso) => (iso ? new Date(iso).toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) : '');
  const status = el('p', { class: 'console-sub', role: 'status', 'aria-live': 'polite', 'data-mod-status': '' });
  const say = (t) => { status.textContent = t; };

  // —— Reports and flags ——
  const reportList = el('ul', { class: 'console-list console-ticketlist', 'data-mod-reports': '', 'aria-label': 'Reports' });
  const reportEmpty = el('p', { class: 'console-empty', hidden: true }, 'Nothing reported. Players report an IM from its line in the game.');
  const flagList = el('ul', { class: 'console-list', 'data-mod-flags': '', 'aria-label': 'Flags' });
  const flagEmpty = el('p', { class: 'console-empty', hidden: true }, 'No flags. The game raises one when a player sends IMs to many players, piles up refused IMs or lines, or is blocked by several players in a day.');
  const showAll = el('input', { type: 'checkbox', 'data-mod-all': '' });
  const reportsSection = el('section', { class: 'console-panel', 'aria-labelledby': 'console-mod-reports-title' },
    el('div', { class: 'console-panel-head' },
      el('h2', { id: 'console-mod-reports-title' }, 'Reports ', el('span', { class: 'console-count console-count-unread', 'data-mod-open': '' })),
      el('p', { class: 'console-hint' }, 'IMs players reported, with the lines around them as the reporter saw them. This is the only place an IM\'s words reach moderation.'),
      el('label', { class: 'console-check' }, showAll, ' Show resolved too')),
    status, reportList, reportEmpty,
    el('h3', {}, 'Flags'), flagList, flagEmpty);

  // —— Actions ——
  const actionList = el('ul', { class: 'console-list console-loglist', 'data-mod-actions': '', 'aria-label': 'Moderators\' log' });
  const actionEmpty = el('p', { class: 'console-empty', hidden: true }, 'No moderation yet.');
  const onlyGrok = el('input', { type: 'checkbox', 'data-mod-only-grok': '' });
  const actionsSection = el('section', { class: 'console-panel', 'aria-labelledby': 'console-mod-actions-title' },
    el('div', { class: 'console-panel-head' },
      el('h2', { id: 'console-mod-actions-title' }, 'Moderators\' log'),
      el('p', { class: 'console-hint' }, 'Every call Grok Bot\'s token makes and every action taken here. Silences, IM pauses and bans can be undone; a purge or a deleted line is final.'),
      el('label', { class: 'console-check' }, onlyGrok, ' Grok Bot only')),
    actionList, actionEmpty);

  // —— Standing restrictions ——
  const restrictionList = el('ul', { class: 'console-list', 'data-mod-restrictions': '', 'aria-label': 'Restrictions' });
  const restrictionEmpty = el('p', { class: 'console-empty', hidden: true }, 'Nobody is silenced or has IMs paused.');
  const restrictionsSection = el('section', { class: 'console-panel', 'aria-labelledby': 'console-mod-restrictions-title' },
    el('div', { class: 'console-panel-head' }, el('h2', { id: 'console-mod-restrictions-title' }, 'Silenced and IM-paused')),
    restrictionList, restrictionEmpty);

  // —— Tokens ——
  const tokenList = el('ul', { class: 'console-list console-keylist', 'data-mod-tokens': '', 'aria-label': 'Moderator tokens' });
  const tokenEmpty = el('p', { class: 'console-empty', hidden: true }, 'No moderator token yet.');
  const tokenLabel = el('input', { id: 'console-mod-token-label', type: 'text', maxlength: '60', required: true, autocomplete: 'off', value: 'Grok Bot' });
  const capRead = el('input', { type: 'checkbox', value: 'read', checked: true });
  const capMod = el('input', { type: 'checkbox', value: 'moderate', checked: true });
  const tokenForm = el('form', { class: 'console-reply console-newkey', 'data-mod-token-form': '' },
    el('label', { for: 'console-mod-token-label' }, 'Label'), tokenLabel,
    el('fieldset', { class: 'console-key-scopes' }, el('legend', {}, 'What it may do'),
      el('label', { class: 'console-check console-key-scope' }, capRead, ' Read the feed'),
      el('label', { class: 'console-check console-key-scope' }, capMod, ' Moderate')),
    el('div', { class: 'console-reply-foot' }, el('span', { class: 'console-sub' }, 'A token reaches groddle/v1/mod/* only: never the console, Minn Admin or another admin route, and it never acts on an administrator.'), el('button', { type: 'submit', class: 'console-primary' }, 'Make token')));
  const secretBox = el('textarea', { class: 'console-key-secret', readonly: true, rows: '2', 'aria-label': 'The new moderator token', 'data-mod-token-secret': '' });
  const shown = el('div', { class: 'console-key-shown', hidden: true, role: 'region', 'aria-label': 'The new token' },
    el('p', {}, el('b', {}, 'Shown once. '), 'Give it to Grok Bot as its bearer token (Authorization: Bearer …). The site keeps only its hash.'), secretBox);
  const tokensSection = el('section', { class: 'console-panel console-keys', 'aria-labelledby': 'console-mod-tokens-title' },
    el('div', { class: 'console-panel-head' }, el('h2', { id: 'console-mod-tokens-title' }, 'Moderator tokens'),
      el('p', { class: 'console-hint' }, 'Grok Bot\'s credential. Revoking stops it at once.')),
    tokenList, tokenEmpty, shown, tokenForm);

  panel.append(reportsSection, actionsSection, restrictionsSection, tokensSection);

  // —— Acting ——
  async function act(path, body, done) {
    try {
      const r = await api.call(path, { method: 'POST', body });
      say(r.text || 'Done.');
      done?.(r);
      load();
    } catch (err) { say(err.message); }
  }
  const ask = (q, dflt) => globalThis.prompt?.(q, dflt);

  function reportActions(r) {
    const p = r.player;
    const reason = () => ask(`Why? (shown in the moderators' log${r.kind === 'im' ? ` with report #${r.id}` : ''})`, r.kind === 'im' ? `Report #${r.id}` : `Flag: ${r.signal}`);
    const btn = (label, fn, attrs = {}) => el('button', { type: 'button', class: 'console-small', ...attrs, onclick: fn }, label);
    const bar = el('div', { class: 'console-draft-acts' });
    if (r.status === 'open') {
      const pick = el('select', { 'aria-label': 'Outcome', 'data-mod-outcome': String(r.id) }, ...Object.entries(OUTCOME_WORDS).map(([v, w]) => el('option', { value: v }, w)));
      bar.append(pick, btn('Resolve', () => act(`reports/${r.id}/resolve`, { outcome: pick.value, note: ask('A note for the log (optional)', '') || '' }), { 'data-mod-resolve': String(r.id) }));
    }
    if (r.kind === 'im' && r.lineDeletable) bar.append(btn('Delete line', () => { const why = reason(); if (why) act('delete_line', { report: r.id, reason: why }); }));
    bar.append(
      btn('Silence 24 h', () => { const why = reason(); if (why) act('silence', { player: p.id, scope: 'all', hours: 24, reason: why }); }),
      btn('Pause IMs 24 h', () => { const why = reason(); if (why) act('im_restrict', { player: p.id, scope: 'all', hours: 24, reason: why }); }),
      btn('Purge 24 h', () => { const why = reason(); if (why && globalThis.confirm?.(`Remove everything ${p.name} said in the last 24 hours, from the site and every open window? A purge is final.`)) act('purge', { player: p.id, hours: 24, reason: why }); }),
      btn('Ban', () => { const why = reason(); if (why && globalThis.confirm?.(`Ban ${p.name}? They are signed out at once.`)) act('ban', { player: p.id, reason: why }); }),
    );
    return bar;
  }

  function reportRow(r) {
    const who = el('div', { class: 'console-draft-top' },
      el('b', { text: r.kind === 'im' ? `#${r.id} ${r.player.name}` : `${r.player.name}` }),
      el('span', { class: 'console-sub', text: ` ${when(r.createdAt)}${r.player.newAccount ? ' · new account' : ''}${r.player.accountDays != null ? ` · ${r.player.accountDays} days old` : ''}` }),
      el('span', { class: `console-chip console-chip-${r.status === 'open' ? 'open' : 'closed'}`, text: r.status === 'open' ? 'Open' : (OUTCOME_WORDS[r.outcome?.outcome] || 'Resolved') }));
    const li = el('li', { class: 'console-draft', [r.kind === 'im' ? 'data-mod-report' : 'data-mod-flag']: String(r.id) }, who);
    if (r.kind === 'im') {
      li.append(el('span', { class: 'console-sub', text: `Reported by ${r.reporter?.name || '?'}${r.verified ? '' : ' · from the reporter\'s game (the site did not have the line yet)'}` }));
      if (r.note) li.append(el('p', { class: 'console-quote', text: `“${r.note}”` }));
      const lines = el('ol', { class: 'console-messages', 'aria-label': 'The conversation' });
      for (const l of r.lines) {
        lines.append(el('li', { class: `console-msg${l.reported ? ' console-msg-admin' : ''}`, 'data-mod-line': l.reported ? 'reported' : '' },
          el('span', { class: 'console-msg-who', text: `${l.from?.name || '?'}${l.reported ? ' (reported)' : ''}: ` }),
          el('span', { class: 'console-msg-body', text: l.text })));
      }
      li.append(lines);
    } else {
      li.append(el('span', { class: 'console-sub', text: `${r.about} · ${r.n} in ${Math.round(r.windowSeconds / 60)} min${r.times > 1 ? ` · raised ${r.times} times` : ''}` }));
    }
    if (r.outcome) li.append(el('span', { class: 'console-sub', text: `${OUTCOME_WORDS[r.outcome.outcome] || r.outcome.outcome} by ${r.outcome.by}, ${when(r.outcome.at)}${r.outcome.note ? `: ${r.outcome.note}` : ''}` }));
    li.append(reportActions(r));
    return li;
  }

  function actionRow(a) {
    const li = el('li', { class: 'console-draft', 'data-mod-action': String(a.id) },
      el('div', { class: 'console-draft-top' },
        a.by.grok ? el('span', { class: 'console-chip console-chip-working', text: a.by.name }) : el('b', { text: a.by.name }),
        el('span', { text: ` ${actionWords(a)}` }),
        el('span', { class: 'console-sub', text: ` ${when(a.at)} · ${a.result}` })));
    if (a.reason) li.append(el('span', { class: 'console-sub', text: a.reason }));
    if (a.undone) li.append(el('span', { class: 'console-sub', text: `Undone by ${a.undone.by}, ${when(a.undone.at)}` }));
    if (a.undoable) {
      li.append(el('div', { class: 'console-draft-acts' }, el('button', { type: 'button', class: 'console-small', 'data-mod-undo': String(a.id), onclick: () => act(`actions/${a.id}/undo`, { reason: ask('Why undo it?', 'Undone from the console') || 'Undone from the console' }) }, 'Undo')));
    }
    return li;
  }

  function restrictionRow(r) {
    return el('li', { class: 'console-draft', 'data-mod-restriction': String(r.id) },
      el('div', { class: 'console-draft-top' }, el('b', { text: r.player.name }),
        el('span', { text: r.kind === 'silence' ? ` silenced (${r.scope.replace(',', ' and ')})` : (r.scope === 'all' ? ' IMs paused' : ' IMs to buddies only') }),
        el('span', { class: 'console-sub', text: r.until ? ` until ${when(r.until)}` : ' with no end' })),
      r.reason ? el('span', { class: 'console-sub', text: r.reason }) : null,
      el('div', { class: 'console-draft-acts' }, el('button', { type: 'button', class: 'console-small', onclick: () => act('lift', { player: r.player.id, kind: r.kind, reason: ask('Why lift it?', 'Lifted from the console') || 'Lifted from the console' }) }, 'Lift')));
  }

  function tokenRow(t) {
    const state = t.revokedAt ? `revoked ${when(t.revokedAt)}` : 'active';
    return el('li', { class: 'console-draft', 'data-mod-token': t.id },
      el('div', { class: 'console-draft-top' }, el('b', { text: t.label }), el('span', { class: 'console-sub', text: ` ${t.id}` }), el('span', { class: `console-chip console-chip-${t.revokedAt ? 'closed' : 'open'}`, text: t.revokedAt ? 'revoked' : 'active' })),
      el('span', { class: 'console-sub', text: `${t.caps.join(', ')}. ${state}; ${t.lastUsedAt ? `last used ${when(t.lastUsedAt)}` : 'never used'}.` }),
      t.revokedAt ? null : el('div', { class: 'console-draft-acts' }, el('button', { type: 'button', class: 'console-small', 'data-mod-revoke': t.id, onclick: () => revoke(t) }, 'Revoke')));
  }

  async function load() {
    try {
      const [feed, log, tokens] = await Promise.all([
        api.call(`feed${showAll.checked ? '?status=all' : ''}`),
        api.call(`actions${onlyGrok.checked ? '?actor=token' : ''}`),
        api.call('tokens'),
      ]);
      reportList.replaceChildren(...feed.reports.map(reportRow));
      reportEmpty.hidden = feed.reports.length > 0;
      flagList.replaceChildren(...feed.flags.map(reportRow));
      flagEmpty.hidden = feed.flags.length > 0;
      const open = feed.reports.filter((r) => r.status === 'open').length + feed.flags.filter((r) => r.status === 'open').length;
      panel.querySelector('[data-mod-open]').textContent = open ? String(open) : '';
      actionList.replaceChildren(...log.actions.map(actionRow));
      actionEmpty.hidden = log.actions.length > 0;
      restrictionList.replaceChildren(...feed.restrictions.map(restrictionRow));
      restrictionEmpty.hidden = feed.restrictions.length > 0;
      tokenList.replaceChildren(...tokens.tokens.map(tokenRow));
      tokenEmpty.hidden = tokens.tokens.length > 0;
    } catch (err) { say(err.message); }
  }
  async function revoke(t) {
    if (!globalThis.confirm?.(`Revoke “${t.label}”? It stops at once.`)) return;
    try {
      await api.call(`tokens/${t.id}`, { method: 'DELETE' });
      say(`Revoked “${t.label}”.`);
      load();
    } catch (err) { say(err.message); }
  }
  tokenForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const caps = [capRead, capMod].filter((c) => c.checked).map((c) => c.value);
    try {
      const made = await api.call('tokens', { method: 'POST', body: { label: tokenLabel.value.trim(), caps } });
      secretBox.value = made.token;
      shown.hidden = false;
      secretBox.focus();
      secretBox.select();
      say(`Made “${made.label}”. Copy it now: it is not shown again.`);
      load();
    } catch (err) { say(err.message); }
  });
  showAll.addEventListener('change', load);
  onlyGrok.addEventListener('change', load);

  const tab = document.querySelector('[data-console-tab="moderation"]');
  let loaded = false;
  const maybe = () => { if (!panel.hidden && !loaded) { loaded = true; load(); } };
  tab?.addEventListener('click', () => {
    setTimeout(maybe, 0);
    if (location.hash !== '#moderation') history.replaceState(null, '', '#moderation');
  });
  new MutationObserver(maybe).observe(panel, { attributes: true, attributeFilter: ['hidden'] });
  if (location.hash === '#moderation') tab?.click();
  maybe();
}

if (typeof document !== 'undefined') {
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
}
