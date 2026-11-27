/**
 * Support's drop zone (Austin 2026-11-27: "Can we add a drag and drop zone for photos/files"; ours,
 * Glitch had no web support pages): on /chat/support/<id>/ and /chat/support/new/ (src/SupportPages.php)
 * the plain file field is joined by a dashed box to drop pictures or a text file on, or to click or tap
 * to choose them. Dropping anywhere on the report's pane works too, and pasting a screenshot into the
 * form adds it. The files chosen show under the box (a thumbnail for a picture, the name and size for
 * each) with a Remove button, and are put into the form's real field (DataTransfer), so the form's own
 * multipart POST sends them; choosing more adds to them.
 *
 * Each file is checked here as the server will (SupportPages::sortUploads, ReportPicture::acceptUpload,
 * ReportFile::accept): the kind by the name's ending, how many of each, a text file's and a picture's
 * size. One that would be refused is not added and a plain line says why. The server still checks it all.
 *
 * Without JavaScript, or in a browser that cannot set a file field (no DataTransfer), the page keeps the
 * plain field. The pure parts (no page) are exported for the game's tests (tests/support-pages.test.js).
 */

/** The server's limits (SupportPages, ReportPicture, ReportFile). */
export const LIMITS = Object.freeze({
  pictures: 4, // ReportPicture::MAX_PER_MESSAGE
  texts: 2, // ReportFile::MAX_PER_MESSAGE
  pictureBytes: 10_485_760, // ReportPicture::UPLOAD_MAX_BYTES (10 MB)
  textBytes: 262_144, // ReportFile::MAX_BYTES (256 KB)
});
/** Endings a picture may have (SupportPages::PICTURE_EXTENSIONS) and a text file (ReportFile::EXTENSIONS). */
export const PICTURE_EXTENSIONS = Object.freeze(['jpg', 'jpeg', 'png', 'webp']);
export const TEXT_EXTENSIONS = Object.freeze(['txt', 'log']);
/** The ending a file with no known ending is given from its type (a pasted screenshot, a picture saved without one). */
const TYPE_EXTENSIONS = { 'image/png': 'png', 'image/jpeg': 'jpg', 'image/webp': 'webp', 'text/plain': 'txt' };

export const SAID = Object.freeze({
  type: (name) => `${name} was not added: only pictures (JPEG, PNG or WebP) and text files (.txt or .log) can be added.`,
  pictureBytes: (name) => `${name} was not added: a picture can be up to 10 MB.`,
  textBytes: (name) => `${name} was not added: a text file can be up to 256 KB. Send the part around the problem.`,
  empty: (name) => `${name} was not added: it is empty.`,
  pictures: (name) => `${name} was not added: a message carries at most ${LIMITS.pictures} pictures.`,
  texts: (name) => `${name} was not added: a message carries at most ${LIMITS.texts} text files.`,
  already: (name) => `${name} is already added.`,
  added: (n) => (n === 1 ? '1 file added.' : `${n} files added.`),
  removed: (name) => `${name} removed.`,
  remove: (name) => `Remove ${name}`,
  list: 'Files to send',
});

/** PURE: a name's ending, lower case ('' when it has none). */
export function extensionOf(name) {
  const base = String(name || '').split(/[\\/]/).pop();
  const dot = base.lastIndexOf('.');
  return dot > 0 ? base.slice(dot + 1).toLowerCase() : '';
}

/** PURE: what the server takes a file for, by its name as SupportPages::sortUploads does: 'picture', 'text' or null (refused). */
export function kindOf(name) {
  const ext = extensionOf(name);
  if (PICTURE_EXTENSIONS.includes(ext)) return 'picture';
  if (TEXT_EXTENSIONS.includes(ext)) return 'text';
  return null;
}

/**
 * PURE: the name a file goes up with. Its own when the ending is known; one with no ending at all but a
 * picture's or a text type gets that ending (the server reads only the name); a pasted file is named
 * for what it is. Anything else keeps its name, to be refused by it.
 */
export function uploadName(name, type, { pasted = 0 } = {}) {
  const ext = TYPE_EXTENSIONS[String(type || '').toLowerCase()] || '';
  if (pasted > 0 && ext) return `${ext === 'txt' ? 'pasted-text' : 'pasted-picture'}-${pasted}.${ext}`;
  const n = String(name || '').trim() || 'file';
  return extensionOf(n) === '' && ext ? `${n}.${ext}` : n;
}

/** PURE: the same file chosen twice (its name, size and time). */
export function sameFile(a, b) {
  return a.name === b.name && a.size === b.size && (a.lastModified || 0) === (b.lastModified || 0);
}

