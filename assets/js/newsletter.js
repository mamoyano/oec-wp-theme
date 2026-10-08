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

  // La dirección figura como spam en Elastic Email: el servidor pide una
  // declaración explícita antes de reactivarla (code: 'abuse').
  function showAbuseConsent(form, label, beforeEl) {
    var box = form.querySelector('.oec-nl-consent');
    if (!box) {
      box = document.createElement('label');
      box.className = 'oec-nl-consent';
      box.innerHTML = '<input type="checkbox" name="abuse_consent" value="1"> <span></span>';
      beforeEl.parentNode.insertBefore(box, beforeEl);
    }
    box.querySelector('span').textContent = label;
    box.querySelector('input').focus();
  }

  function abuseConsent(form) {
    var el = form.querySelector('[name="abuse_consent"]');
    return !!(el && el.checked);
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
        abuse_consent: abuseConsent(form),
        website: form.querySelector('[name="website"]').value
      }).then(function (data) {
        setMsg(offerMsg, data.message || 'Ocurrió un error. Probá de nuevo.', !data.ok);
        if (data.code === 'abuse') showAbuseConsent(form, data.consent_label, offerBtn);
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
        abuse_consent: abuseConsent(form),
        website: form.querySelector('[name="website"]').value
      }).then(function (data) {
        setMsg(msg, data.message || 'Ocurrió un error. Probá de nuevo.', !data.ok);
        if (data.code === 'abuse') showAbuseConsent(form, data.consent_label, form.querySelector('.oec-nl__legal') || btn);
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

  /* ── 4. Mis suscripciones (/mis-suscripciones/) ──────────────
     Entra con:
     - ?acceso= (token del link que llega por email), o
     - ?email=&clave= (link "Gestionar mis suscripciones" de los newsletters), o
     - el email + clave guardados en este navegador (la última vez que entró).
     Si no, pide el email y manda el link. Una vez adentro guarda email + clave
     en localStorage para no volver a validar; "¿No sos vos?" lo olvida. */
  var LS_ACCESS = 'oec_nl_access';

  function readAccess() {
    try {
      var a = JSON.parse(localStorage.getItem(LS_ACCESS) || 'null');
      return a && a.email && a.clave ? a : null;
    } catch (_e) { return null; }
  }
  function saveAccess(email, clave) {
    try { localStorage.setItem(LS_ACCESS, JSON.stringify({ email: email, clave: clave })); } catch (_e) {}
  }
  function forgetAccess() {
    try { localStorage.removeItem(LS_ACCESS); } catch (_e) {}
  }

  function initManage(root) {
    var params = new URLSearchParams(window.location.search);
    // Elastic Email no codifica {email}: un "+" llega como espacio.
    var urlEmail = (params.get('email') || '').replace(/ /g, '+');
    var urlClave = params.get('clave') || '';
    var token = params.get('acceso') || '';
    var stored = readAccess();
    // Identidad para la API: token del email, clave del newsletter o la recordada.
    var identity = token ? { token: token }
      : (urlEmail && urlClave && urlClave.indexOf('{') === -1) ? { email: urlEmail, clave: urlClave }
      : (stored && (!urlEmail || urlEmail.toLowerCase() === stored.email.toLowerCase())) ? { email: stored.email, clave: stored.clave }
      : null;
    // El acceso no debe quedar en la barra de direcciones ni en el historial.
    if ((token || urlClave) && window.history && history.replaceState) {
      history.replaceState(null, '', window.location.pathname);
    }
    var states = {};
    root.querySelectorAll('[data-state]').forEach(function (el) { states[el.dataset.state] = el; });
    function show(name) {
      Object.keys(states).forEach(function (k) { states[k].hidden = k !== name; });
    }
    function fail(text) {
      states.error.querySelector('.oec-nl-landing__error').textContent = text;
      show('error');
    }

    // 1. Pedir el link.
    var reqForm = states.request.querySelector('.oec-nl-manage__request');
    var reqMsg = states.request.querySelector('.oec-nl-manage__msg');
    var reqBtn = reqForm.querySelector('button');
    var emailIn = reqForm.querySelector('[name="email"]');
    emailIn.value = urlEmail || (stored && stored.email) || getEmail();
    reqForm.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!emailIn.value || !emailIn.checkValidity()) {
        setMsg(reqMsg, 'Ingresá un email válido.', true);
        emailIn.focus();
        return;
      }
      setBusy(reqBtn, true);
      post(root.dataset.link, { email: emailIn.value.trim(), website: reqForm.querySelector('[name="website"]').value })
        .then(function (data) {
          setMsg(reqMsg, data.message || 'Ocurrió un error. Probá de nuevo.', !data.ok);
          if (data.ok) reqForm.hidden = true;
          if (data.code === 'unsubscribed') {
            var sub = states.request.querySelector('.oec-nl-manage__subscribe');
            var subEmail = sub.querySelector('[name="email"]');
            if (subEmail) subEmail.value = emailIn.value.trim();
            sub.hidden = false;
          }
        })
        .catch(function () { setMsg(reqMsg, 'No pudimos conectar. Probá de nuevo en unos segundos.', true); })
        .finally(function () { setBusy(reqBtn, false); });
    });

    if (!identity) { show('request'); return; }

    // 2. Con el link: listas.
    var form = states.prefs.querySelector('.oec-nl-manage__form');
    var list = states.prefs.querySelector('.oec-nl-manage__lists');
    var msg = states.prefs.querySelector('.oec-nl-manage__msg');
    var saveBtn = states.prefs.querySelector('.oec-nl-manage__save');
    var noneBtn = states.prefs.querySelector('.oec-nl-manage__none');

    function render(data) {
      if (data.clave) {
        // Ya validado: de ahora en más se entra con email + clave.
        identity = { email: data.email, clave: data.clave };
        saveAccess(data.email, data.clave);
      }
      states.prefs.querySelector('.oec-nl-manage__email').textContent = data.email;
      list.innerHTML = '';
      data.lists.forEach(function (l) {
        var li = document.createElement('li');
        li.className = 'oec-nl-manage__item' + (l.subscribed ? ' is-on' : '');
        if (l.accent) li.style.setProperty('--nl-accent', l.accent);
        var label = document.createElement('label');
        var input = document.createElement('input');
        input.type = 'checkbox';
        input.name = 'lists[]';
        input.value = l.name;
        input.checked = !!l.subscribed;
        input.addEventListener('change', function () { li.classList.toggle('is-on', input.checked); });
        var body = document.createElement('span');
        body.className = 'oec-nl-manage__body';
        var title = document.createElement('strong');
        title.textContent = l.label;
        body.appendChild(title);
        if (!l.subscribed) {
          var badge = document.createElement('span');
          badge.className = 'oec-nl-manage__badge';
          badge.textContent = '+' + data.credits + ' créditos';
          body.appendChild(badge);
        }
        if (l.desc) {
          var desc = document.createElement('span');
          desc.className = 'oec-nl-manage__desc';
          desc.textContent = l.desc;
          body.appendChild(desc);
        }
        label.appendChild(input);
        label.appendChild(body);
        li.appendChild(label);
        list.appendChild(li);
      });
      show('prefs');
    }

    function save(lists, btn) {
      msg.hidden = true;
      setBusy(btn, true);
      post(root.dataset.save, Object.assign({ lists: lists }, identity))
        .then(function (data) {
          if (data.code === 'expired') { forgetAccess(); return fail(data.message); }
          if (data.lists) render(data);
          setMsg(msg, data.message || 'Ocurrió un error. Probá de nuevo.', !data.ok);
          if (data.ok && data.balance !== null && data.balance !== undefined) storeCredits(data.email, data.balance);
          if (data.ok) track('newsletter_prefs_saved', { newsletter_lists: lists.length });
        })
        .catch(function () { setMsg(msg, 'No pudimos conectar. Probá de nuevo en unos segundos.', true); })
        .finally(function () { setBusy(btn, false); });
    }

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var lists = Array.prototype.map.call(list.querySelectorAll('input:checked'), function (el) { return el.value; });
      save(lists, saveBtn);
    });
    noneBtn.addEventListener('click', function () {
      if (!window.confirm('¿Darte de baja de todos los newsletters de G-SE?')) return;
      list.querySelectorAll('input').forEach(function (el) { el.checked = false; });
      save([], noneBtn);
    });

    // "¿No sos vos?": olvida este navegador y vuelve a pedir el email.
    states.prefs.querySelector('.oec-nl-manage__forget').addEventListener('click', function () {
      forgetAccess();
      identity = null;
      emailIn.value = '';
      reqForm.hidden = false;
      reqMsg.hidden = true;
      show('request');
      emailIn.focus();
    });

    show('loading');
    post(root.dataset.prefs, identity)
      .then(function (data) {
        if (data.ok) return render(data);
        if (!identity.token) {
          // Clave vieja o inválida: se olvida y se pide el link (con el email ya cargado).
          forgetAccess();
          emailIn.value = identity.email || emailIn.value;
          return show('request');
        }
        fail(data.message || 'El link no es válido o venció.');
      })
      .catch(function () { fail('No pudimos conectar. Recargá la página para intentar de nuevo.'); });
  }

  function init() {
    document.querySelectorAll('.oec-nl-hero').forEach(initHero);
    document.querySelectorAll('.oec-nl-manage').forEach(initManage);
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
