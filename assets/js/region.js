/**
 * País y moneda de los precios — una sola fuente para todo el sitio.
 *
 * Orden: lo que viene en la URL (?country=BO&currency=USD, así aplica su
 * elección el selector de la ficha) → lo que la persona eligió antes
 * (localStorage) → la detección por IP de api.g-se.com/v2/initialPreferences.
 * Lo elegido a mano se guarda y manda de ahí en más, hasta que lo cambie o
 * vuelva a "detectar automáticamente".
 *
 * Expone window.oecRegion { get(), set(), reset(), open(), current() } y el
 * evento "oec:region" (document). Lo usan el chat con IA y el selector del pie.
 * Se carga en el <head>, sin defer: en la ficha tiene que correr antes que el
 * script del plugin, que lee el país y la moneda de la URL al iniciar.
 */
(function () {
  'use strict';

  const KEY        = 'oec_region';
  const PREFS_URL  = 'https://api.g-se.com/v2/initialPreferences';
  // La API de precios solo cotiza estas monedas; con otra responde error.
  const CURRENCIES = [
    ['EUR', '(EUR) Euro'], ['USD', '(USD) Dólar Americano'], ['ARS', '(ARS) Peso Argentino'],
    ['MXN', '(MXN) Peso Mexicano'], ['COP', '(COP) Peso Colombiano'], ['CLP', '(CLP) Peso Chileno'],
  ];
  const FREQUENT = ['AR', 'BO', 'BR', 'CL', 'CO', 'CR', 'CU', 'EC', 'ES', 'US', 'MX', 'PA', 'PY', 'PE', 'UY', 'VE'];
  const ALL = 'AF AX AL DE AD AO AI AG AQ SA DZ AR AM AW AU AT AZ BS BH BD BB BE BZ BJ BM BY BO BQ BA BW BR BN BG BF BI BT CV KH CM CA QA TD CZ CL CN CY VA CO KM CG CD KP KR CI CR HR CU CW DK DM EC EG SV AE ER SK SI ES US EE SZ ET PH FI FJ FR GA GM GE GH GI GD GR GL GP GU GT GF GG GN GW GQ GY HT HN HK HU IN ID IQ IR IE BV IM CX NF IS KY CC CK FO GS HM FK MP MH UM PN SB TC VG VI IL IT JM JP JE JO KZ KE KG KI XK KW LA LS LV LB LR LY LI LT LU MO MK MG MY MW MV ML MT MA MQ MU MR YT MX FM MD MC MN ME MS MZ MM NA NR NP NI NE NG NU NO NC NZ OM NL PK PW PS PA PG PY PE PF PL PT PR GB CF DO RE RW RO RU EH WS AS BL KN SM MF PM VC SH LC ST SN RS SC SL SG SX SY SO LK ZA SD SS SE CH SR SJ TH TW TZ TJ IO TF TL TG TK TO TT TN TM TR TV UA UG UY UZ VU VE VN WF YE DJ ZM ZW'.split(' ');

  const names = (() => { try { return new Intl.DisplayNames(['es'], { type: 'region' }); } catch (_) { return null; } })();
  function countryName(code) { try { return (names && names.of(code)) || code; } catch (_) { return code; } }

  const okCountry  = c => /^[A-Z]{2}$/.test(c);
  const okCurrency = c => CURRENCIES.some(x => x[0] === c);
  function clean(country, currency) {
    country  = String(country || '').toUpperCase();
    currency = String(currency || '').toUpperCase();
    if (!okCountry(country)) return null;
    return { country, currency: okCurrency(currency) ? currency : 'USD' };
  }

  function stored() {
    try {
      const s = JSON.parse(localStorage.getItem(KEY) || 'null');
      return s ? clean(s.country, s.currency) : null;
    } catch (_) { return null; }
  }
  function store(r) { try { localStorage.setItem(KEY, JSON.stringify(r)); } catch (_) {} }

  const isFicha = /\/formacion\/[^/]*t-[A-Za-z0-9]{14}/.test(location.pathname);

  /* ── 1. URL: si trae país y moneda, es una elección de la persona ── */
  let manual = null;
  (function fromUrl() {
    const q = new URLSearchParams(location.search);
    if (q.get('country')) {
      const r = clean(q.get('country'), q.get('currency'));
      if (r) { manual = r; store(r); }
      return;
    }
    manual = stored();
    // En la ficha, el plugin toma país y moneda solo de la URL: se los pasamos sin
    // recargar, y cuando ya los leyó la URL vuelve a quedar limpia (para compartir).
    if (manual && isFicha && history.replaceState) {
      const clean_url = location.pathname + location.search + location.hash;
      const u = new URL(location.href);
      u.searchParams.set('country', manual.country);
      u.searchParams.set('currency', manual.currency);
      history.replaceState(history.state, '', u.pathname + u.search + u.hash);
      let tries = 0;
      const timer = setInterval(() => {
        const sel = document.getElementById('oec-countries_select');
        const read = sel ? sel.value === manual.country : (document.readyState === 'complete' && tries > 6);
        if (read || ++tries > 60) {
          clearInterval(timer);
          if (new URLSearchParams(location.search).get('country') === manual.country) {
            history.replaceState(history.state, '', clean_url);
          }
        }
      }, 300);
    }
  })();

  /* ── 2. Detección por IP (una vez por página) ── */
  let detecting = null;
  function detect() {
    if (!detecting) {
      detecting = fetch(PREFS_URL, { cache: 'no-store' })
        .then(r => (r.ok ? r.json() : null))
        .then(d => (d ? clean(d.country, d.currency) : null))
        .catch(() => null);
    }
    return detecting;
  }

  function get() {
    if (manual) return Promise.resolve(Object.assign({ manual: true }, manual));
    return detect().then(r => (r ? Object.assign({ manual: false }, r) : { country: '', currency: '', manual: false }));
  }

  function announce() {
    get().then(r => {
      paintTrigger(r);
      document.dispatchEvent(new CustomEvent('oec:region', { detail: r }));
    });
  }

  function set(country, currency) {
    const r = clean(country, currency);
    if (!r) return;
    manual = r;
    store(r);
    if (isFicha) { // la ficha recalcula sus precios al recargar con la elección en la URL
      const u = new URL(location.href);
      u.searchParams.set('country', r.country);
      u.searchParams.set('currency', r.currency);
      location.href = u.pathname + u.search;
      return;
    }
    announce();
  }

  function reset() {
    manual = null;
    try { localStorage.removeItem(KEY); } catch (_) {}
    if (isFicha) {
      const u = new URL(location.href);
      u.searchParams.delete('country');
      u.searchParams.delete('currency');
      location.href = u.pathname + u.search;
      return;
    }
    announce();
  }

  /* ── 3. Selector (botón en el pie + paneles; mismo diseño que el de la ficha) ── */
  let panel = null, countryPanel = null, backdrop = null, anchor = null;
  let pick = { country: '', currency: '' };

  function label(r) { return r.country ? 'Ver precios: ' + countryName(r.country) + ' · ' + r.currency : 'Ver precios por país'; }
  function paintTrigger(r) {
    document.querySelectorAll('[data-oec-geo-label]').forEach(el => { el.textContent = label(r); });
  }

  function li(value, text) {
    const el = document.createElement('li');
    el.setAttribute('role', 'option');
    el.dataset.value = value;
    const s = document.createElement('span');
    s.textContent = text;
    const i = document.createElement('i');
    i.className = 'bi bi-check-lg';
    el.append(s, i);
    return el;
  }

  function build() {
    backdrop = document.createElement('div');
    backdrop.className = 'oec-geo-backdrop';

    panel = document.createElement('div');
    panel.className = 'oec-geo-panel';
    panel.hidden = true;
    panel.innerHTML =
        '<div class="oec-geo-head"><span class="oec-geo-title">Ver precios para</span>'
      + '<button type="button" class="oec-geo-close" aria-label="Cerrar">×</button></div>'
      + '<div class="oec-geo-body">'
      +   '<p class="oec-geo-label">País</p>'
      +   '<button type="button" class="oec-geo-current"><span></span><i class="bi bi-chevron-right"></i></button>'
      +   '<p class="oec-geo-label oec-geo-label--gap">Moneda</p>'
      +   '<ul class="oec-geo-list" role="listbox" aria-label="Moneda" data-list="currency"></ul>'
      + '</div>'
      // Fuera de la parte que se desplaza: el botón queda siempre a la vista
      + '<div class="oec-geo-foot">'
      +   '<button type="button" class="oec-geo-apply">Aplicar</button>'
      +   '<button type="button" class="oec-geo-auto" hidden>Detectar automáticamente</button>'
      + '</div>';

    countryPanel = document.createElement('div');
    countryPanel.className = 'oec-geo-panel';
    countryPanel.hidden = true;
    countryPanel.innerHTML =
        '<div class="oec-geo-head"><button type="button" class="oec-geo-back"><i class="bi bi-chevron-left"></i> Volver</button>'
      + '<button type="button" class="oec-geo-close" aria-label="Cerrar">×</button></div>'
      + '<div class="oec-geo-body">'
      +   '<p class="oec-geo-sublabel">Frecuentes</p><ul class="oec-geo-list" role="listbox" aria-label="Países frecuentes" data-list="freq"></ul>'
      +   '<p class="oec-geo-sublabel">Todos los países</p><ul class="oec-geo-list" role="listbox" aria-label="Todos los países" data-list="all"></ul>'
      + '</div>';

    const cur  = panel.querySelector('[data-list="currency"]');
    CURRENCIES.forEach(([code, name]) => cur.appendChild(li(code, name)));
    const freq = countryPanel.querySelector('[data-list="freq"]');
    FREQUENT.map(c => [c, countryName(c)]).sort((a, b) => a[1].localeCompare(b[1], 'es'))
      .forEach(([c, n]) => freq.appendChild(li(c, n)));
    const all  = countryPanel.querySelector('[data-list="all"]');
    ALL.map(c => [c, countryName(c)]).sort((a, b) => a[1].localeCompare(b[1], 'es'))
      .forEach(([c, n]) => all.appendChild(li(c, n)));

    document.body.append(backdrop, panel, countryPanel);

    backdrop.addEventListener('click', close);
    [panel, countryPanel].forEach(p => p.querySelector('.oec-geo-close').addEventListener('click', close));
    panel.querySelector('.oec-geo-current').addEventListener('click', () => { panel.hidden = true; show(countryPanel); });
    countryPanel.querySelector('.oec-geo-back').addEventListener('click', () => { countryPanel.hidden = true; show(panel); });
    cur.addEventListener('click', e => {
      const item = e.target.closest('li');
      if (item) { pick.currency = item.dataset.value; paintPanel(); }
    });
    countryPanel.addEventListener('click', e => {
      const item = e.target.closest('li');
      if (!item) return;
      pick.country = item.dataset.value;
      paintPanel();
      countryPanel.hidden = true;
      show(panel);
    });
    panel.querySelector('.oec-geo-apply').addEventListener('click', () => { close(); set(pick.country, pick.currency); });
    panel.querySelector('.oec-geo-auto').addEventListener('click', () => { close(); reset(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
    window.addEventListener('scroll', reposition, { passive: true });
    window.addEventListener('resize', reposition);
  }

  function paintPanel() {
    panel.querySelector('.oec-geo-current span').textContent = pick.country ? countryName(pick.country) : 'Elegir país';
    panel.querySelectorAll('[data-list="currency"] li').forEach(el => el.setAttribute('aria-selected', String(el.dataset.value === pick.currency)));
    countryPanel.querySelectorAll('li').forEach(el => el.setAttribute('aria-selected', String(el.dataset.value === pick.country)));
    panel.querySelector('.oec-geo-apply').disabled = !pick.country;
    panel.querySelector('.oec-geo-auto').hidden = !manual;
  }

  function place(p) {
    const vw = window.innerWidth, vh = window.innerHeight;
    const r  = anchor && anchor.isConnected ? anchor.getBoundingClientRect() : { top: vh / 2, bottom: vh / 2, left: vw / 2 - 150 };
    const above = r.top - 16, below = vh - r.bottom - 16;
    const up    = above >= Math.min(320, below) || above >= below;
    p.style.maxHeight = Math.min(440, Math.max(180, up ? above : below)) + 'px';
    let top = up ? r.top - p.offsetHeight - 8 : r.bottom + 8;
    top = Math.max(10, Math.min(top, vh - p.offsetHeight - 10));
    p.style.top  = top + 'px';
    p.style.left = Math.max(10, Math.min(r.left, vw - p.offsetWidth - 10)) + 'px';
  }
  function show(p) { backdrop.classList.add('is-visible'); p.hidden = false; place(p); }
  function reposition() {
    if (panel && !panel.hidden) place(panel);
    if (countryPanel && !countryPanel.hidden) place(countryPanel);
  }
  function close() {
    if (!panel) return;
    panel.hidden = true;
    countryPanel.hidden = true;
    backdrop.classList.remove('is-visible');
    document.querySelectorAll('.oec-geo-trigger').forEach(t => t.setAttribute('aria-expanded', 'false'));
  }

  /** Abre el selector junto a `el` (el botón del pie, o un enlace del chat). */
  function open(el) {
    if (!panel) build();
    const wasOpen = !panel.hidden || !countryPanel.hidden;
    close();
    if (wasOpen && el === anchor) return;
    anchor = el || document.querySelector('.oec-geo-trigger');
    if (anchor && anchor.classList.contains('oec-geo-trigger')) anchor.setAttribute('aria-expanded', 'true');
    get().then(r => {
      pick = { country: r.country, currency: r.currency || 'USD' };
      paintPanel();
      show(panel);
    });
  }

  window.oecRegion = { get, set, reset, open, current: () => manual, countryName, CURRENCIES };

  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.oec-geo-trigger').forEach(t => t.addEventListener('click', e => { e.stopPropagation(); open(t); }));
    get().then(paintTrigger);
  });
})();
