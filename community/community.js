/**
 * The Community pages' script (src/CommunityPages.php draws them whole; they read and post without
 * this). With it:
 * - the reply box grows with what you write and Enter sends it (Shift+Enter for a new line), and the
 *   forms post through groddle/v1/community (src/Community.php) without a reload;
 * - Quote, React (the chat's set as chips), Edit (15 minutes), Delete and Report on each post;
 * - new and changed posts come in while the thread is open (GET community/threads/<id>/updates every
 *   POLL_MS while the tab is visible): appended on the newest page, a "new posts" link on older ones;
 * - a link to another site asks first (the game's own js/chat-links.js, as in its chat);
 * - a sign-in panel can sign you in here (groddle/v1/session), then the page reloads; when the site
 *   has a Google client id it carries Continue with Google too, as the game's forms do;
 * - the chat app's header (src/SiteNav.php app layout): its links fold behind a menu button on a
 *   phone; for a signed-in player the bell (notifications.js) starts first and is handed to the app;
 * - moving around /chat/ never reloads (chat-nav.js): a board, a topic or a conversation comes in
 *   place with pushState, so the forms and the topic's poll below are wired again for each view
 *   (wirePage), and the chat app's live socket stays connected.
 *
 * The REST nonce is the one the page drew (main[data-nonce]); a stale one is swapped for a fresh one
 * from admin-ajax.php?action=rest-nonce, as players/buddy.js does.
 */

const main = document.querySelector('main[data-community]');
const POLL_MS = 10000;
const NONCE = /^[0-9a-f]{10}$/;
const API = '/wp-json/groddle/v1/';
/** The chat's reactions (the game's js/chat-reactions.js, read from the page: main[data-reactions], data/reactions.json). */
let REACTIONS = [];
try { REACTIONS = JSON.parse(main?.dataset.reactions || '[]').map((r) => [r.key, r.glyph, r.label, r.art]); } catch { REACTIONS = []; }
const SAID = {
  failed: 'That didn\'t work. Try again in a moment.',
  reported: 'Thanks. An administrator will take a look.',
  already: 'You already reported this post.',
  deleteAsk: 'Delete this post? It shows as deleted in the thread.',
  newPosts: (n) => (n === 1 ? '1 new post: see it' : `${n} new posts: see them`),
  enterHint: 'Enter sends, Shift+Enter for a new line.',
  signIn: 'Sign in here',
  // The game's js/account-forms.js FORM_COPY.google.
  google: {
    or: 'or',
    failed: 'Google sign-in didn\'t work. Try again, or use your email.',
    nameTitle: 'Choose your character name',
    nameBody: (email) => `You're signing in with Google as ${email}. What should other players call you?`,
    submit: 'Make my character',
    back: 'Use my email instead',
  },
};

let nonce = main?.dataset.nonce || '';
/** Whether the chat app's socket is up (chat-app.js sets it): the topic's poll waits while it is. */
export const live = { on: false };
export { main, call, fromHtml, say, grow, GROW_ROWS, sendKey, REACTIONS, SAID, openPicker, closePicker };

async function freshNonce() {
  try {
    const r = await fetch('/wp-admin/admin-ajax.php?action=rest-nonce', { credentials: 'same-origin', cache: 'no-store' });
    const t = (await r.text()).trim();
    nonce = r.ok && NONCE.test(t) ? t : '';
  } catch { nonce = ''; }
  return nonce;
}

/** A call to groddle/v1: { ok, status, data }; a stale nonce is refreshed once. */
async function call(path, { method = 'GET', body, retried = false } = {}) {
  let r;
  try {
    r = await fetch(API + path, {
      method,
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { ...(nonce ? { 'X-WP-Nonce': nonce } : {}), ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}) },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
  } catch {
    return { ok: false, status: 0, data: { message: SAID.failed } };
  }
  const data = await r.json().catch(() => null);
  if (r.status === 403 && data?.code === 'rest_cookie_invalid_nonce' && !retried && (await freshNonce())) return call(path, { method, body, retried: true });
  const next = r.headers.get('X-WP-Nonce');
  if (next && NONCE.test(next)) nonce = next;
  return { ok: r.ok, status: r.status, data };
}

