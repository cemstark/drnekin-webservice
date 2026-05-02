/* DRN.EKİN OTO — Yeniden Kullanılabilir Floating Widget Tabanı
 * Özellikler: drag (header), resize (sağ-alt köşe), close (×), ESC,
 *             pozisyon/boyut localStorage, viewport clamp, mouse + touch.
 *
 * Kullanım:
 *   const w = WidgetBase.create({
 *     id: 'deger-kaybi',
 *     title: 'Değer Kaybı Hesaplama',
 *     bodyHtml: '<p>...</p>',
 *     width: 480, height: 600,
 *     onClose: () => {...}
 *   });
 *   w.open();
 */
(function (root) {
  'use strict';

  const STORAGE_PREFIX = 'drnWidget_';
  const MIN_W = 320, MIN_H = 320;
  const HEADER_H = 48;

  function clamp(v, min, max) { return Math.max(min, Math.min(max, v)); }

  function loadState(id) {
    try {
      const raw = localStorage.getItem(STORAGE_PREFIX + id);
      return raw ? JSON.parse(raw) : null;
    } catch { return null; }
  }

  function saveState(id, state) {
    try { localStorage.setItem(STORAGE_PREFIX + id, JSON.stringify(state)); } catch {}
  }

  function create(opts) {
    const {
      id,
      title = 'Widget',
      bodyHtml = '',
      width = 480,
      height = 600,
      onClose = null,
      onOpen = null
    } = opts;

    if (!id) throw new Error('Widget id zorunlu');

    let el = null;
    let state = loadState(id) || {
      x: Math.max(20, (window.innerWidth - width) / 2),
      y: Math.max(20, (window.innerHeight - height) / 3),
      w: width,
      h: height
    };

    function buildDom() {
      el = document.createElement('div');
      el.className = 'drn-widget';
      el.setAttribute('role', 'dialog');
      el.setAttribute('aria-labelledby', `${id}-title`);
      el.setAttribute('aria-modal', 'false');
      el.id = `widget-${id}`;
      el.innerHTML = `
        <div class="drn-widget-header" data-drag-handle>
          <span class="drn-widget-title" id="${id}-title">${title}</span>
          <button class="drn-widget-close" type="button" aria-label="Kapat">×</button>
        </div>
        <div class="drn-widget-body">${bodyHtml}</div>
        <div class="drn-widget-resize" aria-hidden="true"></div>
      `;
      applyState();
      document.body.appendChild(el);
      bindEvents();
    }

    function applyState() {
      el.style.left = `${clamp(state.x, 0, window.innerWidth - 100)}px`;
      el.style.top  = `${clamp(state.y, 0, window.innerHeight - HEADER_H)}px`;
      el.style.width  = `${Math.max(MIN_W, state.w)}px`;
      el.style.height = `${Math.max(MIN_H, state.h)}px`;
    }

    function persist() { saveState(id, state); }

    function bindEvents() {
      const handle = el.querySelector('[data-drag-handle]');
      const closeBtn = el.querySelector('.drn-widget-close');
      const resize = el.querySelector('.drn-widget-resize');

      // Drag
      let dragStart = null;
      const onDown = (e) => {
        if (e.target === closeBtn) return;
        const pt = e.touches ? e.touches[0] : e;
        dragStart = { x: pt.clientX - state.x, y: pt.clientY - state.y };
        el.classList.add('dragging');
        e.preventDefault();
      };
      const onMove = (e) => {
        if (!dragStart) return;
        const pt = e.touches ? e.touches[0] : e;
        state.x = clamp(pt.clientX - dragStart.x, 0, window.innerWidth - 100);
        state.y = clamp(pt.clientY - dragStart.y, 0, window.innerHeight - HEADER_H);
        el.style.left = `${state.x}px`;
        el.style.top  = `${state.y}px`;
      };
      const onUp = () => {
        if (dragStart) { dragStart = null; el.classList.remove('dragging'); persist(); }
      };
      handle.addEventListener('mousedown', onDown);
      handle.addEventListener('touchstart', onDown, { passive: false });
      document.addEventListener('mousemove', onMove);
      document.addEventListener('touchmove', onMove, { passive: false });
      document.addEventListener('mouseup', onUp);
      document.addEventListener('touchend', onUp);

      // Resize
      let resizeStart = null;
      const onResizeDown = (e) => {
        const pt = e.touches ? e.touches[0] : e;
        resizeStart = { x: pt.clientX, y: pt.clientY, w: state.w, h: state.h };
        el.classList.add('resizing');
        e.preventDefault();
        e.stopPropagation();
      };
      const onResizeMove = (e) => {
        if (!resizeStart) return;
        const pt = e.touches ? e.touches[0] : e;
        state.w = Math.max(MIN_W, resizeStart.w + (pt.clientX - resizeStart.x));
        state.h = Math.max(MIN_H, resizeStart.h + (pt.clientY - resizeStart.y));
        el.style.width  = `${state.w}px`;
        el.style.height = `${state.h}px`;
      };
      const onResizeUp = () => {
        if (resizeStart) { resizeStart = null; el.classList.remove('resizing'); persist(); }
      };
      resize.addEventListener('mousedown', onResizeDown);
      resize.addEventListener('touchstart', onResizeDown, { passive: false });
      document.addEventListener('mousemove', onResizeMove);
      document.addEventListener('touchmove', onResizeMove, { passive: false });
      document.addEventListener('mouseup', onResizeUp);
      document.addEventListener('touchend', onResizeUp);

      // Close
      closeBtn.addEventListener('click', close);

      // ESC
      const onKey = (e) => { if (e.key === 'Escape' && el && document.body.contains(el)) close(); };
      document.addEventListener('keydown', onKey);
      el._onKey = onKey;

      // Window resize → clamp
      const onWinResize = () => applyState();
      window.addEventListener('resize', onWinResize);
      el._onWinResize = onWinResize;
    }

    function open() {
      if (el && document.body.contains(el)) {
        el.focus();
        return el;
      }
      buildDom();
      requestAnimationFrame(() => el.classList.add('open'));
      if (typeof onOpen === 'function') onOpen(el);
      return el;
    }

    function close() {
      if (!el) return;
      el.classList.remove('open');
      document.removeEventListener('keydown', el._onKey);
      window.removeEventListener('resize', el._onWinResize);
      setTimeout(() => {
        if (el && el.parentNode) el.parentNode.removeChild(el);
        el = null;
        if (typeof onClose === 'function') onClose();
      }, 200);
    }

    function getBody() {
      return el ? el.querySelector('.drn-widget-body') : null;
    }

    function setTitle(t) {
      if (el) el.querySelector('.drn-widget-title').textContent = t;
    }

    return { open, close, getBody, setTitle, get element() { return el; } };
  }

  root.WidgetBase = { create };
})(window);
