/**
 * The buddy button on a player's page (/players/<name>/, src/PlayerPages.php
 * buddyControl): "Add to buddy list" or "Remove from buddy list", as
 * glitch.com's profiles had (friendAdd / friendRemove on the archived
 * profile). The page works without this script (the form posts to
 * PlayerPages::buddy and comes back); with it the button calls
 * groddle/v1/buddies (src/Buddies.php) and changes in place.
 *
 * The REST nonce is the one the page drew into the form; a stale one is
 * swapped for a fresh one from admin-ajax.php?action=rest-nonce, as the
 * console and the game's js/minn-account.js do. keepNew=1 leaves who added
 * you marked new, so the game still tells you at your next sign-in.
 */
(() => {
  const NONCE = /^[0-9a-f]{10}$/;
  const SAID = {
    added: (name) => `${name} is on your buddy list now.`,
    removed: (name) => `${name} is off your buddy list.`,
    failed: 'That didn\'t work. Try again in a moment.',
  };

  function take(form) {
    const id = Number(form.dataset.buddyId) || 0;
    const name = form.dataset.buddyName || 'them';
    const button = form.querySelector('[data-buddy-do]');
    const doField = form.querySelector('input[name="do"]');
    const nonceField = form.querySelector('input[name="_wpnonce"]');
    const note = form.querySelector('.profile-buddy-note');
    if (!id || !button || !doField || !nonceField || !note) return;
    let nonce = nonceField.value;
    let busy = false;

    const set = (what) => {
      doField.value = what;
      button.dataset.buddyDo = what;
      button.textContent = what === 'remove' ? form.dataset.labelRemove : form.dataset.labelAdd;
    };
    const say = (text, bad = false) => {
      note.textContent = text;
      if (bad) note.dataset.tone = 'bad';
      else delete note.dataset.tone;
    };

    async function fresh() {
      const r = await fetch('/wp-admin/admin-ajax.php?action=rest-nonce', { credentials: 'same-origin', cache: 'no-store' });
      const t = (await r.text()).trim();
      nonce = r.ok && NONCE.test(t) ? t : '';
      return nonce;
    }

    async function call(remove, retried = false) {
      const r = await fetch(remove ? `/wp-json/groddle/v1/buddies/${id}?keepNew=1` : '/wp-json/groddle/v1/buddies?keepNew=1', {
        method: remove ? 'DELETE' : 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'X-WP-Nonce': nonce, ...(remove ? {} : { 'Content-Type': 'application/json' }) },
        body: remove ? undefined : JSON.stringify({ id }),
      });
      const data = await r.json().catch(() => null);
      if (r.status === 403 && data?.code === 'rest_cookie_invalid_nonce' && !retried && (await fresh())) return call(remove, true);
      const next = r.headers.get('X-WP-Nonce');
      if (next && NONCE.test(next)) nonce = next;
      return { ok: r.ok, code: data?.code || '', message: data?.message || '' };
    }

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (busy) return;
      busy = true;
      button.disabled = true;
      const remove = doField.value === 'remove';
      try {
        const r = await call(remove);
        // Already so (another tab): the button follows what is true.
        if (r.ok || r.code === (remove ? 'groddle_buddy_missing' : 'groddle_buddy_exists')) {
          set(remove ? 'add' : 'remove');
          say(remove ? SAID.removed(name) : SAID.added(name));
        } else if (r.code === 'groddle_buddy_ignored') {
          say(r.message, true);
          button.hidden = true;
        } else {
          say(r.message || SAID.failed, true);
        }
      } catch {
        say(SAID.failed, true);
      } finally {
        busy = false;
        button.disabled = false;
      }
    });
  }

  for (const form of document.querySelectorAll('[data-buddy-form]')) take(form);
})();