function fromHtml(html) {
  const t = document.createElement('template');
  t.innerHTML = String(html || '').trim();
  return t.content.firstElementChild;
}

function say(el, text, bad = false) {
  if (!el) return;
  el.textContent = text || '';
  if (bad) el.dataset.tone = 'bad';
  else delete el.dataset.tone;
}

/**
 * The box grows with its words up to GROW_ROWS lines or its CSS max-height (about half the screen),
 * whichever is less, then scrolls, so a long post can be read back before it goes (Friendly's Bugs
 * thread 6). The reply box, a new topic's box and an edit all grow.
 */
const GROW_ROWS = 18;
function grow(field, maxRows = GROW_ROWS) {
  if (!field) return () => {};
  const fit = () => {
    field.style.height = 'auto';
    const cs = getComputedStyle(field);
    const line = parseFloat(cs.lineHeight) || 22;
    const extra = (parseFloat(cs.paddingTop) || 0) + (parseFloat(cs.paddingBottom) || 0) + (parseFloat(cs.borderTopWidth) || 0) + (parseFloat(cs.borderBottomWidth) || 0);
    const cap = parseFloat(cs.maxHeight);
    const max = Math.min(line * maxRows + extra, Number.isFinite(cap) && cap > 0 ? cap : Infinity);
    field.style.height = `${Math.min(max, field.scrollHeight + (parseFloat(cs.borderTopWidth) || 0) + (parseFloat(cs.borderBottomWidth) || 0))}px`;
    field.style.overflowY = field.scrollHeight + extra > max ? 'auto' : 'hidden';
  };
  field.addEventListener('input', fit);
  fit();
  return fit;
}

/** Enter (no Shift, Alt, Ctrl or Cmd, not composing) sends (the game's js/ui/chat-input.js sendKey). */
const sendKey = (e) => e.key === 'Enter' && !e.isComposing && e.keyCode !== 229 && !e.shiftKey && !e.altKey && !e.ctrlKey && !e.metaKey;

// —— Links to other sites ask first ——

if (main) {
  import('/js/chat-links.js').then((m) => m.bindChatLinks(main)).catch(() => { /* plain links */ });
}

// —— Sign in here ——

function wireSignIn(root) {
  for (const box of root.querySelectorAll('[data-community-signin]:not([data-signin-wired])')) {
    box.dataset.signinWired = '';
    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'cbutton cbutton-quiet';
    toggle.textContent = SAID.signIn;
    toggle.setAttribute('aria-expanded', 'false');
    const form = document.createElement('form');
    form.className = 'csignin-form';
    // Password managers (Proton Pass among them) read a sign-in by its method, action and the
    // identifier's autocomplete: it posts to the route the script calls (the script still sends it).
    form.method = 'post';
    form.action = '/wp-json/groddle/v1/session';
    form.hidden = true;
    form.innerHTML = '<div class="csignin-google" data-community-google hidden><div data-community-google-button></div><div class="csignin-or" aria-hidden="true">' + SAID.google.or + '</div></div>'
      + '<label class="clabel" for="csignin-email">Email</label><input class="cinput" id="csignin-email" name="email" type="email" autocomplete="username" required />'
      + '<label class="clabel" for="csignin-pass">Password</label><input class="cinput" id="csignin-pass" name="password" type="password" autocomplete="current-password" required />'
      + '<div class="cform-row"><button class="cbutton" type="submit">Sign in</button><a class="cquoting-off" href="/play/online/">Forgot your password?</a></div><p class="csaid" role="status" aria-live="polite"></p>';
    box.querySelector('.cbutton')?.replaceWith(toggle);
    box.after(form);
    const named = googleNameForm(form);
    form.after(named);
    toggle.addEventListener('click', () => {
      const open = form.hidden && named.hidden;
      form.hidden = !open;
      named.hidden = true;
      toggle.setAttribute('aria-expanded', String(open));
      if (!open) return;
      form.querySelector('input')?.focus();
      googleForms.active = form;
      startGoogle(form);
    });
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const note = form.querySelector('.csaid');
      const button = form.querySelector('button[type="submit"]');
      button.disabled = true;
      nonce = '';
      const r = await call('session', { method: 'POST', body: { email: form.email.value, password: form.password.value, remember: true } });
      button.disabled = false;
      if (r.ok && r.data?.signedIn) {
        location.reload();
        return;
      }
      say(note, r.data?.message || SAID.failed, true);
    });
  }
}