/**
 * PURE: files added to the ones already chosen, each checked as the server will. `files` are File-like
 * ({ name, size, type, lastModified }). Returns { files, added, refused } where files is the whole list to
 * send (the chosen ones first, then the new ones in order), added the new ones kept and refused a list of
 * { file, why } with a plain line each.
 */
export function addFiles(chosen, incoming) {
  const files = [...(chosen || [])];
  const added = [];
  const refused = [];
  const count = (kind) => files.filter((f) => kindOf(f.name) === kind).length;
  for (const file of incoming || []) {
    const name = String(file?.name || 'file');
    const kind = kindOf(name);
    const size = Number(file?.size) || 0;
    let why = '';
    if (files.some((f) => sameFile(f, file))) why = SAID.already(name);
    else if (!kind) why = SAID.type(name);
    else if (kind === 'picture' && size > LIMITS.pictureBytes) why = SAID.pictureBytes(name);
    else if (kind === 'text' && size > LIMITS.textBytes) why = SAID.textBytes(name);
    else if (size === 0) why = SAID.empty(name);
    else if (kind === 'picture' && count('picture') >= LIMITS.pictures) why = SAID.pictures(name);
    else if (kind === 'text' && count('text') >= LIMITS.texts) why = SAID.texts(name);
    if (why) refused.push({ file, why });
    else {
      files.push(file);
      added.push(file);
    }
  }
  return { files, added, refused };
}

/** PURE: the list without the file at `index`. */
export function removeFile(chosen, index) {
  return (chosen || []).filter((_, i) => i !== index);
}

/** PURE: a size as a player reads it: KB as the server says it (ReportFile::size, at least 1 KB), MB from 1 MB. */
export function sizeText(bytes) {
  const b = Math.max(0, Number(bytes) || 0);
  if (b >= 1_048_576) return `${(b / 1_048_576).toFixed(1).replace(/\.0$/, '')} MB`;
  return `${Math.max(1, Math.round(b / 1024))} KB`;
}

/** PURE: the line after an add: what was refused and why, else how many were added. */
export function addedLine({ added = [], refused = [] }) {
  const why = refused.map((r) => r.why).join(' ');
  if (why) return { text: added.length ? `${SAID.added(added.length)} ${why}` : why, bad: true };
  return { text: added.length ? SAID.added(added.length) : '', bad: false };
}

/** Whether this browser can put files into a file field (DataTransfer's constructor and a settable files). */
export function canSetFiles(input) {
  try {
    const dt = new DataTransfer();
    return Boolean(dt.items && input && 'files' in input);
  } catch {
    return false;
  }
}

/** The files a drag carries (not a dragged link or words). */
const carriesFiles = (e) => Array.from(e.dataTransfer?.types || []).includes('Files');

/**
 * The drop zone wired on one Support form; `area` is where a drop is caught (the report's pane). Returns
 * a stop function (community.js wirePage calls it when the view goes), or null when the page keeps the
 * plain field.
 */
