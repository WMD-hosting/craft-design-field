/**
 * Design Field: a larger look at a layout tile.
 *
 * Hovering a layout tile, or reaching it with the keyboard, shows the same picture at
 * its real size in a floating card, with the layout's name under it. It only shows:
 * it never selects, saves or changes anything. Touch has no hover, so a tap selects as before.
 */
(() => {
  const TILE = 'craft-radio';
  const PICTURE = 'img[src$="#df-tile"]';
  const DELAY = 150;
  const GAP = 8;
  const EDGE = 8;
  const MAX_WIDTH = 480;

  let card = null;
  let timer = 0;
  let current = null;

  const tileOf = (target) => {
    const tile = target instanceof Element ? target.closest(TILE) : null;
    return tile && tile.querySelector(PICTURE) ? tile : null;
  };

  // The first ancestor that paints a background: the card takes its colours from the
  // panel it belongs to, so it follows the light and the dark theme without knowing either.
  const surfaceOf = (el) => {
    for (let node = el; node instanceof Element; node = node.parentElement || node.getRootNode().host) {
      const background = getComputedStyle(node).backgroundColor;
      if (background && background !== 'transparent' && !/,\s*0\)$/.test(background)) {
        return background;
      }
    }
    return 'Canvas';
  };

  const build = () => {
    const el = document.createElement('div');
    el.className = 'df-tile-preview';
    el.setAttribute('aria-hidden', 'true');
    if ('popover' in HTMLElement.prototype) {
      el.popover = 'manual';
    }
    Object.assign(el.style, {
      position: 'fixed',
      inset: 'auto',
      margin: '0',
      padding: '8px',
      border: '1px solid',
      borderRadius: '8px',
      boxShadow: '0 12px 32px rgb(0 0 0 / .28)',
      pointerEvents: 'none',
      zIndex: '2147483647',
      boxSizing: 'border-box',
      overflow: 'hidden',
    });
    const img = document.createElement('img');
    img.alt = '';
    Object.assign(img.style, {display: 'block', width: '100%', height: 'auto', borderRadius: '4px'});
    const caption = document.createElement('div');
    Object.assign(caption.style, {marginTop: '6px', fontSize: '12px', lineHeight: '1.3', fontWeight: '600'});
    el.append(img, caption);
    return el;
  };

  const place = (tile) => {
    const img = card.firstChild;
    const box = tile.getBoundingClientRect();
    // Stay inside the pane the panel is in (a slideout, the Live Preview editor), and the window.
    const pane = (tile.closest('craft-content-block-input') || document.documentElement).getBoundingClientRect();
    const left = Math.max(EDGE, pane.left);
    const right = Math.min(window.innerWidth - EDGE, pane.right);
    const natural = img.naturalWidth || MAX_WIDTH;
    const width = Math.min(natural + 18, MAX_WIDTH + 18, Math.max(160, right - left));

    card.style.width = `${width}px`;
    const height = card.getBoundingClientRect().height;
    const x = Math.min(Math.max(box.left + box.width / 2 - width / 2, left), Math.max(left, right - width));
    // Above the tile's row when there is room, else below it: never over the tile under the pointer.
    const above = box.top - GAP - height;
    const y = above >= EDGE ? above : Math.min(box.bottom + GAP, Math.max(EDGE, window.innerHeight - EDGE - height));

    card.style.left = `${Math.round(x)}px`;
    card.style.top = `${Math.round(y)}px`;
  };

  const show = (tile) => {
    const picture = tile.querySelector(PICTURE);
    if (!picture || !tile.isConnected) {
      return;
    }
    card ??= build();
    const label = tile.querySelector('label');
    const [img, caption] = card.children;

    img.src = picture.currentSrc || picture.src;
    caption.textContent = label ? label.textContent.trim() : '';
    card.style.background = surfaceOf(tile);
    card.style.color = getComputedStyle(label || tile).color;
    card.style.borderColor = 'color-mix(in srgb, currentColor 22%, transparent)';

    // Inside the dialog when there is one, so the card is above it.
    (tile.closest('dialog') || document.body).append(card);
    if (card.popover && !card.matches(':popover-open')) {
      card.showPopover();
    }
    card.hidden = false;
    current = tile;
    place(tile);
    if (!img.complete) {
      img.addEventListener('load', () => current === tile && place(tile), {once: true});
    }
  };

  const hide = () => {
    clearTimeout(timer);
    current = null;
    if (!card) {
      return;
    }
    if (card.popover && card.matches(':popover-open')) {
      card.hidePopover();
    }
    card.hidden = true;
  };

  const open = (tile) => {
    clearTimeout(timer);
    if (tile === current) {
      return;
    }
    timer = setTimeout(() => show(tile), DELAY);
  };

  document.addEventListener('pointerover', (event) => {
    if (event.pointerType !== 'mouse') {
      return;
    }
    const tile = tileOf(event.target);
    tile ? open(tile) : (current || timer) && hide();
  });
  document.addEventListener('pointerout', (event) => {
    if (event.pointerType === 'mouse' && tileOf(event.target) && !tileOf(event.relatedTarget)) {
      hide();
    }
  });
  document.addEventListener('focusin', (event) => {
    const tile = tileOf(event.target);
    // Only keyboard focus: a click focuses the radio too, and the pointer already has its card.
    if (tile && event.target instanceof Element && event.target.matches(':focus-visible')) {
      open(tile);
    }
  });
  document.addEventListener('focusout', (event) => tileOf(event.target) && hide());
  document.addEventListener('keydown', (event) => event.key === 'Escape' && hide());
  document.addEventListener('scroll', hide, {capture: true, passive: true});
  window.addEventListener('resize', hide);
})();