// —— Continue with Google (Friendly, /chat/bugs/9) ——
// The game's js/account-forms.js, the same way: the client id from groddle/v1/account/options (null when
// Google sign-in is off: no button, no script), Google's own button (Google Identity Services, the
// credential callback: no redirect, no secret), the credential to groddle/v1/account/google
// (src/GoogleSignIn.php), and a new player's name step through account/google/finish. Google's script
// loads only when a sign-in form is opened.

const GOOGLE_SCRIPT = 'https://accounts.google.com/gsi/client';
/** The open sign-in form (its button's credential comes back to it) and the Google client id, asked once. */
const googleForms = { active: null, clientId: null, gis: null };

/** Google's button options: the game's googleButtonOptions (Continue with Google, outlined, large). */
export function googleButtonOptions(width = 320) {
  return { type: 'standard', theme: 'outline', size: 'large', text: 'continue_with', shape: 'pill', logo_alignment: 'left', width: Math.max(200, Math.min(400, Math.round(width))) };
}

/** What a Google answer means: 'signed-in', 'name' (a new player chooses a name, 202 with a pending token) or 'refused'. */
export function googleAnswer(r) {
  if (r?.status === 202 && typeof r.data?.pending === 'string' && r.data.pending) return 'name';
  return r?.ok && r.data?.signedIn === true ? 'signed-in' : 'refused';
}

function googleClientId() {
  googleForms.clientId ||= call('account/options').then((r) => (r.ok && typeof r.data?.google === 'string' ? r.data.google : ''), () => '');
  return googleForms.clientId;
}

function googleScript() {
  googleForms.gis ||= new Promise((resolve) => {
    const tag = document.createElement('script');
    tag.src = GOOGLE_SCRIPT;
    tag.async = true;
    tag.onload = () => resolve(globalThis.google?.accounts?.id || null);
    tag.onerror = () => { googleForms.gis = null; resolve(null); };
    document.head.append(tag);
  });
  return googleForms.gis;
}

/** Google's button in an opened sign-in form, once, when the site has a client id. */
async function startGoogle(form) {
  const row = form.querySelector('[data-community-google]');
  if (!row || row.dataset.started) return;
  row.dataset.started = '';
  const clientId = await googleClientId();
  if (!clientId) return;
  const gis = await googleScript();
  if (!gis) return;
  if (!googleForms.inited) {
    googleForms.inited = true;
    gis.initialize({ client_id: clientId, callback: (r) => onGoogleCredential(r?.credential), ux_mode: 'popup', auto_select: false, cancel_on_tap_outside: true, context: 'signin' });
  }
  row.hidden = false;
  gis.renderButton(row.querySelector('[data-community-google-button]'), googleButtonOptions(Math.min(400, form.clientWidth || 320)));
}

