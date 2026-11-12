/**
 * The Known gaps page's filter (src/RestorationGaps.php draws the whole list
 * server-side; this only adds a row of chips, as the restoration report's
 * systems board has): Everything, then one chip per state with its count.
 * A chip shows that state's gaps and hides the areas left empty.
 */
(() => {
  for (const board of document.querySelectorAll('[data-gaps-board]')) {
    const bar = board.querySelector('.gaps-filters');
    const items = [...board.querySelectorAll('.gaps-item')];
    if (!bar || items.length === 0) continue;
    const states = new Map();
    for (const li of items) {
      const s = li.dataset.state;
      const label = s === 'fixed' ? 'Fixed' : li.querySelector('.gaps-state')?.textContent || s;
      const seen = states.get(s) || { label, tone: li.style.getPropertyValue('--tone'), n: 0 };
      seen.n++;
      states.set(s, seen);
    }
    let active = 'all';
    const chips = [];
    const chip = (id, label, n, tone) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'chip';
      b.dataset.state = id;
      if (tone) {
        const dot = document.createElement('span');
        dot.className = 'dot';
        dot.style.background = tone;
        b.append(dot);
      }
      const count = document.createElement('span');
      count.className = 'count';
      count.textContent = String(n);
      b.append(label, count);
      b.addEventListener('click', () => {
        active = id;
        apply();
      });
      chips.push(b);
      bar.append(b);
    };
    const apply = () => {
      for (const b of chips) b.setAttribute('aria-pressed', String(b.dataset.state === active));
      for (const li of items) li.hidden = active !== 'all' && li.dataset.state !== active;
      for (const area of board.querySelectorAll('.gaps-area')) area.hidden = ![...area.querySelectorAll('.gaps-item')].some((li) => !li.hidden);
    };
    chip('all', 'Everything', items.length, null);
    const ORDER = ['fixing', 'known', 'unreleased', 'fixed', 'lost'];
    for (const [id, s] of [...states].sort(([a], [b]) => ORDER.indexOf(a) - ORDER.indexOf(b))) chip(id, s.label, s.n, s.tone);
    bar.hidden = false;
    apply();
  }
})();
