/* OEC Theme — newsletter.js
   Tres piezas (ver inc/newsletter.php):
   1. .oec-nl-hero — dentro de [oec-credits-widget]: cuando conocemos el
      email consulta /newsletter/status. No suscripto → ofrece +50
      créditos (nombres y apellidos). Suscripto sin reclamar los créditos
      del último newsletter → ofrece reenviárselo. Todo contra la lista de
      data-list: la general en el home, la de la temática en cada landing.
   2. .oec-nl — formulario del shortcode [oec_newsletter].
   2b. [data-oec-nl-aware] — envuelve un formulario .oec-nl: si ya
      conocemos el email y está suscripto a data-list, cambia el
      formulario por "Ya estás suscripto" (+ reenvío si no reclamó los
      créditos de la semana). Lo usa /creditos-por-descuentos/.
   3. [data-oec-nl-landing] — páginas /newsletter-confirmado/ y
      /otorgar-creditos/: postean los parámetros del link y muestran el
      nuevo balance.
   Usa las mismas claves de storage que el widget de créditos y el badge
   del header, y avisa con el evento "oec:credits-updated". */
(function () {
  'use strict';

  var LS_EMAIL = 'userEmail';
  var SS_CREDITS = 'userCredits';
  var LS_CREDITS_FALLBACK = 'oec_credits_balance';

  function getEmail() {
    try { return localStorage.getItem(LS_EMAIL) || ''; } catch (_e) { return ''; }
  }

  function storeCredits(email, balance) {
    try {
      if (email) localStorage.setItem(LS_EMAIL, email);
      sessionStorage.setItem(SS_CREDITS, String(balance));
      localStorage.setItem(LS_CREDITS_FALLBACK, String(balance));
    } catch (_e) {}
    window.dispatchEvent(new CustomEvent('oec:credits-updated', { detail: { balance: balance } }));
  }

  function post(url, payload) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    }).then(function (res) {
      return res.json().catch(function () { return {}; });
    });
  }

  function track(event, extra) {
    if (!window.dataLayer) return;
    var data = { event: event };
    for (var k in extra) data[k] = extra[k];
    window.dataLayer.push(data);
  }

  function setMsg(el, text, isError) {
    el.textContent = text;
    el.hidden = false;
    el.classList.toggle('is-error', !!isError);
  }

  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // Despliega un .oec-nl-reveal (slide-down + fade, ver style.css).
  function reveal(el) {
    if (!el.hidden && el.classList.contains('is-open')) return;
    el.hidden = false;
    void el.offsetHeight; // fuerza el reflow para que arranque la transición
    el.classList.add('is-open');
  }

  // Lo pliega y recién al terminar lo saca del flujo.
  function conceal(el) {
    if (el.hidden) return;
    el.classList.remove('is-open');
    if (reduceMotion) { el.hidden = true; return; }
    var done = function (e) {
      if (e && e.target !== el) return;
      el.removeEventListener('transitionend', done);
      if (!el.classList.contains('is-open')) el.hidden = true;
    };
    el.addEventListener('transitionend', done);
    setTimeout(done, 600); // por si no dispara transitionend
  }

  // Botón "procesando": spinner + deshabilitado mientras dura el pedido.
  function setBusy(btn, busy) {
    btn.disabled = busy;
    btn.classList.toggle('is-busy', busy);
    btn.setAttribute('aria-busy', busy ? 'true' : 'false');
  }

  /* ── 1. Bloque del newsletter en el widget de créditos ─────────
     Según /status muestra la invitación (no suscripto) o el recordatorio
     de créditos semanales con reenvío (suscripto sin reclamar). */
  function initHero(hero) {
    var checking = hero.querySelector('.oec-nl-checking');
    var offer = hero.querySelector('.oec-nl-offer');
    var weekly = hero.querySelector('.oec-nl-weekly');
    var form = offer.querySelector('.oec-nl-offer__form');
    var offerMsg = offer.querySelector('.oec-nl-offer__msg');
    var offerBtn = offer.querySelector('.oec-nl-offer__submit');
    var resendBtn = weekly.querySelector('.oec-nl-weekly__resend');
    var weeklyMsg = weekly.querySelector('.oec-nl-offer__msg');
    var list = hero.dataset.list || '';
    var checkedFor = '';
    var MIN_CHECKING_MS = 700; // que el "buscando…" no parpadee si la API responde rápido

    function reset() {
      [checking, offer, weekly].forEach(function (el) { el.classList.remove('is-open'); el.hidden = true; });
      form.hidden = false;
      resendBtn.hidden = false;
      offerMsg.hidden = true;
      weeklyMsg.hidden = true;
      form.reset();
      checkedFor = '';
    }

    function check() {
      var email = getEmail();
      if (!email || email === checkedFor) return;
      checkedFor = email;

      conceal(offer);
      conceal(weekly);
      reveal(checking);
      var started = Date.now();

      // Sin caché en el navegador: el servidor ya cachea el resultado por
      // email, y así un error pasajero no deja el bloque oculto toda la sesión.
      post(hero.dataset.status, { email: email, list: list }).then(function (data) {
        var wait = Math.max(0, MIN_CHECKING_MS - (Date.now() - started));
        setTimeout(function () {
          if (getEmail() !== email) return;
          conceal(checking);
          var showWeekly = !data.offer && data.weekly && !data.weekly.claimed;
          if (data.offer) {
            reveal(offer);
            track('newsletter_offer_view', {});
          }
          if (showWeekly) {
            weekly.querySelector('.oec-nl-weekly__date').textContent = data.weekly.date;
            reveal(weekly);
            track('newsletter_weekly_view', {});
          }
        }, wait);
      }).catch(function () {
        conceal(checking);
        checkedFor = '';
      });
    }

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var first = form.querySelector('[name="first_name"]');
      var last = form.querySelector('[name="last_name"]');
      if (!first.value.trim() || !last.value.trim()) {
        setMsg(offerMsg, 'Completá tus nombres y apellidos.', true);
        (first.value.trim() ? last : first).focus();
        return;
      }
      offerMsg.hidden = true;
      setBusy(offerBtn, true);
      post(hero.dataset.subscribe, {
        email: getEmail(),
        first_name: first.value.trim(),
        last_name: last.value.trim(),
        lists: list ? [list] : undefined,
        website: form.querySelector('[name="website"]').value
      }).then(function (data) {
        setMsg(offerMsg, data.message || 'Ocurrió un error. Probá de nuevo.', !data.ok);
        if (data.ok) {
          form.hidden = true;
          track('newsletter_signup', { newsletter_source: 'hero', newsletter_list: list });
        }
      }).catch(function () {
        setMsg(offerMsg, 'No pudimos conectar. Probá de nuevo en unos segundos.', true);
      }).finally(function () {
        setBusy(offerBtn, false);
      });
    });

    resendBtn.addEventListener('click', function () {
      weeklyMsg.hidden = true;
      setBusy(resendBtn, true);
      post(hero.dataset.resend, { email: getEmail(), list: list }).then(function (data) {
        setMsg(weeklyMsg, data.message || 'Ocurrió un error. Probá de nuevo.', !data.ok);
        if (data.ok) {
          resendBtn.hidden = true;
          track('newsletter_resend', {});
        }
      }).catch(function () {
        setMsg(weeklyMsg, 'No pudimos conectar. Probá de nuevo en unos segundos.', true);
      }).finally(function () {
        setBusy(resendBtn, false);
      });
    });

    window.addEventListener('oec:credits-updated', check);
    window.addEventListener('oec:credits-reset', reset);
    check();
  }

  /* ── 2. Formulario del shortcode ────────────────────────────── */
  function initForm(form) {
    var msg = form.querySelector('.oec-nl__msg');
    var btn = form.querySelector('.oec-nl__submit');

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var email = form.querySelector('[name="email"]');
      var first = form.querySelector('[name="first_name"]');
      var last = form.querySelector('[name="last_name"]');
      var lists = Array.prototype.map.call(
        form.querySelectorAll('input[name="lists[]"]:checked, input[type="hidden"][name="lists[]"]'),
        function (el) { return el.value; }
      );

      if (!first.value.trim() || !last.value.trim()) {
        setMsg(msg, 'Completá tus nombres y apellidos.', true);
        return;
      }
      if (!email.value || !email.checkValidity()) {
        setMsg(msg, 'Ingresá un email válido.', true);
        email.focus();
        return;
      }
      if (!lists.length) {
        setMsg(msg, 'Elegí al menos un newsletter.', true);
        return;
      }

      setBusy(btn, true);
      form.classList.add('is-loading');
      post(form.dataset.subscribe, {
        email: email.value.trim(),
        first_name: first.value.trim(),
        last_name: last.value.trim(),
        lists: lists,
        website: form.querySelector('[name="website"]').value
      }).then(function (data) {
        setMsg(msg, data.message || 'Ocurrió un error. Probá de nuevo.', !data.ok);
        if (data.ok) {
          form.classList.add('is-done');
          track('newsletter_signup', { newsletter_source: 'form', newsletter_lists: lists.length });
        }
      }).catch(function () {
        setMsg(msg, 'No pudimos conectar. Probá de nuevo en unos segundos.', true);
      }).finally(function () {
        setBusy(btn, false);
        form.classList.remove('is-loading');
      });
    });
  }

  /* ── 2b. Formulario que sabe si ya estás suscripto ──────────── */
  function initAware(box) {
    var form = box.querySelector('.oec-nl');
    var panel = box.querySelector('[data-nl-subscribed]');
    if (!form || !panel) return;
    var weekly = panel.querySelector('[data-nl-weekly]');
    var next = panel.querySelector('[data-nl-next]');
    var msg = panel.querySelector('[data-nl-msg]');
    var resendBtn = panel.querySelector('[data-nl-resend]');
    var list = box.dataset.list || '';
    var checkedFor = '';

    function showForm() {
      panel.hidden = true;
      form.hidden = false;
    }

    function check() {
      var email = getEmail();
      if (!email) { checkedFor = ''; showForm(); return; }
      if (email === checkedFor) return;
      checkedFor = email;
      var input = form.querySelector('[name="email"]');
      if (input && !input.value) input.value = email;

      post(box.dataset.status, { email: email, list: list }).then(function (data) {
        if (getEmail() !== email) return;
        // Solo con confirmación explícita: un error de Elastic Email no debe
        // esconder el formulario.
        if (data.subscribed !== true) { showForm(); return; }
        panel.querySelector('[data-nl-email]').textContent = email;
        var pending = data.weekly && !data.weekly.claimed;
        weekly.hidden = !pending;
        next.hidden = !!pending;
        if (pending) panel.querySelector('[data-nl-date]').textContent = data.weekly.date;
        msg.hidden = true;
        resendBtn.hidden = false;
        form.hidden = true;
        panel.hidden = false;
      }).catch(function () { checkedFor = ''; });
    }

    resendBtn.addEventListener('click', function () {
      msg.hidden = true;
      setBusy(resendBtn, true);
      post(box.dataset.resend, { email: getEmail(), list: list }).then(function (data) {
        setMsg(msg, data.message || 'Ocurrió un error. Probá de nuevo.', !data.ok);
        if (data.ok) {
          resendBtn.hidden = true;
          track('newsletter_resend', { newsletter_source: 'creditos' });
        }
      }).catch(function () {
        setMsg(msg, 'No pudimos conectar. Probá de nuevo en unos segundos.', true);
      }).finally(function () {
        setBusy(resendBtn, false);
      });
    });

    window.addEventListener('oec:credits-updated', check);
    window.addEventListener('oec:credits-reset', function () { checkedFor = ''; showForm(); });
    check();
  }

  /* ── 3. Páginas de confirmación / créditos semanales ────────── */
  function initLanding(root) {
    var mode = root.dataset.oecNlLanding;
    var params = new URLSearchParams(window.location.search);
    var states = {};
    root.querySelectorAll('[data-state]').forEach(function (el) { states[el.dataset.state] = el; });

    function show(name) {
      Object.keys(states).forEach(function (k) { states[k].hidden = k !== name; });
    }
    function fail(text) {
      states.error.querySelector('.oec-nl-landing__error').textContent = text;
      show('error');
    }

    var payload;
    if (mode === 'confirm') {
      if (!params.get('t')) return fail('Falta el código de confirmación. Abrí el link desde el email que te enviamos.');
      payload = { token: params.get('t') };
    } else {
      if (!params.get('email') || !params.get('t') || !params.get('s')) {
        return fail('Abrí esta página desde el botón "Obtener mis créditos" del newsletter.');
      }
      payload = { email: params.get('email'), t: params.get('t'), s: params.get('s') };
    }

    post(root.dataset.endpoint, payload).then(function (data) {
      if (!data.ok) return fail(data.message || 'Ocurrió un error. Probá de nuevo más tarde.');

      states.done.querySelector('.oec-nl-landing__title').textContent = data.message;
      if (data.balance !== null && data.balance !== undefined) {
        var box = states.done.querySelector('.oec-nl-landing__balance');
        box.querySelector('.oec-nl-landing__balance-num').textContent = parseInt(data.balance, 10).toLocaleString('es-AR');
        box.hidden = false;
        storeCredits(data.email, data.balance);
      }
      show('done');
      track(mode === 'confirm' ? 'newsletter_confirmed' : 'newsletter_weekly_credits', { granted: !!data.granted });
    }).catch(function () {
      fail('No pudimos conectar. Recargá la página para intentar de nuevo.');
    });
  }

  function init() {
    document.querySelectorAll('.oec-nl-hero').forEach(initHero);
    document.querySelectorAll('.oec-nl').forEach(initForm);
    document.querySelectorAll('[data-oec-nl-aware]').forEach(initAware);
    document.querySelectorAll('[data-oec-nl-landing]').forEach(initLanding);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