async function onGoogleCredential(credential) {
  const form = googleForms.active;
  if (!form?.isConnected) return;
  const note = form.querySelector('.csaid');
  say(note, '');
  if (typeof credential !== 'string' || !credential) return say(note, SAID.google.failed, true);
  nonce = '';
  const r = await call('account/google', { method: 'POST', body: { credential, remember: true } });
  const answer = googleAnswer(r);
  if (answer === 'signed-in') return location.reload();
  if (answer === 'refused') return say(note, r.status && r.data?.message ? r.data.message : SAID.google.failed, true);
  const named = form.nextElementSibling;
  if (!named?.matches('[data-community-google-name]')) return say(note, SAID.google.failed, true);
  named.pending = r.data.pending;
  named.querySelector('[data-google-email]').textContent = SAID.google.nameBody(String(r.data.email || ''));
  const field = named.querySelector('input[name="name"]');
  field.value = String(r.data.name || '');
  say(named.querySelector('.csaid'), '');
  form.hidden = true;
  named.hidden = false;
  field.focus();
}

let googleNames = 0;
/** A new player from Google chooses a character name (the site's pending token, held here only), then is signed in. */
function googleNameForm(signin) {
  const id = `csignin-gname-${++googleNames}`;
  const named = document.createElement('form');
  named.className = 'csignin-form';
  named.dataset.communityGoogleName = '';
  named.method = 'post';
  named.action = '/wp-json/groddle/v1/account/google/finish';
  named.hidden = true;
  named.innerHTML = `<strong>${SAID.google.nameTitle}</strong><p class="chint" data-google-email></p>`
    + `<label class="clabel" for="${id}">Character name</label><input class="cinput" id="${id}" name="name" type="text" autocomplete="nickname" maxlength="19" autocapitalize="words" spellcheck="false" required />`
    + `<div class="cform-row"><button class="cbutton" type="submit">${SAID.google.submit}</button><button class="cquoting-off" type="button" data-google-back>${SAID.google.back}</button></div><p class="csaid" role="status" aria-live="polite"></p>`;
  named.querySelector('[data-google-back]').addEventListener('click', () => {
    named.pending = null;
    named.hidden = true;
    signin.hidden = false;
    signin.querySelector('input[name="email"]')?.focus();
  });
  named.addEventListener('submit', async (e) => {
    e.preventDefault();
    const note = named.querySelector('.csaid');
    const button = named.querySelector('button[type="submit"]');
    button.disabled = true;
    nonce = '';
    const r = await call('account/google/finish', { method: 'POST', body: { pending: named.pending || '', name: named.querySelector('input[name="name"]').value, remember: true } });
    button.disabled = false;
    if (r.ok && r.data?.signedIn) {
      location.reload();
      return;
    }
    // google_expired / google_used: start again from Google's button.
    if (r.status === 410) {
      named.pending = null;
      named.hidden = true;
      signin.hidden = false;
      say(signin.querySelector('.csaid'), r.data?.message || SAID.google.failed, true);
      return;
    }
    say(note, r.data?.fields?.name || r.data?.message || SAID.failed, true);
  });
  return named;
}

wireSignIn(document);

// —— The parts of the view in the pane: a new thread's form, a topic's posts, its reply box and poll ——

/** chat-app.js calls this when the server says this topic has a new post. */
export const topicNow = { fn: null, thread: 0 };
/** Stops what the last view started (its poll, its listeners). */
let unwire = () => {};

/** The moves inside /chat/ (chat-nav.js), once it has loaded; until then (and without the app's frame) a link loads as usual. */
const v = new URL(import.meta.url).search;
let nav = null;
const navReady = main?.matches('.cx')
  ? import(`./chat-nav.js${v}`)
    .then((m) => { nav = m.startNav({ main, hooks: { leave: () => unwire(), enter: (root) => { wireSignIn(root); wirePage(); } } }); return nav; })
    .catch((e) => { console.warn('chat nav', e); return null; })
  : Promise.resolve(null);
const go = (url, opts) => (nav ? nav.go(url, opts) : location.assign(url));