export function wireSupportFiles(form, { area = form } = {}) {
  const input = form?.querySelector('input[type="file"][name="files[]"]');
  const box = form?.querySelector('[data-drop]');
  const zone = box?.querySelector('[data-drop-zone]');
  const list = box?.querySelector('[data-chosen]');
  const note = box?.querySelector('[data-drop-said]');
  if (!input || !box || !zone || !list || !canSetFiles(input)) return null;

  let chosen = [];
  let pasted = 0;
  let depth = 0;
  const urls = new Map();
  const stops = [];
  const on = (el, type, fn, opts) => {
    el.addEventListener(type, fn, opts);
    stops.push(() => el.removeEventListener(type, fn, opts));
  };
  const say = ({ text, bad }) => {
    note.textContent = text || '';
    if (bad) note.dataset.tone = 'bad';
    else delete note.dataset.tone;
  };
  const urlOf = (file) => {
    if (!urls.has(file)) urls.set(file, URL.createObjectURL(file));
    return urls.get(file);
  };
  const forget = (file) => {
    const url = urls.get(file);
    if (url) URL.revokeObjectURL(url);
    urls.delete(file);
  };

  /** The real field holds exactly the chosen files, so the form's own POST sends them. */
  function sync() {
    const dt = new DataTransfer();
    for (const f of chosen) dt.items.add(f);
    input.files = dt.files;
  }

  function draw() {
    list.replaceChildren(...chosen.map((file, i) => {
      const li = document.createElement('li');
      li.className = 'csupport-chosen-item';
      li.dataset.kind = kindOf(file.name) || '';
      if (li.dataset.kind === 'picture') {
        const img = document.createElement('img');
        img.className = 'csupport-chosen-thumb';
        img.alt = '';
        img.src = urlOf(file);
        li.append(img);
      } else {
        const tag = document.createElement('span');
        tag.className = 'csupport-chosen-thumb csupport-chosen-ext';
        tag.setAttribute('aria-hidden', 'true');
        tag.textContent = extensionOf(file.name).toUpperCase() || 'TXT';
        li.append(tag);
      }
      const text = document.createElement('span');
      text.className = 'csupport-chosen-text';
      const name = document.createElement('span');
      name.className = 'csupport-chosen-name';
      name.textContent = file.name;
      const size = document.createElement('small');
      size.className = 'cmuted csupport-chosen-size';
      size.textContent = sizeText(file.size);
      text.append(name, size);
      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'cbutton cbutton-quiet csupport-chosen-remove';
      remove.dataset.remove = String(i);
      remove.setAttribute('aria-label', SAID.remove(file.name));
      remove.textContent = 'Remove';
      li.append(text, remove);
      return li;
    }));
    list.hidden = chosen.length === 0;
  }

  function add(files) {
    const incoming = Array.from(files || []).map((f) => {
      const name = uploadName(f.name, f.type);
      return name === f.name ? f : new File([f], name, { type: f.type, lastModified: f.lastModified });
    });
    if (!incoming.length) return;
    const r = addFiles(chosen, incoming);
    chosen = r.files;
    sync();
    draw();
    say(addedLine(r));
  }

  // Taken over: the box shows, the plain field is kept (visually hidden) and posts what the box holds.
  box.hidden = false;
  form.dataset.dropReady = '';
  stops.push(() => { delete form.dataset.dropReady; });
  // A field the browser kept (Back to the page) starts the list.
  if (input.files?.length) {
    const kept = Array.from(input.files);
    chosen = [];
    add(kept);
  }

  on(zone, 'click', () => input.click());
  // Chosen with the picker: added to the ones already there (the browser's field alone would replace them).
  on(input, 'change', () => {
    const picked = Array.from(input.files || []);
    sync();
    add(picked.filter((f) => !chosen.includes(f)));
  });
  on(list, 'click', (e) => {
    const b = e.target.closest?.('[data-remove]');
    if (!b) return;
    const i = Number(b.dataset.remove);
    const file = chosen[i];
    if (!file) return;
    forget(file);
    chosen = removeFile(chosen, i);
    sync();
    draw();
    say({ text: SAID.removed(file.name), bad: false });
    (list.querySelector(`[data-remove="${Math.min(i, chosen.length - 1)}"]`) || zone).focus();
  });

  // A drag over the pane lights the box; a drop anywhere on it adds the files.
  const over = (e) => {
    if (!carriesFiles(e)) return;
    e.preventDefault();
    e.dataTransfer.dropEffect = 'copy';
    box.dataset.over = '';
  };
  on(area, 'dragenter', (e) => {
    if (!carriesFiles(e)) return;
    depth += 1;
    over(e);
  });
  on(area, 'dragover', over);
  on(area, 'dragleave', (e) => {
    if (!carriesFiles(e)) return;
    depth = Math.max(0, depth - 1);
    if (depth === 0) delete box.dataset.over;
  });
  on(area, 'drop', (e) => {
    if (!carriesFiles(e)) return;
    e.preventDefault();
    depth = 0;
    delete box.dataset.over;
    add(e.dataTransfer.files);
  });
  // A drop just outside the pane never opens the file in place of the page (and the words with it).
  const guard = (e) => {
    if (carriesFiles(e) && !area.contains(e.target)) e.preventDefault();
  };
  on(document, 'dragover', guard);
  on(document, 'drop', guard);

  // A pasted screenshot (Cmd or Ctrl+V) in the form is added; pasted words paste as usual.
  on(form, 'paste', (e) => {
    const files = Array.from(e.clipboardData?.files || []);
    if (!files.length) return;
    const words = Array.from(e.clipboardData.types || []).includes('text/plain');
    if (!words) e.preventDefault();
    add(files.map((f) => (kindOf(f.name) && !/^image\.(png|jpe?g|webp)$/i.test(f.name)
      ? f
      : new File([f], uploadName(f.name, f.type, { pasted: (pasted += 1) }), { type: f.type, lastModified: f.lastModified }))));
  });

  return () => {
    for (const stop of stops.splice(0)) stop();
    for (const file of [...urls.keys()]) forget(file);
  };
}
