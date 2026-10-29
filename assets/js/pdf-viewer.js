/* OEC Theme — pdf-viewer.js
   Visor de [oec-pdf] (inc/pdf-viewer.php). La página trae solo la tarjeta;
   al tocar "Ver PDF" se importa PDF.js (assets/vendor/pdfjs) y el PDF se
   pide por rangos (solo las partes que se ven). Las páginas se dibujan
   cuando entran en pantalla y se sueltan las que quedan lejos, así un PDF
   largo no llena la memoria del celular. Sin PDF.js (PDF de otro sitio,
   navegador viejo, error) el botón abre el PDF en una pestaña nueva. */
(function () {
  'use strict';

  const ZOOMS = [0.5, 0.75, 1, 1.25, 1.5, 2, 3];
  const libs = {};

  function loadLib(fig) {
    const src = fig.dataset.lib;
    if (!libs[src]) {
      libs[src] = import(src).then((lib) => {
        lib.GlobalWorkerOptions.workerSrc = fig.dataset.worker;
        return lib;
      });
      libs[src].catch(() => { delete libs[src]; });
    }
    return libs[src];
  }

  // Con la intención de abrirlo (mouse encima, foco) ya se va pidiendo la librería.
  function warm(fig) {
    if (fig.dataset.warm || !fig.dataset.lib) return;
    fig.dataset.warm = '1';
    for (const href of [fig.dataset.lib, fig.dataset.worker]) {
      const l = document.createElement('link');
      l.rel = 'modulepreload';
      l.href = href;
      document.head.appendChild(l);
    }
  }

  function el(tag, cls, html) {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (html) n.innerHTML = html;
    return n;
  }

  function btn(icon, label, cls) {
    const b = el('button', 'oec-pdf__tool' + (cls ? ' ' + cls : ''), '<i class="bi ' + icon + '" aria-hidden="true"></i>');
    b.type = 'button';
    b.title = label;
    b.setAttribute('aria-label', label);
    return b;
  }

  class Viewer {
    constructor(fig) {
      this.fig = fig;
      this.zoom = 1;
      this.pages = [];
      this.build();
    }

    build() {
      const fig = this.fig;
      this.box = el('div', 'oec-pdf__viewer');
      this.box.setAttribute('role', 'region');
      this.box.setAttribute('aria-label', (fig.querySelector('.oec-pdf__title') || {}).textContent || 'PDF');

      const bar = el('div', 'oec-pdf__bar');
      this.prev = btn('bi-chevron-up', 'Página anterior');
      this.next = btn('bi-chevron-down', 'Página siguiente');
      this.num = el('input', 'oec-pdf__num');
      this.num.type = 'number';
      this.num.min = '1';
      this.num.value = '1';
      this.num.inputMode = 'numeric';
      this.num.setAttribute('aria-label', 'Página');
      this.count = el('span', 'oec-pdf__count', '/ ' + (fig.dataset.pages || '…'));
      this.out = btn('bi-zoom-out', 'Alejar');
      this.in = btn('bi-zoom-in', 'Acercar');
      this.full = btn('bi-arrows-fullscreen', 'Pantalla completa', 'oec-pdf__full');
      const dl = el('a', 'oec-pdf__tool', '<i class="bi bi-download" aria-hidden="true"></i>');
      dl.href = fig.dataset.src;
      dl.setAttribute('download', '');
      dl.title = 'Descargar';
      dl.setAttribute('aria-label', 'Descargar');
      this.close = btn('bi-x-lg', 'Cerrar el visor');

      const nav = el('div', 'oec-pdf__group');
      nav.append(this.prev, this.next, this.num, this.count);
      const zoom = el('div', 'oec-pdf__group');
      zoom.append(this.out, this.in);
      const end = el('div', 'oec-pdf__group');
      end.append(this.full, dl, this.close);
      bar.append(nav, zoom, end);

      this.scroller = el('div', 'oec-pdf__scroll');
      this.stack = el('div', 'oec-pdf__pages');
      this.status = el('p', 'oec-pdf__status', 'Cargando el PDF…');
      this.status.setAttribute('role', 'status');
      this.scroller.append(this.status, this.stack);
      this.box.append(bar, this.scroller);
      fig.append(this.box);
      fig.classList.add('is-open');

      this.prev.addEventListener('click', () => this.go(this.current - 1));
      this.next.addEventListener('click', () => this.go(this.current + 1));
      this.num.addEventListener('change', () => this.go(parseInt(this.num.value, 10) - 1));
      this.out.addEventListener('click', () => this.setZoom(-1));
      this.in.addEventListener('click', () => this.setZoom(1));
      this.full.addEventListener('click', () => this.toggleFull());
      this.close.addEventListener('click', () => this.destroy());
      this.box.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && this.isFull()) this.toggleFull();
      });
      this.onFs = () => {
        this.box.classList.toggle('is-full', this.isFull());
        this.layout();
      };
      document.addEventListener('fullscreenchange', this.onFs);
      document.addEventListener('webkitfullscreenchange', this.onFs);
      this.scroller.addEventListener('scroll', () => this.track(), { passive: true });

      this.box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      this.open();
    }

    async open() {
      const fig = this.fig;
      try {
        const lib = await loadLib(fig);
        this.task = lib.getDocument({
          url: fig.dataset.src,
          standardFontDataUrl: fig.dataset.fonts,
          // Solo los rangos que hacen falta (si el servidor no acepta rangos, baja entero).
          disableAutoFetch: true,
          disableStream: true,
          isEvalSupported: false,
        });
        this.doc = await this.task.promise;
        const first = await this.doc.getPage(1);
        this.base = first.getViewport({ scale: 1 });
        this.total = this.doc.numPages;
      } catch (err) {
        if (this.dead) return;
        this.status.innerHTML = 'No se pudo mostrar el PDF acá. ';
        const a = el('a', '', 'Abrilo en una pestaña nueva');
        a.href = fig.dataset.src;
        a.target = '_blank';
        a.rel = 'noopener';
        this.status.append(a, '.');
        return;
      }
      if (this.dead) return;
      this.status.remove();
      this.count.textContent = '/ ' + this.total;
      this.num.max = String(this.total);

      for (let i = 0; i < this.total; i++) {
        const p = el('div', 'oec-pdf__page');
        p.dataset.i = String(i);
        p.setAttribute('aria-label', 'Página ' + (i + 1));
        this.stack.append(p);
        this.pages.push({ node: p, ratio: this.base.height / this.base.width, scale: 0, task: null });
      }

      // Se dibujan las páginas a menos de una pantalla de distancia; las que
      // se alejan más de tres pantallas sueltan el canvas.
      this.near = new IntersectionObserver((entries) => {
        for (const e of entries) {
          const pg = this.pages[e.target.dataset.i];
          pg.visible = e.isIntersecting;
          if (e.isIntersecting) this.draw(pg);
        }
      }, { root: this.scroller, rootMargin: '100% 0px' });
      this.far = new IntersectionObserver((entries) => {
        for (const e of entries) {
          if (!e.isIntersecting) this.drop(this.pages[e.target.dataset.i]);
        }
      }, { root: this.scroller, rootMargin: '300% 0px' });
      this.resize = new ResizeObserver(() => {
        const w = this.scroller.clientWidth;
        if (w && w !== this.width) this.layout();
      });

      this.layout();
      for (const pg of this.pages) {
        this.near.observe(pg.node);
        this.far.observe(pg.node);
      }
      this.resize.observe(this.scroller);
      this.track();
    }

    // Ancho de página = ancho del visor × zoom (al 100 % entra justo).
    layout() {
      if (!this.base) return;
      this.width = this.scroller.clientWidth;
      const fit = Math.max(200, this.width - 24) / this.base.width;
      this.scale = fit * this.zoom;
      const anchor = this.current || 0;
      for (const pg of this.pages) {
        pg.node.style.width = Math.floor(this.base.width * this.scale) + 'px';
        pg.node.style.height = Math.floor(this.base.width * this.scale * pg.ratio) + 'px';
        if (pg.visible) this.draw(pg);
        else this.drop(pg);
      }
      this.out.disabled = this.zoom <= ZOOMS[0];
      this.in.disabled = this.zoom >= ZOOMS[ZOOMS.length - 1];
      this.go(anchor, true);
    }

    async draw(pg) {
      if (!this.doc || (pg.scale === this.scale && pg.canvas)) return;
      const scale = this.scale;
      const i = +pg.node.dataset.i;
      if (pg.task) pg.task.cancel();
      try {
        const page = await this.doc.getPage(i + 1);
        if (this.dead || scale !== this.scale) return;
        const vp = page.getViewport({ scale });
        const dpr = Math.min(window.devicePixelRatio || 1, 2);
        const canvas = document.createElement('canvas');
        canvas.width = Math.floor(vp.width * dpr);
        canvas.height = Math.floor(vp.height * dpr);
        pg.task = page.render({
          canvasContext: canvas.getContext('2d'),
          viewport: vp,
          transform: dpr !== 1 ? [dpr, 0, 0, dpr, 0, 0] : null,
        });
        await pg.task.promise;
        pg.task = null;
        if (this.dead || scale !== this.scale) return;
        // La página real puede tener otra proporción que la primera.
        pg.ratio = vp.height / vp.width;
        pg.node.style.height = Math.floor(vp.height) + 'px';
        pg.node.replaceChildren(canvas);
        pg.canvas = canvas;
        pg.scale = scale;
      } catch (e) {
        pg.task = null; // render cancelado por otro zoom: no pasa nada
      }
    }

    drop(pg) {
      if (pg.task) { pg.task.cancel(); pg.task = null; }
      if (pg.canvas) {
        pg.canvas.width = pg.canvas.height = 0;
        pg.node.replaceChildren();
        pg.canvas = null;
        pg.scale = 0;
      }
    }

    // Página actual: la que ocupa el primer tercio del visor.
    track() {
      if (this.raf || !this.pages.length) return;
      this.raf = requestAnimationFrame(() => {
        this.raf = 0;
        const line = this.scroller.scrollTop + this.scroller.clientHeight / 3;
        let cur = 0;
        for (let i = 0; i < this.pages.length; i++) {
          if (this.pages[i].node.offsetTop <= line) cur = i; // relativo al visor (.oec-pdf__scroll es position: relative)
          else break;
        }
        this.current = cur;
        if (document.activeElement !== this.num) this.num.value = String(cur + 1);
        this.prev.disabled = cur <= 0;
        this.next.disabled = cur >= this.pages.length - 1;
      });
    }

    go(i, instant) {
      if (!this.pages.length || isNaN(i)) return;
      i = Math.max(0, Math.min(this.pages.length - 1, i));
      this.scroller.scrollTo({ top: this.pages[i].node.offsetTop - 12, behavior: instant ? 'auto' : 'smooth' });
      this.current = i;
      this.num.value = String(i + 1);
      this.track();
    }

    setZoom(dir) {
      const at = ZOOMS.indexOf(this.zoom);
      const next = ZOOMS[Math.max(0, Math.min(ZOOMS.length - 1, at + dir))];
      if (next === this.zoom) return;
      this.zoom = next;
      this.layout();
    }

    isFull() {
      const fs = document.fullscreenElement || document.webkitFullscreenElement;
      return fs === this.box || this.box.classList.contains('is-fake-full');
    }

    // Pantalla completa real; en iPhone (no la permite para un div) o si el
    // navegador la rechaza, el visor tapa la página.
    toggleFull() {
      const box = this.box;
      const fs = document.fullscreenElement || document.webkitFullscreenElement;
      const req = box.requestFullscreen || box.webkitRequestFullscreen;
      if (fs === box) {
        (document.exitFullscreen || document.webkitExitFullscreen).call(document);
      } else if (box.classList.contains('is-fake-full')) {
        this.fakeFull(false);
      } else if (req) {
        const p = req.call(box);
        if (p && p.catch) p.catch(() => this.fakeFull(true));
      } else {
        this.fakeFull(true);
      }
      this.full.focus();
    }

    fakeFull(on) {
      this.box.classList.toggle('is-fake-full', on);
      this.box.classList.toggle('is-full', on);
      document.documentElement.classList.toggle('oec-pdf-lock', on);
      this.layout();
    }

    destroy() {
      if (this.isFull()) this.toggleFull();
      this.dead = true;
      this.near && this.near.disconnect();
      this.far && this.far.disconnect();
      this.resize && this.resize.disconnect();
      document.removeEventListener('fullscreenchange', this.onFs);
      document.removeEventListener('webkitfullscreenchange', this.onFs);
      this.pages.forEach((pg) => this.drop(pg));
      if (this.task) this.task.destroy();
      this.box.remove();
      this.fig.classList.remove('is-open');
      this.fig.viewer = null;
      const open = this.fig.querySelector('.oec-pdf__open');
      open.setAttribute('aria-expanded', 'false');
      open.focus();
    }
  }

  function track(fig, action) {
    if (window.dataLayer) {
      window.dataLayer.push({ event: 'pdf_' + action, file_url: fig.dataset.src, file_name: (fig.querySelector('.oec-pdf__title') || {}).textContent || '' });
    }
  }

  // Sin import() dinámico (navegador muy viejo) queda el link: abre el PDF en otra pestaña.
  let modules = false;
  try { modules = 'noModule' in HTMLScriptElement.prototype; } catch (e) { /* viejo */ }

  document.querySelectorAll('.oec-pdf').forEach((fig) => {
    const open = fig.querySelector('.oec-pdf__open');
    const dl = fig.querySelector('.oec-pdf__download');
    if (dl) dl.addEventListener('click', () => track(fig, 'download'));
    if (!open) return;
    if (!fig.dataset.lib || !modules || !('IntersectionObserver' in window) || !('ResizeObserver' in window)) {
      open.addEventListener('click', () => track(fig, 'open'));
      return;
    }
    open.setAttribute('aria-expanded', 'false');
    ['pointerenter', 'focus', 'touchstart'].forEach((ev) => open.addEventListener(ev, () => warm(fig), { passive: true, once: true }));
    open.addEventListener('click', (e) => {
      e.preventDefault();
      if (fig.viewer) {
        fig.viewer.box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        return;
      }
      fig.viewer = new Viewer(fig);
      open.setAttribute('aria-expanded', 'true');
      track(fig, 'view');
    });
  });
})();