function wirePage() {
  unwire();
  const stops = [];
  const newForm = document.querySelector('form[data-community-new]');
  if (newForm) {
    const board = newForm.dataset.communityNew;
    const note = newForm.querySelector('.csaid');
    // The topic's words grow as the reply box's do (Friendly's Bugs thread 6).
    grow(newForm.querySelector('textarea'));
    newForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const button = newForm.querySelector('button[type="submit"]');
      button.disabled = true;
      const r = await call(`community/boards/${board}/threads`, { method: 'POST', body: { title: newForm.title.value, body: newForm.body.value } });
      if (r.ok && r.data?.url) {
        // The new topic, in place of the form (as the form's own 303 without JavaScript).
        go(r.data.url, { replace: true });
        return;
      }
      button.disabled = false;
      say(note, r.data?.message || SAID.failed, true);
    });
  }

  // —— A thread ——

  const list = document.querySelector('[data-community-posts]');
  const threadId = Number(main?.dataset.thread) || 0;
  let after = Number(main?.dataset.after) || 0;
  let since = Number(main?.dataset.since) || 0;
  const lastPage = main?.dataset.lastPage === '1';
  const replyForm = document.querySelector('form[data-community-reply]');
  topicNow.thread = list ? threadId : 0;

  function placePost(html, { fresh = false } = {}) {
    const li = fromHtml(html);
    if (!li || !list) return null;
    const old = document.getElementById(li.id);
    if (old) {
      // A post being edited keeps its editor until it is saved or cancelled.
      if (old.querySelector('.cedit')) return old;
      old.replaceWith(li);
    } else {
      if (fresh) li.dataset.fresh = '';
      list.append(li);
    }
    return li;
  }

  if (replyForm && list) {
    const field = replyForm.querySelector('textarea');
    const note = replyForm.querySelector('.csaid');
    const quoteField = replyForm.querySelector('[data-quote-field]');
    const quoting = replyForm.querySelector('[data-quoting]');
    const hint = replyForm.querySelector('[data-reply-hint]');
    if (hint) hint.textContent = `${SAID.enterHint} ${hint.textContent}`;
    const fit = grow(field);
    let busy = false;
    field.addEventListener('keydown', (e) => {
      if (sendKey(e)) {
        e.preventDefault();
        replyForm.requestSubmit();
      }
    });
    replyForm.querySelector('[data-unquote]')?.addEventListener('click', (e) => {
      e.preventDefault();
      quoteField.value = '';
      quoting.hidden = true;
      field.focus();
    });
    replyForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (busy || !field.value.trim()) return;
      busy = true;
      const button = replyForm.querySelector('button[type="submit"]');
      button.disabled = true;
      const r = await call(`community/threads/${threadId}/posts`, { method: 'POST', body: { body: field.value, quote: Number(quoteField.value) || 0 } });
      busy = false;
      button.disabled = false;
      if (!r.ok) {
        say(note, r.data?.message || SAID.failed, true);
        return;
      }
      if (!lastPage) {
        go(r.data.url);
        return;
      }
      const li = placePost(r.data.html, { fresh: true });
      after = Math.max(after, Number(r.data.post?.id) || 0);
      field.value = '';
      quoteField.value = '';
      quoting.hidden = true;
      fit();
      say(note, '');
      li?.scrollIntoView({ block: 'nearest' });
    });

    list.addEventListener('click', (e) => {
      const q = e.target.closest('[data-quote]');
      if (!q) return;
      e.preventDefault();
      const li = q.closest('.cpost');
      quoteField.value = q.dataset.quote;
      quoting.querySelector('[data-quoting-text]').textContent = `Quoting ${q.dataset.quoteName}: ${li?.dataset.quoteText || ''}`;
      quoting.hidden = false;
      field.focus();
      replyForm.scrollIntoView({ block: 'nearest' });
    });
  }

  if (list) {
    list.addEventListener('click', (e) => {
      const li = e.target.closest('.cpost');
      if (!li) return;
      const chip = e.target.closest('button[data-react]');
      if (chip) return void react(li, chip.dataset.react, chip.getAttribute('aria-pressed') !== 'true');
      const open = e.target.closest('[data-react-open]');
      if (open) return void (pickerFor === li && !picker?.hidden ? closePicker() : openPicker(li, open));
      if (e.target.closest('[data-edit]')) return void editPost(li);
      if (e.target.closest('[data-delete]')) return void deletePost(li);
      if (e.target.closest('[data-report]')) return void reportPost(li);
    });
  }

  // —— What changed while the thread is open ——

  if (list && threadId) {
    const notice = document.querySelector('[data-community-new-posts]');
    let waiting = 0;
    let polling = false;
    async function poll(forced = false) {
      if (polling || document.hidden || (live.on && forced !== true)) return;
      polling = true;
      const r = await call(`community/threads/${threadId}/updates?after=${after}&since=${since}`);
      polling = false;
      if (!r.ok || !r.data) return;
      since = Number(r.data.now) || since;
      for (const p of r.data.posts || []) {
        if (p.new && !lastPage) {
          waiting += 1;
          after = Math.max(after, p.id);
          continue;
        }
        if (!p.new && !document.getElementById(`post-${p.id}`)) continue;
        placePost(p.html, { fresh: p.new });
        if (p.new) after = Math.max(after, p.id);
      }
      if (waiting && notice) {
        notice.hidden = false;
        notice.innerHTML = '';
        const a = document.createElement('a');
        a.href = `${location.pathname}?page=0#reply`;
        a.textContent = SAID.newPosts(waiting);
        notice.append(a);
      }
      // Too much changed to bring in: the view again, from the server.
      if (r.data.more) go(location.href, { replace: true, focus: false });
    }
    const timer = setInterval(poll, POLL_MS);
    const seen = () => { if (!document.hidden) poll(true); };
    document.addEventListener('visibilitychange', seen);
    stops.push(() => { clearInterval(timer); document.removeEventListener('visibilitychange', seen); });
    // chat-app.js: a new post in this topic, said by the realtime server (board_post).
    topicNow.fn = () => poll(true);
  }

  unwire = () => {
    for (const stop of stops) stop();
    topicNow.fn = null;
    topicNow.thread = 0;
  };
}

