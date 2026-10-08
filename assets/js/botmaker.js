/* ============================================================
   CHAT ANÓNIMO (Botmaker) — home y landings de temática

   Mismo comportamiento que la ficha de formación del plugin
   (js/oec-formacion.js, initBotmakerChat):
   - El script de Botmaker (~300 KB entre su app, fuentes y polyfill) se
     pide recién ante la primera intención sobre un disparador: hover,
     foco o toque (precalienta) o el clic mismo. Antes se cargaba en
     cada visita aunque casi nadie abriera el chat.
   - Su "pelotita" flotante se esconde siempre (bmHide + CSS del contenido
     con body.oec-bm-chat-open); solo se ve mientras el usuario lo abrió
     desde un .oec-botmaker-trigger. Mientras está abierto se esconde
     nuestra burbuja (.oec-float-botmaker, CSS en style.css): en su lugar
     Botmaker muestra su cruz de cerrar.
   - bmHide/bmShow/bmMaximize/bmSendMessage no están documentadas por
     Botmaker: si dejan de existir, el botón no abre nada pero no rompe.
   ============================================================ */
(function () {
  'use strict';

  const SRC = window.oecBotmaker && window.oecBotmaker.src;
  const triggers = document.querySelectorAll('.oec-botmaker-trigger');
  if (!SRC || !triggers.length) return;

  // El ícono flotante vive dentro del contenido: se lo pasa a <body> para
  // que position:fixed no dependa de un contenedor con transform/overflow.
  const float = document.getElementById('oec-float-botmaker');
  if (float && float.parentNode !== document.body) document.body.appendChild(float);

  let loaded = false;
  let userWantsOpen = false;

  const ready = () => ['bmHide', 'bmShow', 'bmMaximize', 'bmSendMessage']
    .every((fn) => typeof window[fn] === 'function');

  function whenReady(fn, triesLeft) {
    if (ready()) { fn(); return; }
    if (triesLeft <= 0) return; // Botmaker no cargó (bloqueado, caído…)
    setTimeout(() => whenReady(fn, triesLeft - 1), 200);
  }

  // Las funciones bm* aparecen ANTES de que Botmaker termine de conectarse
  // con su servidor, y bmSendMessage descarta el mensaje sin avisar mientras
  // no tenga usuario ("No user or business defined yet"). Con la carga al
  // vuelo eso pasaba casi siempre: se espera a que bmInfo() traiga el
  // contacto. Si bmInfo deja de existir, se vuelve al retraso fijo de antes.
  function whenConnected(fn, triesLeft) {
    if (typeof window.bmInfo !== 'function') { setTimeout(fn, 500); return; }
    let info = null;
    try { info = window.bmInfo(); } catch (err) { /* todavía montándose */ }
    if ((info && info.platformContactId) || triesLeft <= 0) { fn(); return; }
    setTimeout(() => whenConnected(fn, triesLeft - 1), 200);
  }

  // El mensaje de cada botón se manda una sola vez por visita: reabrir el
  // chat no lo repite en la conversación.
  const sent = new Set();

  const bmIframe = () => document.querySelector('iframe[name="Botmaker"]');

  function setOpen(open) {
    userWantsOpen = open;
    document.body.classList.toggle('oec-bm-chat-open', open);
  }

  function load() {
    if (loaded) return;
    loaded = true;
    const js = document.createElement('script');
    js.async = true;
    js.src = SRC;
    document.body.appendChild(js);

    // Si el usuario cierra desde adentro del widget, Botmaker queda
    // "minimizado" (iframe > 0 px) en vez de escondido: se lo vuelve a
    // esconder mientras no lo haya pedido abierto.
    whenReady(() => {
      setInterval(() => {
        if (userWantsOpen) return;
        const iframe = bmIframe();
        if (!iframe) return;
        const r = iframe.getBoundingClientRect();
        if (r.width > 0 || r.height > 0) window.bmHide();
      }, 400);
    }, 75); // hasta 15 s (red móvil lenta)
  }

  triggers.forEach((trigger) => {
    ['pointerenter', 'focus', 'touchstart'].forEach((ev) =>
      trigger.addEventListener(ev, load, { once: true, passive: true }));
    trigger.addEventListener('click', (e) => {
      e.preventDefault();
      load();
      trigger.classList.add('oec-bm-loading');
      whenReady(() => {
        trigger.classList.remove('oec-bm-loading');
        setOpen(true);
        window.bmShow();
        window.bmMaximize();
        const msg = trigger.getAttribute('data-msg') || '';
        if (msg && !sent.has(msg)) {
          sent.add(msg);
          whenConnected(() => window.bmSendMessage(msg), 75);
        }
      }, 75);
    });
  });

  // "Cerrar chat" de Botmaker: se re-engancha en cada tick porque Botmaker
  // reescribe su documento y a veces reusa el botón de la sesión anterior.
  setInterval(() => {
    if (!userWantsOpen) return;
    // Minimizado por otra vía (no por "Cerrar chat"): vuelve la burbuja.
    try {
      if (typeof window.bmInfo === 'function' && window.bmInfo().isMinimized) {
        setOpen(false);
        return;
      }
    } catch (err) { /* sigue el vigía de abajo */ }
    try {
      const iframe = bmIframe();
      const closeBtn = iframe && iframe.contentDocument
        && iframe.contentDocument.querySelector('[aria-label="Cerrar chat"]');
      if (closeBtn && !closeBtn.dataset.oecBound) {
        closeBtn.dataset.oecBound = '1';
        closeBtn.addEventListener('click', () => {
          delete closeBtn.dataset.oecBound;
          setOpen(false);
        }, { once: true });
      }
    } catch (err) { /* otro origen: el vigía de tamaño alcanza */ }
  }, 400);
})();
