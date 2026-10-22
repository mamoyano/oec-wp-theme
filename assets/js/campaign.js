/* OEC Theme — campaign.js
   Campañas de descuentos (inc/campaign.php). Los datos llegan en
   window.OEC_CMP (en el <head>) y el estado (se viene / en curso) lo
   decide el navegador con las fechas exactas, porque el HTML puede venir
   de la caché de Cloudflare armado en otro momento.

   - Cuentas regresivas [data-oec-cmp-cd] (landing, franja del home, barra).
   - Barra arriba de todo y aviso chico una vez por visita, salvo en la
     landing y en las fichas de formación.
   - Marca con el % en cada card que participa (también las que llegan
     después: buscador de /formaciones, carruseles).
   - En la ficha de una formación que participa (en curso): marca junto al
     título y el nombre de la campaña en su caja de descuento.
   - Landing: filtros por % y temática. */
(function () {
  'use strict';

  const C = window.OEC_CMP;
  const landing = document.querySelector('[data-oec-cmp-landing]');
  const phase = C ? C.phase : '';
  const live = phase === 'live';

  /* ── Cuentas regresivas ─────────────────────────────────────── */
  const pad = (n) => String(n).padStart(2, '0');
  function parts(ms) {
    const s = Math.max(0, Math.floor(ms / 1000));
    return { d: Math.floor(s / 86400), h: Math.floor((s % 86400) / 3600), m: Math.floor((s % 3600) / 60), s: s % 60 };
  }
  function shortLeft(ms) {
    const p = parts(ms);
    return (p.d ? p.d + 'd ' : '') + pad(p.h) + ':' + pad(p.m) + ':' + pad(p.s);
  }
  // Al llegar a cero en la landing, se recarga una vez (el estado cambió).
  // Con un parámetro: la copia sin él puede seguir en Cloudflare hasta que
  // se borre la caché, un minuto después del cambio (inc/campaign.php).
  function reachedZero() {
    if (!landing) return;
    const k = 'oec_cmp_reload_' + (C ? C.key : '') + '_' + landing.dataset.oecCmpLanding;
    try { if (sessionStorage.getItem(k)) return; sessionStorage.setItem(k, '1'); } catch (e) { return; }
    setTimeout(() => location.replace(location.pathname + '?cmp=' + Math.floor(Date.now() / 6e4)), 1500);
  }
  function tick() {
    const now = Date.now();
    document.querySelectorAll('[data-oec-cmp-cd]').forEach((el) => {
      const left = Number(el.dataset.oecCmpCd) - now;
      const p = parts(left);
      el.querySelectorAll('[data-u]').forEach((n) => { n.textContent = pad(p[n.dataset.u]); });
      el.classList.toggle('is-urgent', left > 0 && left < 864e5);
      if (left <= 0 && !el.dataset.done) { el.dataset.done = '1'; reachedZero(); }
    });
    document.querySelectorAll('[data-oec-cmp-left]').forEach((el) => {
      el.textContent = shortLeft(Number(el.dataset.oecCmpLeft) - now);
    });
  }
  tick();
  setInterval(tick, 1000);

  /* ── Landing: filtros por % y por temática ──────────────────── */
  const filters = document.querySelector('[data-oec-cmp-filters]');
  if (filters) {
    const items = [...document.querySelectorAll('.oec-cmpL-item')];
    const count = filters.querySelector('.oec-cmpL-filters__count');
    let pct = '';
    let tema = '';
    const apply = () => {
      let n = 0;
      items.forEach((it) => {
        const ok = (!pct || it.dataset.pct === pct) && (!tema || (' ' + it.dataset.temas + ' ').includes(' ' + tema + ' '));
        it.hidden = !ok;
        if (ok) n++;
      });
      if (count) count.textContent = pct || tema ? (n === 1 ? '1 formación' : n + ' formaciones') : '';
    };
    filters.addEventListener('click', (e) => {
      const b = e.target.closest('.oec-cmpL-chip');
      if (!b) return;
      const isPct = 'filterPct' in b.dataset;
      if (isPct) pct = b.dataset.filterPct; else tema = b.dataset.filterTema;
      b.parentElement.querySelectorAll('.oec-cmpL-chip').forEach((x) => x.setAttribute('aria-pressed', String(x === b)));
      apply();
    });
  }

  if (!C || (phase !== 'teaser' && phase !== 'live')) {
    // Franja del home armada en otro estado (caché): afuera.
    document.querySelectorAll('[data-oec-cmp-phase]').forEach((el) => { el.hidden = true; });
    return;
  }
  document.querySelectorAll('[data-oec-cmp-phase]').forEach((el) => { if (el.dataset.oecCmpPhase !== phase) el.hidden = true; });

  const ids = C.ids || {};
  const target = live ? C.end : C.start;
  const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const name = esc(C.name);
  const track = (what) => { if (window.dataLayer) window.dataLayer.push({ event: 'oec_campaign', campaign: C.key, action: what }); };

  /* ── Marca en las cards ─────────────────────────────────────── */
  function tagCard(card) {
    if (card.dataset.cmp || !(card.dataset.id in ids)) return;
    card.dataset.cmp = '1';
    const wrap = card.querySelector('.oec-image-wrapper');
    if (!wrap) return;
    const promo = wrap.querySelector('.oec-promo'); // "Pago anticipado" de Recién llegadas: es este mismo descuento
    if (promo) promo.remove();
    const pct = ids[card.dataset.id];
    const tag = document.createElement('span');
    tag.className = 'oec-cmp-tag' + (live ? '' : ' oec-cmp-tag--soon');
    tag.innerHTML = live && pct
      ? `<b class="oec-cmp-tag__pct">−${pct}%</b><span class="oec-cmp-tag__txt">${name}</span>`
      : `<b class="oec-cmp-tag__pct">−??%</b><span class="oec-cmp-tag__txt">${name}<small>${esc(C.days)}</small></span>`;
    wrap.appendChild(tag);
  }
  const tagAll = (root) => root.querySelectorAll('.oec-card[data-id]').forEach(tagCard);
  tagAll(document);
  new MutationObserver((muts) => muts.forEach((m) => m.addedNodes.forEach((n) => {
    if (n.nodeType !== 1) return;
    if (n.matches('.oec-card[data-id]')) tagCard(n); else tagAll(n);
  }))).observe(document.body, { childList: true, subtree: true });

  /* ── Ficha de una formación que participa (solo en curso) ───── */
  if (C.page === 'ficha') {
    const m = location.pathname.match(/(t-[A-Za-z0-9]{14})\/?$/);
    const id = m && m[1];
    const pct = id && ids[id];
    const h1 = document.getElementById('oec-training-title');
    if (live && pct && h1) {
      const mark = document.createElement('div');
      mark.className = 'oec-cmp-mark';
      mark.innerHTML = `<b>−${pct}%</b><span>${name}</span><span class="oec-cmp-mark__left"><i class="bi bi-stopwatch" aria-hidden="true"></i> <span data-oec-cmp-left="${C.end}"></span></span>`;
      h1.parentNode.insertBefore(mark, h1);
      const title = document.querySelector('#oec-disc-early-payment .oec-disc-title');
      if (title) title.innerHTML = `<i class="bi bi-lightning-charge-fill"></i> ${pct}% dto. ${name}`;
      tick();
    }
    return; // en la ficha, nada más: que nadie se vaya cuando está por comprar
  }
  if (C.page === 'landing') return;

  /* ── Barra arriba de todo ───────────────────────────────────── */
  const storeKey = (k) => 'oec_cmp_' + k + '_' + C.key + '_' + phase;
  const seen = (k) => { try { return !!sessionStorage.getItem(storeKey(k)); } catch (e) { return false; } };
  const remember = (k) => { try { sessionStorage.setItem(storeKey(k), '1'); } catch (e) { /* sin storage */ } };

  if (!seen('bar')) {
    const head = live
      ? (C.max ? `Hasta ${C.max}% OFF en ${C.n} formaciones` : `${C.n} formaciones con descuento`)
      : `Se viene · ${esc(C.days)}`;
    const cta = live ? 'Ver ofertas' : 'Ver cuáles participan';
    const item = `<span class="oec-cmp-bar__item"><b class="oec-cmp-bar__name">${name}</b><span>${head}</span>`
      + `<span class="oec-cmp-bar__left">${live ? 'Termina en' : 'Empieza en'} <span data-oec-cmp-left="${target}"></span></span></span>`;
    const bar = document.createElement('div');
    bar.className = 'oec-cmp-bar';
    bar.setAttribute('role', 'region');
    bar.setAttribute('aria-label', C.name);
    bar.innerHTML = `<a class="oec-cmp-bar__link" href="${esc(C.url)}">`
      + `<span class="oec-cmp-bar__track">${item}<i class="bi bi-lightning-charge-fill" aria-hidden="true"></i>${item.replace('<span class="oec-cmp-bar__item">', '<span class="oec-cmp-bar__item" aria-hidden="true">')}<i class="bi bi-lightning-charge-fill" aria-hidden="true"></i></span>`
      + `<span class="oec-cmp-bar__cta">${cta} <i class="bi bi-arrow-right" aria-hidden="true"></i></span></a>`
      + '<button type="button" class="oec-cmp-bar__close" aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>';
    document.body.insertBefore(bar, document.body.firstChild);
    bar.querySelector('.oec-cmp-bar__link').addEventListener('click', () => track('bar_click'));
    bar.querySelector('.oec-cmp-bar__close').addEventListener('click', () => { bar.remove(); remember('bar'); track('bar_close'); });
    tick();
  }

  /* ── Aviso chico, una vez por visita (en curso) ─────────────── */
  if (live && !seen('toast')) {
    let shown = false;
    const show = () => {
      // Mismo rincón que el aviso "antes de irte": si está abierto, no se pisa.
      if (shown || document.querySelector('.oec-antes-irte.is-open, #oec-ai-overlay.oec-overlay--open')) return;
      shown = true;
      window.removeEventListener('scroll', onScroll);
      remember('toast');
      const t = document.createElement('div');
      t.className = 'oec-cmp-toast';
      t.setAttribute('role', 'dialog');
      t.setAttribute('aria-label', C.name);
      t.innerHTML = '<button type="button" class="oec-cmp-toast__close" aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>'
        + `<p class="oec-cmp-toast__eyebrow"><i class="bi bi-lightning-charge-fill" aria-hidden="true"></i> Promo flash</p>`
        + `<p class="oec-cmp-toast__name">${name}</p>`
        + `<p class="oec-cmp-toast__big">${C.max ? `Hasta <strong>${C.max}% OFF</strong>` : 'Descuentos fuertes'}</p>`
        + `<p class="oec-cmp-toast__txt">En ${C.n} formaciones. Termina en <span data-oec-cmp-left="${C.end}"></span></p>`
        + `<a class="oec-cmp-btn oec-cmp-btn--sm" href="${esc(C.url)}">Ver ofertas <i class="bi bi-arrow-right" aria-hidden="true"></i></a>`;
      document.body.appendChild(t);
      tick();
      requestAnimationFrame(() => t.classList.add('is-in'));
      t.querySelector('a').addEventListener('click', () => track('toast_click'));
      t.querySelector('.oec-cmp-toast__close').addEventListener('click', () => { t.classList.remove('is-in'); setTimeout(() => t.remove(), 300); track('toast_close'); });
      track('toast_show');
    };
    const onScroll = () => {
      const h = document.documentElement.scrollHeight - innerHeight;
      if (h > 0 && scrollY / h > 0.35) show();
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    setTimeout(show, 12000);
  }
})();