// Reactions: the chips toggle yours; React opens the set.
let picker = null;
let pickerFor = null;

function closePicker(back = false) {
  if (!picker || picker.hidden) return;
  picker.hidden = true;
  const opener = pickerFor?.querySelector('[data-react-open]');
  opener?.setAttribute('aria-expanded', 'false');
  if (back) opener?.focus();
  pickerFor = null;
}

/** chat-app.js: a chat line's reactions go over the socket (lineReact.fn), a post's through the site. */
export const lineReact = { fn: null };

async function react(li, key, on) {
  if (!li.dataset.post) return void lineReact.fn?.(li, key, on);
  const id = Number(li.dataset.post);
  const r = await call(`community/posts/${id}/reactions`, { method: 'POST', body: { e: key, on } });
  if (!r.ok) {
    showLine(li, r.data?.message || SAID.failed, true);
    return;
  }
  const box = fromHtml(r.data.html);
  li.querySelector('[data-reactions]')?.replaceWith(box);
}

function openPicker(li, opener) {
  if (!picker) {
    picker = document.createElement('div');
    picker.className = 'cpicker';
    picker.setAttribute('role', 'group');
    picker.setAttribute('aria-label', 'Add a reaction');
    for (const [key, glyph, label, art] of REACTIONS) {
      const b = document.createElement('button');
      b.type = 'button';
      b.dataset.pick = key;
      b.title = label;
      b.setAttribute('aria-label', label);
      if (art) {
        const cut = document.createElement('span');
        cut.className = `cchip-art cchip-${key}`;
        cut.setAttribute('aria-hidden', 'true');
        b.append(cut);
      } else b.textContent = glyph;
      picker.append(b);
    }
    picker.hidden = true;
    document.body.append(picker);
    picker.addEventListener('click', (e) => {
      const b = e.target.closest('[data-pick]');
      if (!b || !pickerFor) return;
      const li = pickerFor;
      closePicker(true);
      react(li, b.dataset.pick, b.getAttribute('aria-pressed') !== 'true');
    });
    picker.addEventListener('keydown', (e) => {
      const items = [...picker.querySelectorAll('button')];
      const at = items.indexOf(document.activeElement);
      if (e.key === 'Escape') { e.preventDefault(); closePicker(true); }
      else if (e.key === 'ArrowRight') { e.preventDefault(); items[(at + 1) % items.length].focus(); }
      else if (e.key === 'ArrowLeft') { e.preventDefault(); items[(at - 1 + items.length) % items.length].focus(); }
    });
    document.addEventListener('pointerdown', (e) => {
      if (picker.hidden || picker.contains(e.target) || e.target.closest?.('[data-react-open]')) return;
      closePicker();
    });
  }
  pickerFor = li;
  for (const b of picker.querySelectorAll('[data-pick]')) {
    const mine = li.querySelector(`[data-reactions] [data-react="${b.dataset.pick}"], .cchan-chips [data-react="${b.dataset.pick}"]`)?.getAttribute('aria-pressed') === 'true';
    b.setAttribute('aria-pressed', String(mine));
  }
  picker.hidden = false;
  const r = opener.getBoundingClientRect();
  const w = picker.offsetWidth;
  picker.style.left = `${Math.max(8, Math.min(window.scrollX + r.left, window.scrollX + document.documentElement.clientWidth - w - 8))}px`;
  picker.style.top = `${window.scrollY + r.bottom + 6}px`;
  opener.setAttribute('aria-expanded', 'true');
  picker.querySelector('button')?.focus();
}

