/**
 * Reactions on a Support report's messages (Austin 2026-11-27: "Can we add emoji reacts to the new bug
 * support section?"; ours, Glitch's bug form had no thread to react in). On /chat/support/<id>/
 * (src/SupportPages.php) each message carries its reactions as chips, drawn on the server so they read
 * without JavaScript, and a React button this shows. React opens the boards' picker (community.js
 * openPicker: the chat's set, two rows of eight, the arrow keys follow the grid); a pick or a chip toggles
 * yours through the game's own route, POST groddle/v1/tickets/<id>/messages/<message>/reactions
 * (src/Tickets.php react: the page's REST nonce, the owner check and the reaction limit are the game's),
 * so it is the same row the game's Reports window and the console read. The chips are drawn again from
 * its answer, as SupportPages::reactionsHtml draws them.
 *
 * Who reacted is never a name (only the player and the team see a report): "You", and "Administrator"
 * (or "the team" when more than one of them did), as SupportPages::reactionNames. The pure parts are
 * exported for the game's tests (tests/support-pages.test.js).
 */

/** PURE: who reacted, as a report names them (SupportPages::reactionNames): You first, then the team. */
export function whoNames(by) {
  const list = Array.isArray(by) ? by : [];
  const you = list.some((b) => b?.you === true);
  const team = list.filter((b) => b?.you !== true).length;
  const names = you ? ['You'] : [];
  if (team > 0) names.push(team > 1 ? (you ? 'the team' : 'The team') : 'Administrator');
  return names;
}

/** PURE: a chip's tip (CommunityText::reactionLabel): "Thumbs up: You and Administrator". */
export function chipTip(label, by) {
  const names = whoNames(by);
  if (!names.length) return String(label || '');
  const who = names.length === 1 ? names[0] : `${names.slice(0, -1).join(', ')} and ${names.at(-1)}`;
  return `${label}: ${who}`;
}

/**
 * PURE: a message's reactions from the route ([{ e, by, mine }]) as chips to draw, in the set's order
 * (REACTIONS: [key, glyph, label, art]); a key outside the set is left out.
 */
export function chipsFor(reactions, set) {
  const defs = new Map((Array.isArray(set) ? set : []).map((d) => [d[0], d]));
  const chips = [];
  for (const [key] of defs) {
    const r = (Array.isArray(reactions) ? reactions : []).find((x) => x?.e === key);
    const by = Array.isArray(r?.by) ? r.by : [];
    if (!r || !by.length) continue;
    const [, glyph, label, art] = defs.get(key);
    chips.push({ key, glyph, art: Boolean(art), n: by.length, mine: r.mine === true, tip: chipTip(label, by) });
  }
  return chips;
}

/** One chip, as SupportPages::reactionsHtml (and CommunityPages::glyph) draws it. */
function chipButton(c) {
  const b = document.createElement('button');
  b.type = 'button';
  b.className = 'cchip';
  b.dataset.react = c.key;
  b.setAttribute('aria-pressed', String(c.mine));
  b.title = c.tip;
  b.setAttribute('aria-label', c.tip);
  const mark = document.createElement('span');
  mark.setAttribute('aria-hidden', 'true');
  if (c.art) mark.className = `cchip-art cchip-${c.key}`;
  else {
    mark.className = 'cchip-glyph';
    mark.textContent = c.glyph;
  }
  const n = document.createElement('span');
  n.textContent = String(c.n);
  b.append(mark, n);
  return b;
}

/**
 * Wires a report's messages (the page's ol of .csupport-msg): shows React, toggles a chip on a click,
 * opens the picker on React, and answers the picker's picks (hook.fn, community.js react). Returns a
 * function that stops it all.
 */
export function wireSupportReactions(list, { ticket, call, REACTIONS, togglePicker, closePicker, showLine, hook, failed }) {
  if (!list || !(Number(ticket) > 0) || !REACTIONS?.length) return () => {};
  for (const row of list.querySelectorAll('[data-react-row]')) row.hidden = false;
  const busy = new Set();
  async function react(li, key, on) {
    const message = Number(li?.dataset.message);
    if (!(message > 0) || busy.has(message)) return;
    busy.add(message);
    const r = await call(`tickets/${Number(ticket)}/messages/${message}/reactions`, { method: 'POST', body: { e: key, on } });
    busy.delete(message);
    if (!r.ok || !Array.isArray(r.data?.reactions)) {
      showLine(li, r.data?.message || failed, true);
      return;
    }
    const box = li.querySelector('[data-reactions]');
    if (!box) return;
    const chips = chipsFor(r.data.reactions, REACTIONS);
    box.replaceChildren(...chips.map(chipButton));
    box.toggleAttribute('data-empty', chips.length === 0);
  }
  const onClick = (e) => {
    const li = e.target.closest('.csupport-msg');
    if (!li) return;
    const chip = e.target.closest('button[data-react]');
    if (chip) return void react(li, chip.dataset.react, chip.getAttribute('aria-pressed') !== 'true');
    const open = e.target.closest('[data-react-open]');
    if (open) togglePicker(li, open);
  };
  list.addEventListener('click', onClick);
  hook.fn = react;
  return () => {
    list.removeEventListener('click', onClick);
    if (hook.fn === react) hook.fn = null;
    closePicker();
  };
}
