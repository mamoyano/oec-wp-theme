/* OEC Theme — main.js */
(function () {
  'use strict';

  /* ── Sticky header shadow ───────────────────────────────── */
  const header = document.querySelector('.site-header');
  if (header) {
    window.addEventListener('scroll', () => {
      header.classList.toggle('is-scrolled', window.scrollY > 10);
    }, { passive: true });
  }

  /* ── Mobile: hamburger ──────────────────────────────────── */
  const menuToggle = document.getElementById('menu-toggle');
  const mainNav    = document.getElementById('main-nav');
  const searchToggle = document.getElementById('search-toggle');
  const searchMobile = document.getElementById('header-search-mobile');

  function closeNav() {
    if (!mainNav) return;
    mainNav.classList.remove('is-open');
    if (menuToggle) menuToggle.setAttribute('aria-expanded', 'false');
  }

  function closeSearch() {
    if (!searchMobile) return;
    searchMobile.hidden = true;
    if (searchToggle) searchToggle.setAttribute('aria-expanded', 'false');
  }

  if (menuToggle && mainNav) {
    menuToggle.addEventListener('click', () => {
      const open = mainNav.classList.toggle('is-open');
      menuToggle.setAttribute('aria-expanded', String(open));
      if (open) closeSearch();
    });

    document.addEventListener('click', (e) => {
      if (!mainNav.contains(e.target) && !menuToggle.contains(e.target)) {
        closeNav();
      }
    });

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && mainNav.classList.contains('is-open')) {
        closeNav();
        menuToggle.focus();
      }
    });
  }

  /* ── Mobile: toggle buscador ────────────────────────────── */
  if (searchToggle && searchMobile) {
    searchToggle.addEventListener('click', () => {
      const isOpen = !searchMobile.hasAttribute('hidden');
      if (isOpen) {
        closeSearch();
      } else {
        closeNav();
        searchMobile.hidden = false;
        searchToggle.setAttribute('aria-expanded', 'true');
        searchMobile.querySelector('input')?.focus();
      }
    });
  }

  /* ── Mega menú (desktop + mobile) ──────────────────────── */
  document.querySelectorAll('.nav-item--has-sub').forEach((item) => {
    const trigger = item.querySelector('.nav-trigger');
    if (!trigger) return;
    let closeTimer = null;

    // Desktop: hover con delay de cierre para movimiento diagonal
    item.addEventListener('mouseenter', () => {
      clearTimeout(closeTimer);
      openMega(item, trigger);
    });
    item.addEventListener('mouseleave', () => {
      closeTimer = setTimeout(() => closeMega(item, trigger), 150);
    });

    // Click en el trigger:
    // - Desktop: el hover maneja el dropdown; el click navega normalmente.
    // - Mobile: el submenú siempre está visible; el click también navega directamente.
    // No se necesita lógica especial de toggle.

    // Cerrar con Escape
    item.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') { closeMega(item, trigger); trigger.focus(); }
    });
  });

  // Cerrar mega menús al hacer click fuera
  document.addEventListener('click', (e) => {
    if (!e.target.closest('.nav-item--has-sub')) {
      document.querySelectorAll('.nav-item--has-sub.is-open').forEach((item) => {
        closeMega(item, item.querySelector('.nav-trigger'));
      });
    }
  });

  function openMega(item, trigger) {
    item.classList.add('is-open');
    trigger.setAttribute('aria-expanded', 'true');
  }
  function closeMega(item, trigger) {
    item.classList.remove('is-open');
    if (trigger) trigger.setAttribute('aria-expanded', 'false');
  }

  /* ── Filtros sidebar toggle (mobile) ───────────────────── */
  const filterToggle = document.getElementById('art-filter-toggle');
  const filterBody   = document.getElementById('art-sidebar-body');
  if (filterToggle && filterBody) {
    filterToggle.addEventListener('click', () => {
      const open = filterBody.classList.toggle('is-open');
      filterToggle.classList.toggle('is-open', open);
      filterToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  /* ── Stats [oec-tematica-stats]: cuentan de 0 al valor al entrar en pantalla ── */
  const statValues = document.querySelectorAll('.oec-stats-bar__value[data-count]');
  if (statValues.length && 'IntersectionObserver' in window
      && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    const fmt = new Intl.NumberFormat(document.documentElement.lang || 'es');
    const countUp = (el) => {
      const target = parseInt(el.dataset.count, 10) || 0;
      const final  = el.textContent; // formato del servidor (p. ej. "7.261")
      const start  = performance.now();
      const step = (now) => {
        const p = Math.min((now - start) / 1400, 1);
        el.textContent = fmt.format(Math.floor((1 - Math.pow(1 - p, 3)) * target));
        if (p < 1) requestAnimationFrame(step);
        else el.textContent = final;
      };
      requestAnimationFrame(step);
    };
    const statsObserver = new IntersectionObserver((entries) => entries.forEach((e) => {
      if (!e.isIntersecting) return;
      e.target.querySelectorAll('.oec-stats-bar__value[data-count]').forEach(countUp);
      statsObserver.unobserve(e.target);
    }), { threshold: 0.4 });
    document.querySelectorAll('.oec-stats-bar').forEach((bar) => statsObserver.observe(bar));
  }

  /* ── Subrayado animado .oec-highlight: se dibuja al entrar en pantalla ── */
  const highlights = document.querySelectorAll('.oec-highlight');
  if (highlights.length) {
    if ('IntersectionObserver' in window) {
      const hlObserver = new IntersectionObserver((entries) => entries.forEach((e) => {
        if (!e.isIntersecting) return;
        setTimeout(() => e.target.classList.add('is-drawn'), 500);
        hlObserver.unobserve(e.target);
      }), { threshold: 0.6 });
      highlights.forEach((el) => hlObserver.observe(el));
    } else {
      highlights.forEach((el) => el.classList.add('is-drawn'));
    }
  }

  /* ── [oec-cierres]: cuenta regresiva al próximo cierre de inscripción ── */
  document.querySelectorAll('[data-oec-next-close]').forEach((el) => {
    const end   = Date.parse(el.dataset.oecNextClose);
    const clock = el.querySelector('.oec-cierres__clock');
    if (!end || !clock) return;
    const pad = (n) => String(n).padStart(2, '0');
    const render = () => {
      const left = Math.floor((end - Date.now()) / 1000);
      if (left <= 0) { el.hidden = true; return false; }
      const d = Math.floor(left / 86400), h = Math.floor(left / 3600) % 24;
      const m = Math.floor(left / 60) % 60, sec = left % 60;
      clock.textContent = d > 0
        ? `${d} d ${pad(h)} h ${pad(m)} min`
        : `${pad(h)} h ${pad(m)} min ${pad(sec)} s`;
      el.hidden = false;
      return true;
    };
    if (render()) {
      const timer = setInterval(() => { if (!render()) clearInterval(timer); }, 1000);
    }
  });

  /* Mezcla un array en el lugar (Fisher–Yates). Docentes y organizaciones
     ordenan al azar en cada visita lo que el servidor eligió: el HTML queda
     cacheado, así que el orden no puede salir del PHP. */
  const mezclar = (arr) => {
    for (let i = arr.length - 1; i > 0; i--) {
      const j = Math.floor(Math.random() * (i + 1));
      [arr[i], arr[j]] = [arr[j], arr[i]];
    }
    return arr;
  };

  /* ── Docentes [oec-docentes]: orden al azar, flechas + entrada escalonada ── */
  document.querySelectorAll('.oec-docentes').forEach((section) => {
    const track  = section.querySelector('.oec-docentes__track');
    const arrows = section.querySelectorAll('.oec-docentes__arrow');
    if (!track) return;

    // La card "+N docentes más" queda siempre al final.
    const mas = track.querySelector('.oec-docente--more');
    mezclar([...track.querySelectorAll('.oec-docente:not(.oec-docente--more)')]).forEach((card, i) => {
      card.style.setProperty('--i', i); // orden de la entrada escalonada
      track.insertBefore(card, mas);
    });

    const update = () => {
      const max = track.scrollWidth - track.clientWidth - 2;
      arrows.forEach((b) => { b.disabled = b.dataset.dir === '-1' ? track.scrollLeft <= 2 : track.scrollLeft >= max; });
    };
    arrows.forEach((b) => b.addEventListener('click', () => {
      track.scrollBy({ left: Number(b.dataset.dir) * track.clientWidth * 0.8 });
    }));
    track.addEventListener('scroll', update, { passive: true });
    window.addEventListener('resize', update);
    update();

    if ('IntersectionObserver' in window && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      section.classList.add('js-reveal');
      const io = new IntersectionObserver(([e]) => {
        if (!e.isIntersecting) return;
        section.classList.add('is-in');
        io.disconnect();
      }, { threshold: 0.25 });
      io.observe(section);
    }
  });

  /* ── Directorios (page-docentes.php, page-organizaciones.php): búsqueda,
     temática, orden y "Mostrar más". Todas las cards vienen en el HTML
     (para buscadores); acá solo se filtran/ordenan/paginan. El estado queda
     en la URL (?tematica=&q=). Marcado: [data-oec-dir] en el contenedor de
     filtros, con data-grid (id de la grilla), data-per-page y data-noun
     ("docente|docentes"); cada card con data-search/tags/sort y los
     valores numéricos por los que se ordena. */
  document.querySelectorAll('[data-oec-dir]').forEach((box) => {
    const grid = document.getElementById(box.dataset.grid);
    if (!grid) return;

    const cards   = [...grid.children].filter((c) => 'search' in c.dataset);
    const perPage = parseInt(box.dataset.perPage, 10) || 0; // 0 = sin paginar
    const [one, many] = (box.dataset.noun || 'resultado|resultados').split('|');
    const q       = box.querySelector('[data-dir-q]');
    const orden   = box.querySelector('[data-dir-sort]');
    const chips   = [...box.querySelectorAll('[data-dir-chip]')];
    const count   = box.querySelector('[data-dir-count]');
    const more    = document.querySelector(`[data-dir-more="${box.dataset.grid}"]`);
    const empty   = document.querySelector(`[data-dir-empty="${box.dataset.grid}"]`);
    const fmt     = new Intl.NumberFormat(document.documentElement.lang || 'es');
    const norm    = (t) => t.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    let shown     = perPage || Infinity;

    const tematica = () => (chips.find((c) => c.getAttribute('aria-pressed') === 'true') || {}).dataset?.tematica || '';
    const num = (c, k) => Number(c.dataset[k] || 0);
    const sorters = {
      alumnos:     (a, b) => num(b, 'alumnos') - num(a, 'alumnos'),
      abiertas:    (a, b) => (num(b, 'open') - num(a, 'open')) || (num(b, 'alumnos') - num(a, 'alumnos')),
      formaciones: (a, b) => num(b, 'formaciones') - num(a, 'formaciones'),
      nombre:      (a, b) => a.dataset.sort.localeCompare(b.dataset.sort),
    };

    const render = () => {
      const term = norm(q ? q.value.trim() : '');
      const tem  = tematica();
      const list = cards
        .filter((c) => (!term || c.dataset.search.includes(term)) && (!tem || (c.dataset.tags || '').split(' ').includes(tem)))
        .sort(sorters[orden ? orden.value : ''] || (() => 0));

      const visible = new Set(list.slice(0, shown));
      list.forEach((c) => grid.appendChild(c)); // reordena en el DOM
      cards.forEach((c) => { c.hidden = !visible.has(c); });

      if (count) {
        count.textContent = list.length
          ? `${fmt.format(Math.min(shown, list.length))} de ${fmt.format(list.length)} ${list.length === 1 ? one : many}`
          : '';
      }
      if (empty) empty.hidden = list.length > 0;
      if (more) {
        more.hidden = list.length <= shown;
        if (!more.hidden) more.textContent = `Mostrar más ${many} (${fmt.format(list.length - shown)})`;
      }

      const url = new URL(location.href);
      term ? url.searchParams.set('q', q.value.trim()) : url.searchParams.delete('q');
      tem ? url.searchParams.set('tematica', tem) : url.searchParams.delete('tematica');
      history.replaceState(null, '', url);
    };
    const reset = () => { shown = perPage || Infinity; render(); };

    let t;
    q?.addEventListener('input', () => { clearTimeout(t); t = setTimeout(reset, 150); });
    orden?.addEventListener('change', reset);
    chips.forEach((chip) => chip.addEventListener('click', () => {
      chips.forEach((c) => c.setAttribute('aria-pressed', String(c === chip)));
      reset();
    }));
    more?.addEventListener('click', () => { shown += perPage; render(); });
    empty?.querySelector('[data-dir-reset]')?.addEventListener('click', () => {
      if (q) q.value = '';
      chips.forEach((c) => c.setAttribute('aria-pressed', String(c.dataset.tematica === '')));
      reset();
    });

    grid.classList.add('is-ready');
    render();
  });

  /* ── Búsqueda en vivo de los listados paginados en el servidor
     (page-formaciones.php, page-articulos.php). Sin JS es un <form> GET
     común; con JS, mientras se escribe (o al cambiar el orden) se pide la
     misma página con los nuevos parámetros y se reemplazan las zonas
     [data-live-region] (resultados, sidebar con conteos, botón de filtros
     en mobile) — el input no se toca, así no pierde el foco. */
  document.querySelectorAll('form[data-oec-live-search]').forEach((form) => {
    const input = form.querySelector('input[name="q"]');
    const base  = form.getAttribute('action').split('?')[0];
    let timer;
    let ctrl;

    const run = () => {
      const url = new URL(base, location.href);
      new FormData(form).forEach((v, k) => { if (String(v).trim() !== '') url.searchParams.set(k, String(v).trim()); });
      if (url.href === location.href) return;

      ctrl?.abort();
      ctrl = new AbortController();
      document.querySelectorAll('[data-live-region="results"]').forEach((el) => el.classList.add('is-loading'));
      fetch(url, { signal: ctrl.signal, credentials: 'same-origin' })
        .then((r) => (r.ok ? r.text() : Promise.reject(r.status)))
        .then((html) => {
          const doc = new DOMParser().parseFromString(html, 'text/html');
          document.querySelectorAll('[data-live-region]').forEach((el) => {
            const fresh = doc.querySelector(`[data-live-region="${el.dataset.liveRegion}"]`);
            if (fresh) el.innerHTML = fresh.innerHTML;
            el.classList.remove('is-loading');
          });
          history.replaceState(null, '', url);
        })
        .catch((e) => { if (e?.name !== 'AbortError') location.href = url; });
    };

    input?.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(run, 300); });
    form.querySelectorAll('select').forEach((s) => s.addEventListener('change', run));
    form.addEventListener('submit', (e) => { e.preventDefault(); clearTimeout(timer); run(); });
  });

  /* ── [oec-agenda]: días del mes que viene ↔ cards de la tira de abajo ──
     Cuenta las cards por data-start, habilita los días con formaciones y
     al elegir uno filtra la tira a ese día. Si el mes que viene tiene
     menos de data-min formaciones, suma el mes siguiente (días + cards);
     si no, las cards de ese segundo mes quedan ocultas. */
  document.querySelectorAll('[data-oec-agenda]').forEach((agenda) => {
    // La tira es la primera .oec-scroll-section que viene después.
    let strip = agenda.nextElementSibling;
    while (strip && !strip.matches('.oec-scroll-section') && !strip.querySelector('.oec-scroll-section')) {
      strip = strip.nextElementSibling;
    }
    strip = strip && (strip.matches('.oec-scroll-section') ? strip : strip.querySelector('.oec-scroll-section'));
    if (!strip) return;

    const track  = strip.querySelector('.oec-scroll-track');
    const cards  = [...strip.querySelectorAll('.oec-card[data-start]')];
    const days   = [...agenda.querySelectorAll('.oec-agenda__day')];
    const main   = days.filter((d) => d.dataset.day && !d.classList.contains('is-extra'));
    const extra  = days.filter((d) => d.classList.contains('is-extra'));
    const title  = agenda.querySelector('[data-agenda-title]');
    const sub    = agenda.querySelector('[data-agenda-sub]');
    const sep    = agenda.querySelector('[data-agenda-sep]');
    const { mes, mes2 } = agenda.dataset;
    const min    = parseInt(agenda.dataset.min, 10) || 0;
    const plural = (n, a, b) => `${n} ${n === 1 ? a : b}`;

    const porDia = {};
    cards.forEach((c) => { if (c.dataset.start) porDia[c.dataset.start] = (porDia[c.dataset.start] || 0) + 1; });
    const suma = (list) => list.reduce((t, d) => t + (porDia[d.dataset.day] || 0), 0);
    const n1 = suma(main);
    const n2 = suma(extra);
    const ampliar = n1 < min && n2 > 0;
    if (!n1 && !ampliar) return; // sin datos: queda el encabezado tal cual

    // Días (y cards) que forman parte de la agenda.
    const activos = new Set((ampliar ? main.concat(extra) : main).map((d) => d.dataset.day));
    const enAgenda = cards.filter((c) => activos.has(c.dataset.start));
    cards.forEach((c) => { c.hidden = !activos.has(c.dataset.start); });

    const periodo = ampliar ? `${mes} y ${mes2}` : mes;
    if (ampliar) {
      extra.forEach((d) => { d.hidden = false; });
      sep.hidden = false;
      title.textContent = `Empiezan en ${periodo}`;
    }

    days.forEach((d) => {
      const n = porDia[d.dataset.day];
      if (!d.dataset.day || !n || !activos.has(d.dataset.day)) return;
      d.disabled = false;
      d.classList.add('has-starts');
      const cnt = d.querySelector('.oec-agenda__cnt');
      cnt.textContent = n;
      cnt.hidden = false;
      d.setAttribute('aria-label', `${d.dataset.label}: ${plural(n, 'formación', 'formaciones')}`);
    });

    const textoTodo = `${plural(enAgenda.length, 'formación empieza', 'formaciones empiezan')} en ${periodo}. Elegí un día para ver cuáles arrancan ese día.`;
    sub.textContent = textoTodo;

    days.forEach((d) => d.addEventListener('click', () => {
      days.forEach((x) => x.setAttribute('aria-pressed', String(x === d)));
      const dia = d.dataset.day;
      cards.forEach((c) => { c.hidden = !activos.has(c.dataset.start) || (!!dia && c.dataset.start !== dia); });
      if (track) track.scrollTo({ left: 0 });
      sub.textContent = dia
        ? `${plural(porDia[dia], 'formación empieza', 'formaciones empiezan')} el ${d.dataset.label}.`
        : textoTodo;
    }));
  });

  /* ── [oec-novedades]: marca las cards de "Recién llegadas" ─────────────
     Con el mapa id → {pct, exp, pub} que imprime el shortcode: etiqueta de
     descuento por pago anticipado (−30 %, vencimiento y días que quedan) o
     "Nueva · hace N días". Filtro "Con descuento" si hay alguna. */
  document.querySelectorAll('[data-oec-novedades]').forEach((box) => {
    let strip = box.nextElementSibling;
    while (strip && !strip.matches('.oec-scroll-section') && !strip.querySelector('.oec-scroll-section')) {
      strip = strip.nextElementSibling;
    }
    strip = strip && (strip.matches('.oec-scroll-section') ? strip : strip.querySelector('.oec-scroll-section'));
    if (!strip) return;

    let mapa = {};
    try { mapa = JSON.parse(box.querySelector('[data-novedades-map]').textContent || '{}'); } catch (e) { /* sin mapa */ }

    const DIA   = 86400000;
    const hoy   = new Date(); hoy.setHours(0, 0, 0, 0);
    const meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    const cards = [...strip.querySelectorAll('.oec-card[data-id]')];
    const conDescuento = [];

    cards.forEach((card) => {
      const d = mapa[card.dataset.id];
      const wrap = card.querySelector('.oec-image-wrapper');
      if (!d || !wrap) return;
      const tag = document.createElement('span');

      if (d.pct && d.exp) {
        const [y, m, dd] = d.exp.split('-').map(Number);
        const quedan = Math.round((new Date(y, m - 1, dd) - hoy) / DIA);
        if (quedan < 0) return;
        const cuando = quedan === 0 ? 'último día' : quedan === 1 ? 'queda 1 día' : `quedan ${quedan} días`;
        tag.className = 'oec-promo' + (quedan <= 7 ? ' is-urgent' : '');
        tag.innerHTML = `<strong class="oec-promo__pct">−${d.pct}%</strong>`
          + `<span class="oec-promo__txt"><b>Pago anticipado</b>hasta el ${dd} ${meses[m - 1]} · ${cuando}</span>`;
        card.dataset.descuento = '1';
        card.dataset.vence = d.exp;
        conDescuento.push(card);
      } else if (d.pub) {
        const [y, m, dd] = d.pub.split('-').map(Number);
        const hace = Math.round((hoy - new Date(y, m - 1, dd)) / DIA);
        tag.className = 'oec-new-pill';
        tag.textContent = hace <= 0 ? 'Nueva · hoy' : hace === 1 ? 'Nueva · ayer' : `Nueva · hace ${hace} días`;
      } else {
        return;
      }
      wrap.appendChild(tag);
    });

    const desc = box.querySelector('[data-novedades-desc]');
    if (desc) {
      desc.textContent = conDescuento.length
        ? `Hay ${conDescuento.length} con descuento por pago anticipado: cuanto antes te inscribas, menos pagás.`
        : '';
    }

    const filtros = box.querySelector('.oec-novedades__filters');
    if (!conDescuento.length || !filtros) return;
    filtros.hidden = false;
    filtros.querySelector('[data-novedades-count]').textContent = conDescuento.length;
    const track = strip.querySelector('.oec-scroll-track');
    const chips = [...filtros.querySelectorAll('[data-filtro]')];
    const primera = cards[0];
    chips.forEach((chip) => chip.addEventListener('click', () => {
      chips.forEach((c) => c.setAttribute('aria-pressed', String(c === chip)));
      const solo = chip.dataset.filtro === 'descuento';
      cards.forEach((c) => { c.hidden = solo && c.dataset.descuento !== '1'; });
      // Con el filtro: primero las que vencen antes. Sin filtro: orden original.
      const orden = solo
        ? [...conDescuento].sort((a, b) => a.dataset.vence.localeCompare(b.dataset.vence))
        : cards;
      if (primera) orden.forEach((c) => primera.parentNode.insertBefore(c, primera.parentNode.querySelector('.oec-scroll-more')));
      if (track) track.scrollTo({ left: 0 });
    }));
  });

  /* ── [oec-clases]: reproductor + lista de clases de Vimeo ──────────────
     La clase se reproduce en la página (iframe de Vimeo recién al tocar
     play). Con la API postMessage del player se escucha el fin de la
     clase: aparece "Ver el curso completo" y la siguiente arranca sola a
     los 8 segundos, salvo que se cancele. */
  document.querySelectorAll('[data-oec-clases]').forEach((box) => {
    const player = box.querySelector('[data-clases-player]');
    const poster = box.querySelector('[data-clases-poster]');
    const endBox = box.querySelector('[data-clases-end]');
    const next   = box.querySelector('[data-clases-next]');
    const items  = [...box.querySelectorAll('[data-clase]')];
    if (!player || !items.length) return;

    let actual = 0;
    let iframe = null;
    let timer  = null;

    const pintar = (i) => {
      const it = items[i].dataset;
      items.forEach((b, j) => { if (j === i) b.setAttribute('aria-current', 'true'); else b.removeAttribute('aria-current'); });
      box.querySelector('[data-clases-title]').textContent = it.title;
      box.querySelector('[data-clases-desc]').textContent = it.desc;
      box.querySelectorAll('[data-clases-more]').forEach((a) => { a.href = it.more; a.hidden = !it.more; });
      box.querySelector('[data-clases-dur]').textContent = it.dur;
      poster.style.backgroundImage = `url('${it.thumb}')`;
    };

    const cortarCuenta = () => { clearInterval(timer); timer = null; endBox.hidden = true; };

    const reproducir = (i) => {
      actual = i;
      cortarCuenta();
      pintar(i);
      if (iframe) iframe.remove();
      iframe = document.createElement('iframe');
      iframe.src = `https://player.vimeo.com/video/${items[i].dataset.id}?autoplay=1&dnt=1&title=0&byline=0&portrait=0`;
      iframe.allow = 'autoplay; fullscreen; picture-in-picture';
      iframe.allowFullscreen = true;
      iframe.title = items[i].dataset.title;
      player.appendChild(iframe);
      poster.hidden = true;
      items.forEach((b, j) => b.classList.toggle('is-playing', j === i));
      if (window.matchMedia('(min-width: 1001px)').matches) {
        // Desktop: la lista (al costado) acompaña a la clase elegida.
        items[i].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
      } else if (player.getBoundingClientRect().top < 0) {
        // Mobile: la lista está debajo; llevar la pantalla al reproductor.
        player.scrollIntoView({ block: 'start', behavior: 'smooth' });
      }
    };

    // Mensajes del player de Vimeo: al estar listo, pedirle el evento "ended".
    window.addEventListener('message', (e) => {
      if (!iframe || e.source !== iframe.contentWindow || !/player\.vimeo\.com$/.test(new URL(e.origin).host)) return;
      let data = e.data;
      try { if (typeof data === 'string') data = JSON.parse(data); } catch (err) { return; }
      if (data.event === 'ready') {
        iframe.contentWindow.postMessage(JSON.stringify({ method: 'addEventListener', value: 'ended' }), e.origin);
      } else if (data.event === 'ended') {
        const sig = actual + 1 < items.length ? actual + 1 : null;
        endBox.hidden = false;
        if (sig === null) { next.textContent = ''; return; }
        let seg = 8;
        const texto = () => { next.textContent = `Siguiente: «${items[sig].dataset.title}» en ${seg} s`; };
        texto();
        timer = setInterval(() => {
          seg -= 1;
          if (seg <= 0) { reproducir(sig); } else { texto(); }
        }, 1000);
      }
    });

    // La lista (en desktop, al costado) tiene el mismo alto que el reproductor + info.
    const main = box.querySelector('.oec-clases__main');
    const list = box.querySelector('.oec-clases__list');
    const igualar = () => {
      list.style.maxHeight = window.matchMedia('(min-width: 1001px)').matches ? `${main.offsetHeight}px` : '';
    };
    igualar();
    window.addEventListener('resize', igualar);

    poster.addEventListener('click', () => reproducir(actual));
    items.forEach((b, i) => b.addEventListener('click', () => reproducir(i)));
    box.querySelector('[data-clases-cancel]')?.addEventListener('click', cortarCuenta);
  });

  /* ── [oec-org-spotlight]: vitrina de organizaciones ────────────────────
     Pestañas accesibles (flechas ←/→ también). Rota sola cada 7 s mientras
     está en pantalla; se pausa con el mouse/foco encima y deja de rotar si
     la persona elige una. La barra de progreso de la pestaña activa es CSS
     (animation) y se reinicia en cada cambio. */
  document.querySelectorAll('[data-oec-orgs]').forEach((box) => {
    // Orden al azar en cada visita: pestaña y panel se mueven juntos (los
    // une aria-controls) y arranca seleccionada la primera del nuevo orden.
    const filaTabs = box.querySelector('.oec-orgs__tabs');
    const filaPans = box.querySelector('.oec-orgs__panels');
    if (filaTabs && filaPans) {
      mezclar([...filaTabs.querySelectorAll('.oec-orgs__tab')]).forEach((t, j) => {
        const p = box.querySelector('#' + t.getAttribute('aria-controls'));
        filaTabs.appendChild(t);
        if (p) { filaPans.appendChild(p); p.hidden = j !== 0; }
        t.setAttribute('aria-selected', String(j === 0));
      });
    }

    const tabs   = [...box.querySelectorAll('.oec-orgs__tab')];
    const panels = [...box.querySelectorAll('.oec-orgs__panel')];
    if (tabs.length < 2) return;

    const INTERVALO = 7000;
    let actual = 0, timer = null, visible = false, pausa = false, manual = false;
    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    // En pantallas angostas cada panel tiene otro alto: que cambie solo
    // mientras alguien lee mueve todo. Ahí se cambia tocando los logos.
    const angosta = window.matchMedia('(max-width: 1000px)');

    const mostrar = (i, foco = false) => {
      actual = (i + tabs.length) % tabs.length;
      tabs.forEach((t, j) => {
        t.setAttribute('aria-selected', String(j === actual));
        t.tabIndex = j === actual ? 0 : -1;
      });
      panels.forEach((p, j) => { p.hidden = j !== actual; });
      // reiniciar la barra de progreso
      const bar = tabs[actual].querySelector('.oec-orgs__tab-progress');
      bar.style.animation = 'none'; void bar.offsetWidth; bar.style.animation = '';
      // Solo desplazar la fila de pestañas (en mobile es horizontal), nunca
      // la página: si no, la rotación le movería la pantalla a quien lee.
      const fila = tabs[actual].parentElement;
      if (fila.scrollWidth > fila.clientWidth) {
        fila.scrollTo({ left: tabs[actual].offsetLeft - fila.offsetLeft - 16, behavior: 'smooth' });
      }
      if (foco) tabs[actual].focus({ preventScroll: true });
    };

    const girar = () => {
      clearInterval(timer);
      const corre = visible && !pausa && !manual && !reduce && !angosta.matches;
      box.classList.toggle('is-rotating', corre);
      if (corre) timer = setInterval(() => mostrar(actual + 1), INTERVALO);
    };

    tabs.forEach((t, i) => {
      t.tabIndex = i === 0 ? 0 : -1;
      t.addEventListener('click', () => { manual = true; mostrar(i); girar(); });
      t.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowRight' || e.key === 'ArrowDown') { e.preventDefault(); manual = true; mostrar(actual + 1, true); girar(); }
        if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') { e.preventDefault(); manual = true; mostrar(actual - 1, true); girar(); }
      });
    });
    box.addEventListener('mouseenter', () => { pausa = true; girar(); });
    box.addEventListener('mouseleave', () => { pausa = false; girar(); });
    box.addEventListener('focusin', () => { pausa = true; girar(); });
    box.addEventListener('focusout', () => { pausa = false; girar(); });

    if ('IntersectionObserver' in window) {
      new IntersectionObserver(([e]) => { visible = e.isIntersecting; girar(); }, { threshold: 0.35 }).observe(box);
    }
  });

  /* ── Video del hero ([oec-hero-video]): llega sin src y se pide recién con
     la primera interacción (mouse, toque, scroll o teclado), como los
     trackers. Antes arrancaba en el "load": se bajaban 3–9 MB durante la
     carga y el hero seguía cambiando (PageSpeed: Speed Index y peso total).
     Mientras tanto se ve el poster. Nunca con ahorro de datos o "reducir
     movimiento". ── */
  const heroVideos = document.querySelectorAll('video[data-oec-src]');
  if (heroVideos.length) {
    const conn = navigator.connection || {};
    const skip = conn.saveData || /(^|-)2g$/.test(conn.effectiveType || '')
      || window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (!skip) {
      const evs = ['pointerdown', 'pointermove', 'touchstart', 'scroll', 'keydown', 'wheel'];
      const opts = { passive: true, capture: true };
      const start = () => {
        evs.forEach((ev) => window.removeEventListener(ev, start, opts));
        heroVideos.forEach((video) => {
          video.src = video.dataset.oecSrc;
          video.play().catch(() => {});
        });
      };
      evs.forEach((ev) => window.addEventListener(ev, start, opts));
    }
  }

  /* ── Video del hero que rota entre las landings ([oec-hero-video] en el
     home): al terminar uno, pasa al siguiente de la lista. ── */
  document.querySelectorAll('video[data-oec-videos]').forEach((video) => {
    let lista = [];
    try { lista = JSON.parse(video.dataset.oecVideos); } catch (e) { return; }
    if (lista.length < 2) return;
    let i = 0;
    video.addEventListener('ended', () => {
      i = (i + 1) % lista.length;
      video.src = lista[i];
      video.play().catch(() => {});
    });
  });

  /* ── Cinta de logos [oec-trust-logos] ───────────────────────
     La pista trae los logos dos veces; se corre el largo exacto de una
     vuelta (del 1.º logo al 1.º de la segunda copia) y se empalma. Con el
     mouse encima la velocidad baja a 0 con easing, sin saltos. */
  document.querySelectorAll('.oec-trust__marquee').forEach((marquee) => {
    const track = marquee.querySelector('.oec-trust__track');
    const imgs  = track ? track.querySelectorAll('img') : [];
    if (!imgs.length || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    const SPEED = 40; // px/s
    let loop = 0, x = 0, speed = SPEED, target = SPEED, last = 0, visible = true;
    const measure = () => { loop = imgs[imgs.length / 2].offsetLeft - imgs[0].offsetLeft; };
    measure();
    imgs.forEach((img) => { if (!img.complete) img.addEventListener('load', measure, { once: true }); });
    window.addEventListener('resize', measure);

    marquee.addEventListener('mouseenter', () => { target = 0; });
    marquee.addEventListener('mouseleave', () => { target = SPEED; });
    if ('IntersectionObserver' in window) {
      new IntersectionObserver(([e]) => { visible = e.isIntersecting; }).observe(marquee);
    }

    const tick = (now) => {
      const dt = last ? Math.min((now - last) / 1000, 0.1) : 0;
      last = now;
      speed += (target - speed) * Math.min(dt * 4, 1);
      if (visible && loop > 0) {
        x -= speed * dt;
        if (-x >= loop) x += loop;
        track.style.transform = `translate3d(${x}px,0,0)`;
      }
      requestAnimationFrame(tick);
    };
    requestAnimationFrame(tick);
  });

  /* ── Fade-in on scroll ──────────────────────────────────── */
  const observer = new IntersectionObserver(
    (entries) => entries.forEach((e) => {
      if (e.isIntersecting) { e.target.classList.add('is-visible'); observer.unobserve(e.target); }
    }),
    { threshold: 0.1 }
  );
  document.querySelectorAll('.post-card, .training-card').forEach((el) => {
    el.classList.add('fade-in');
    observer.observe(el);
  });

})();