/** A line under a post (what an action did), gone after a while. */
function showLine(li, text, bad = false) {
  let p = li.querySelector('.cpost-said');
  if (!p) {
    p = document.createElement('p');
    p.className = 'csaid cpost-said';
    p.setAttribute('role', 'status');
    li.querySelector('.cpost-main')?.append(p);
  }
  say(p, text, bad);
  clearTimeout(p.timer);
  p.timer = setTimeout(() => p.remove(), 8000);
}

function editPost(li) {
  const body = li.querySelector('[data-post-body]');
  if (!body || li.querySelector('.cedit')) return;
  const form = document.createElement('form');
  form.className = 'cedit';
  const field = document.createElement('textarea');
  field.className = 'ctext';
  field.setAttribute('aria-label', 'Your post');
  field.maxLength = 4000;
  // The words as written: each paragraph and line of the drawn post.
  field.value = [...body.querySelectorAll('p')].map((p) => p.innerText.replace(/\r/g, '')).join('\n\n');
  const row = document.createElement('div');
  row.className = 'cedit-row';
  row.innerHTML = '<button class="cbutton" type="submit">Save</button><button class="cbutton cbutton-quiet" type="button" data-cancel>Cancel</button>';
  form.append(field, row);
  body.hidden = true;
  body.after(form);
  grow(field);
  field.focus();
  const cancel = () => { form.remove(); body.hidden = false; li.querySelector('[data-edit]')?.focus(); };
  row.querySelector('[data-cancel]').addEventListener('click', cancel);
  field.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') { e.preventDefault(); cancel(); }
  });
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const r = await call(`community/posts/${li.dataset.post}`, { method: 'PUT', body: { body: field.value } });
    if (!r.ok) {
      showLine(li, r.data?.message || SAID.failed, true);
      return;
    }
    form.remove();
    li.replaceWith(fromHtml(r.data.html));
  });
}

