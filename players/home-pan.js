/**
 * The home street's camera on a player's page (src/HomeLayout.php renderStreet,
 * a version 2 layout): look along the whole street as the game's Camera Mode
 * does (the game's features/camera-mode-feature.js: arrows pan at 0.6 px per
 * ms; game.js eases the camera toward its target by 0.08 a frame; each
 * parallax layer moves f px for each px the camera moves, homes-feature
 * drawLandscape), held inside the street's ends.
 *
 * - Arrow keys while the street (or a thing on it) has focus; Home and End
 *   jump to the ends. Tab to a thing on the street pans to it.
 * - Drag or swipe (touch-action pan-y keeps the page scrolling up and down);
 *   a horizontal wheel or trackpad swipe.
 * - prefers-reduced-motion: the camera goes straight to where it is sent.
 * - Art far from the view waits in data-src until the camera comes within
 *   half a view of it.
 *
 * The page reads without it: the server draws the starting frame (--cam).
 * The pure parts are exported for the game repo's tests (tests/home-pan.test.js).
 */

/** Camera Mode's pan speed, view px per ms (features/camera-mode-feature.js PAN_SPEED). */
export const PAN_SPEED = 0.6;
/** The game's camera follow, per 60 fps frame (game.js easeCameraAxis with dampingAlpha(0.08, dt)). */
export const EASE = 0.08;
/** Closer than this (view px) the camera is where it was sent. */
export const SETTLE = 0.05;
/** A press that moves less than this (CSS px) is a click, not a drag. */
export const DRAG_SLOP = 5;
/** How far a Tab-reached thing may sit from the edge before the camera moves to it (view px). */
export const EDGE = 24;

/** The camera's x held inside the street: 0 to street width less the view. */
export function clampCam(x, sw, w) {
  return Math.max(0, Math.min(Math.max(0, sw - w), Number(x) || 0));
}

/** A frame-rate-independent share of the way (game.js movement.js dampingAlpha). */
export function easeShare(dtMs, perFrame = EASE) {
  const frames = Math.max(0, Math.min(10, (Number(dtMs) || 0) / (1000 / 60)));
  return 1 - Math.pow(1 - perFrame, frames);
}

/** One frame of the camera toward its target; straight there with reduced motion or when close. */
export function easeCam(cam, target, dtMs, reduced = false) {
  if (reduced || Math.abs(target - cam) <= SETTLE) return target;
  return cam + (target - cam) * easeShare(dtMs);
}

/** The target moved by held keys (dir -1, 0 or 1) over dtMs, as Camera Mode steps (at most 100 ms a frame). */
export function stepTarget(target, dir, dtMs, sw, w) {
  return clampCam(target + Math.sign(dir) * PAN_SPEED * Math.max(0, Math.min(100, Number(dtMs) || 0)), sw, w);
}

/** Where the camera goes so a box [x, y, w, h] on the street is in view: unchanged when it already is, else centred on it. */
export function camForBox(box, cam, sw, w) {
  const [x, , bw] = box.map(Number);
  if (x >= cam + EDGE && x + bw <= cam + w - EDGE) return cam;
  return clampCam(x + bw / 2 - w / 2, sw, w);
}

/** A CSS px drag as view px: the stage is w view px across `cssWidth` CSS px. */
export function dragToView(dxCss, cssWidth, w) {
  return cssWidth > 0 ? (dxCss * w) / cssWidth : 0;
}

/** Which way a thing's name opens at this camera: 'start' near the left edge, 'end' near the right, else ''. */
export function tipSide(box, cam, w) {
  const mid = (Number(box[0]) + Number(box[2]) / 2 - cam) / w;
  return mid < 0.12 ? 'start' : mid > 0.88 ? 'end' : '';
}