async function deletePost(li) {
  if (!window.confirm(SAID.deleteAsk)) return;
  const r = await call(`community/posts/${li.dataset.post}`, { method: 'DELETE' });
  if (!r.ok) {
    showLine(li, r.data?.message || SAID.failed, true);
    return;
  }
  if (r.data?.thread?.status === 'deleted') {
    go(`/chat/${main.dataset.board}/`, { replace: true });
    return;
  }
  li.replaceWith(fromHtml(r.data.html));
}

function reportPost(li) {
  if (li.querySelector('.cedit')) return;
  const form = document.createElement('form');
  form.className = 'cedit';
  form.innerHTML = '<label class="clabel" for="creport-' + li.dataset.post + '">What is wrong with this post? (optional)</label>'
    + '<input class="cinput" id="creport-' + li.dataset.post + '" name="why" type="text" maxlength="300" autocomplete="off" />'
    + '<div class="cedit-row"><button class="cbutton" type="submit">Send report</button><button class="cbutton cbutton-quiet" type="button" data-cancel>Cancel</button></div>';
  li.querySelector('.cpost-actions')?.after(form);
  form.why.focus();
  const cancel = () => { form.remove(); li.querySelector('[data-report]')?.focus(); };
  form.querySelector('[data-cancel]').addEventListener('click', cancel);
  form.why.addEventListener('keydown', (e) => { if (e.key === 'Escape') { e.preventDefault(); cancel(); } });
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const r = await call(`community/posts/${li.dataset.post}/report`, { method: 'POST', body: { why: form.why.value } });
    form.remove();
    if (!r.ok) showLine(li, r.data?.message || SAID.failed, true);
    else showLine(li, r.data?.status === 'already' ? SAID.already : SAID.reported);
  });
}

wirePage();

// —— The chat app for signed-in players (community/chat-app.js): channels, IMs, buddies, threads, Who's around ——

if (main?.matches('.cx[data-signed="1"]')) {
  // The moves first, then the bell (src/Notifications.php), so the app can tell it when something happens.
  navReady.then(() => import(`./notifications.js${v}`))
    .then((n) => n.startNotifications({ call, nav }))
    .catch((e) => { console.warn('notifications', e); return null; })
    .then((notif) => import(`./chat-app.js${v}`).then((m) => m.start({ main, call, say, SAID, openPicker, live, lineReact, topicNow, notif, nav })))
    .catch((e) => console.warn('chat app', e));
}

// —— The chat app's header on a phone: the menu's links fold behind a menu button (SiteNav app layout) ——

const appNav = document.querySelector('.site-nav-app');
const menuButton = appNav?.querySelector('.site-nav-menu');
if (appNav && menuButton) {
  const links = appNav.querySelector('.site-nav-links');
  const phone = matchMedia('(max-width: 720px)');
  const setOpen = (on, focusBack = false) => {
    appNav.dataset.menu = on ? 'open' : 'closed';
    menuButton.setAttribute('aria-expanded', String(on));
    if (on) links?.querySelector('a')?.focus({ preventScroll: true });
    else if (focusBack) menuButton.focus({ preventScroll: true });
  };
  menuButton.hidden = false;
  setOpen(false);
  menuButton.addEventListener('click', () => setOpen(appNav.dataset.menu !== 'open'));
  appNav.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && appNav.dataset.menu === 'open' && phone.matches) {
      // A submenu panel open inside closes first (site/theme.js has it).
      if (links?.querySelector('.site-nav-panel:not([hidden])')) return;
      e.preventDefault();
      setOpen(false, true);
    }
  });
  document.addEventListener('pointerdown', (e) => {
    if (appNav.dataset.menu === 'open' && !appNav.contains(e.target)) setOpen(false);
  });
  links?.addEventListener('click', (e) => { if (e.target.closest('a')) setOpen(false); });
  phone.addEventListener('change', () => setOpen(false));
}