function start(figure) {
  const stage = figure.querySelector('.hd-stage');
  if (!stage || figure.dataset.panReady) return;
  const W = Number(stage.dataset.w) || 0;
  const SW = Number(stage.dataset.sw) || 0;
  if (!(W > 0) || SW <= W) return;
  figure.dataset.panReady = '1';
  const reducedQuery = globalThis.matchMedia?.('(prefers-reduced-motion: reduce)');
  const reduced = () => Boolean(reducedQuery?.matches);
  let cam = clampCam(Number(stage.dataset.cx) || 0, SW, W);
  let target = cam;
  const held = { left: false, right: false };
  let frame = 0;
  let last = 0;

  figure.classList.add('hd-live');
  const hint = figure.querySelector('.hd-hint');
  if (hint) hint.hidden = false;

  const spots = [...stage.querySelectorAll('.hd-spot')];
  const boxOf = (el) => String(el.dataset.b || '').split(',').map(Number);
  const tips = () => {
    for (const el of spots) {
      const side = tipSide(boxOf(el), cam, W);
      el.classList.toggle('hd-tip-start', side === 'start');
      el.classList.toggle('hd-tip-end', side === 'end');
      el.style.removeProperty('--hd-tip-in');
    }
  };
  const show = () => {
    stage.style.setProperty('--cam', cam.toFixed(2));
    figure.dataset.cam = cam.toFixed(1);
  };

  // Art waiting in data-src loads when it comes within half a view of the stage.
  const load = (img) => {
    if (img.dataset.srcset) img.srcset = img.dataset.srcset;
    if (img.dataset.src) img.src = img.dataset.src;
    delete img.dataset.src;
    delete img.dataset.srcset;
  };
  const waiting = [...stage.querySelectorAll('img[data-src]')];
  if (waiting.length && typeof IntersectionObserver === 'function') {
    const io = new IntersectionObserver((entries) => {
      for (const e of entries) {
        if (!e.isIntersecting) continue;
        load(e.target);
        io.unobserve(e.target);
      }
    }, { root: stage, rootMargin: '0px 50% 0px 50%' });
    for (const img of waiting) io.observe(img);
  } else {
    for (const img of waiting) load(img);
  }

  const tick = (now) => {
    const dt = last ? now - last : 16;
    last = now;
    const dir = (held.right ? 1 : 0) - (held.left ? 1 : 0);
    if (dir) target = stepTarget(target, dir, dt, SW, W);
    cam = easeCam(cam, target, dt, reduced());
    show();
    if (dir || cam !== target) {
      frame = requestAnimationFrame(tick);
    } else {
      frame = 0;
      last = 0;
      tips();
    }
  };
  const run = () => {
    if (!frame) {
      last = 0;
      frame = requestAnimationFrame(tick);
    }
  };
  const go = (x) => {
    target = clampCam(x, SW, W);
    run();
  };

  stage.addEventListener('keydown', (e) => {
    if (e.altKey || e.ctrlKey || e.metaKey) return;
    if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
      held[e.key === 'ArrowLeft' ? 'left' : 'right'] = true;
      e.preventDefault();
      run();
    } else if (e.key === 'Home' || e.key === 'End') {
      go(e.key === 'Home' ? 0 : SW - W);
      e.preventDefault();
    }
  });
  const release = (e) => {
    if (!e || e.key === 'ArrowLeft') held.left = false;
    if (!e || e.key === 'ArrowRight') held.right = false;
  };
  stage.addEventListener('keyup', release);
  stage.addEventListener('focusout', (e) => {
    if (!stage.contains(e.relatedTarget)) release();
  });
  // Tab to a thing on the street: the camera brings it into view.
  stage.addEventListener('focusin', (e) => {
    const spot = e.target.closest?.('.hd-spot');
    if (spot) go(camForBox(boxOf(spot), target, SW, W));
  });
  // overflow: clip keeps the stage from scrolling to a focused thing; this catches browsers without it.
  stage.addEventListener('scroll', () => {
    stage.scrollLeft = 0;
  });

  // Drag and swipe.
  let press = null;
  let swallowClick = false;
  stage.addEventListener('pointerdown', (e) => {
    if (e.button !== 0) return;
    press = { id: e.pointerId, x: e.clientX, from: target, dragging: false };
  });
  stage.addEventListener('pointermove', (e) => {
    if (!press || e.pointerId !== press.id) return;
    const dx = e.clientX - press.x;
    if (!press.dragging) {
      if (Math.abs(dx) < DRAG_SLOP) return;
      press.dragging = true;
      stage.setPointerCapture?.(e.pointerId);
      figure.classList.add('hd-dragging');
    }
    target = clampCam(press.from - dragToView(dx, stage.getBoundingClientRect().width, W), SW, W);
    cam = target;
    show();
  });
  const endPress = (e) => {
    if (!press || (e && e.pointerId !== press.id)) return;
    if (press.dragging) {
      swallowClick = true;
      figure.classList.remove('hd-dragging');
      tips();
    }
    press = null;
  };
  stage.addEventListener('pointerup', endPress);
  stage.addEventListener('pointercancel', endPress);
  // A drag that ends on a link is not a click on it.
  stage.addEventListener('click', (e) => {
    if (!swallowClick) return;
    swallowClick = false;
    e.preventDefault();
    e.stopPropagation();
  }, true);

  // A sideways wheel or trackpad swipe (Shift and the wheel too); up and down stay the page's.
  stage.addEventListener('wheel', (e) => {
    const sideways = Math.abs(e.deltaX) > Math.abs(e.deltaY) ? e.deltaX : e.shiftKey ? e.deltaY : 0;
    if (!sideways) return;
    const unit = e.deltaMode === 1 ? 16 : e.deltaMode === 2 ? stage.clientWidth : 1;
    e.preventDefault();
    target = clampCam(target + dragToView(sideways * unit, stage.getBoundingClientRect().width, W), SW, W);
    cam = reduced() ? target : cam;
    run();
  }, { passive: false });

  // QA and the page's own tests: where the camera is and a way to move it.
  figure.homePan = {
    get cam() { return cam; },
    get target() { return target; },
    get ends() { return [0, Math.max(0, SW - W)]; },
    to: go,
  };
  show();
}

if (typeof document !== 'undefined') {
  const all = () => document.querySelectorAll('figure.hd-pan').forEach(start);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', all, { once: true });
  else all();
}
