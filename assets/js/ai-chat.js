/* OEC Theme — ai-chat.js */
(function () {
  'use strict';

  const cfg            = window.oecAiChat || {};
  const endpoint       = cfg.endpoint || '';
  const streamEndpoint = cfg.streamEndpoint || '';
  const clickEndpoint  = cfg.clickEndpoint || '';
  const nonce          = cfg.nonce || '';
  const botName        = 'Asistente OEC';

  let country  = '';
  let currency = '';
  let history  = [];
  let mentionedFormationIds = []; // últimos 5 IDs vistos, para persistir contexto sin saturar
  let busy     = false;
  let overlayOpen = false;
  let locationFetched = false;

  /* ── Conversación guardada en el navegador ───────────────── */
  // Se mantiene entre páginas (localStorage) y se borra sola tras STORE_TTL
  // sin actividad. cid = ID anónimo de la conversación (para el registro).
  const STORE_KEY = 'oec_ai_chat';
  const STORE_TTL = 2 * 60 * 60 * 1000;
  const STORE_MAX = 40;   // mensajes guardados (el contexto de la IA ya va recortado)
  let cid = '';
  let saved = [];         // [{u: texto}] o [{a: texto con |||, f: tarjetas}]

  function newCid() {
    return (window.crypto && crypto.randomUUID)
      ? crypto.randomUUID()
      : Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 12);
  }

  function loadConversation() {
    let s = null;
    try { s = JSON.parse(localStorage.getItem(STORE_KEY) || 'null'); } catch (_) {}
    if (s && Date.now() - (s.updated || 0) < STORE_TTL) {
      cid     = s.cid || newCid();
      saved   = Array.isArray(s.log) ? s.log : [];
      history = Array.isArray(s.history) ? s.history : [];
      mentionedFormationIds = Array.isArray(s.mentioned) ? s.mentioned : [];
    } else {
      cid = newCid();
      try { localStorage.removeItem(STORE_KEY); } catch (_) {}
    }
  }

  function saveConversation() {
    saved = saved.slice(-STORE_MAX);
    try {
      localStorage.setItem(STORE_KEY, JSON.stringify({
        cid, updated: Date.now(), log: saved, history, mentioned: mentionedFormationIds,
      }));
    } catch (_) {}
    updateEntryPoints();
  }

  function hasConversation() { return saved.length > 0; }

  function resetConversation() {
    cid = newCid();
    saved = [];
    history = [];
    mentionedFormationIds = [];
    try { localStorage.removeItem(STORE_KEY); } catch (_) {}
    const list = document.getElementById('oec-overlay-messages');
    if (list) list.innerHTML = '';
    updateEntryPoints();
    document.getElementById('oec-overlay-input')?.focus();
  }

  // Con una conversación en curso: el campo del header invita a seguirla
  // (Enter con el campo vacío la reabre) y el chat muestra "Nueva conversación".
  function updateEntryPoints() {
    const has = hasConversation();
    ['oec-ai-header', 'oec-ai-mobile'].forEach(p => {
      const input = document.getElementById(p + '-input');
      const btn   = document.getElementById(p + '-send');
      if (!input) return;
      if (!input.dataset.placeholder) input.dataset.placeholder = input.placeholder;
      input.placeholder = has ? 'Continuá tu conversación…' : input.dataset.placeholder;
      if (btn) btn.disabled = !input.value.trim() && !has;
    });
    const nb = document.getElementById('oec-overlay-new');
    if (nb) nb.hidden = !has;
  }

  /* ── Clics en tarjetas → registro (no frena la navegación) ── */
  function trackClick(id) {
    if (!clickEndpoint || !id || !navigator.sendBeacon) return;
    try {
      navigator.sendBeacon(clickEndpoint, new Blob([JSON.stringify({ cid, id })], { type: 'application/json' }));
    } catch (_) {}
  }

  /* ── Helpers ─────────────────────────────────────────────── */
  function escAttr(s) {
    return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;');
  }
  function escText(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }
  function scrollBody() {
    const b = document.getElementById('oec-overlay-body');
    if (b) b.scrollTop = b.scrollHeight;
  }

  /* ── Country/currency (browser-side, uses user IP) ──────── */
  async function detectLocation() {
    try {
      const res = await fetch('https://api.g-se.com/v2/initialPreferences', { cache: 'default' });
      if (res.ok) {
        const d = await res.json();
        country  = d.country  || '';
        currency = d.currency || '';
      }
    } catch (_) {}
  }

  function ensureLocation() {
    if (!locationFetched) {
      locationFetched = true;
      detectLocation();
    }
  }

  /* ── Build full-screen overlay ───────────────────────────── */
  function buildOverlay() {
    const el = document.createElement('div');
    el.id = 'oec-ai-overlay';
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-label', botName);
    el.setAttribute('aria-modal', 'true');

    // Use site header logo if available, fall back to initials
    const siteLogo = document.querySelector('#site-header .site-logo img, #site-header .custom-logo-link img, #site-header img.custom-logo');
    const logoHtml = siteLogo
      ? `<img src="${escAttr(siteLogo.src)}" class="oec-overlay-site-logo" alt="${escAttr(siteLogo.alt || botName)}">`
      : `<div class="oec-overlay-avatar" aria-hidden="true">IA</div>`;

    el.innerHTML = `
      <div id="oec-overlay-topbar">
        <button class="oec-overlay-brand" id="oec-overlay-logo-close" aria-label="Cerrar asistente">
          ${logoHtml}
        </button>
        <button id="oec-overlay-new" class="oec-overlay-new" type="button" hidden>
          <i class="bi bi-plus-lg" aria-hidden="true"></i> Nueva conversación
        </button>
        <button id="oec-overlay-close" aria-label="Cerrar asistente">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
            <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
          </svg>
        </button>
      </div>

      <div id="oec-overlay-body">
        <div id="oec-overlay-messages" role="log" aria-live="polite"></div>
      </div>

      <div id="oec-overlay-footer">
        <div id="oec-overlay-input-wrap">
          <textarea id="oec-overlay-input"
                    rows="1"
                    placeholder="Continuá la conversación…"
                    aria-label="Tu mensaje"></textarea>
          <button id="oec-overlay-send" aria-label="Enviar" disabled>
            <i class="bi bi-arrow-up" aria-hidden="true"></i>
          </button>
        </div>
      </div>
    `;

    document.body.appendChild(el);
  }

  /* ── Teclado mobile: el chat ocupa solo lo visible ───────── */
  // iOS no achica la ventana al abrir el teclado: sin esto, la barra con el
  // botón de enviar queda tapada. Ajusta alto y posición al visualViewport.
  function fitToViewport() {
    const overlay = document.getElementById('oec-ai-overlay');
    const vv = window.visualViewport;
    if (!overlay || !vv) return;
    if (!overlayOpen) {
      overlay.style.height = '';
      overlay.style.top = '';
      return;
    }
    overlay.style.height = vv.height + 'px';
    overlay.style.top    = vv.offsetTop + 'px';
    scrollBody();
  }

  /* ── Open: animación "header se expande" ─────────────────── */
  function openWithMorph(firstMessage) {
    if (overlayOpen) return;
    overlayOpen = true;

    const overlay = document.getElementById('oec-ai-overlay');

    const hi = document.getElementById('oec-ai-header-input');
    if (hi) hi.value = '';
    const hiBtn = document.getElementById('oec-ai-header-send');
    if (hiBtn) hiBtn.disabled = true;
    const mi = document.getElementById('oec-ai-mobile-input');
    if (mi) mi.value = '';
    const miBtn = document.getElementById('oec-ai-mobile-send');
    if (miBtn) miBtn.disabled = true;

    overlay.classList.add('oec-overlay--active');
    document.body.classList.add('oec-chat-open');
    overlay.offsetHeight; // force reflow → CSS transition starts
    overlay.classList.add('oec-overlay--open');
    fitToViewport();
    renderSaved();

    setTimeout(() => {
      // Sin mensaje nuevo = solo reabrir la conversación guardada
      if (firstMessage) {
        appendMsg('user', firstMessage);
        setBusy(true);
        fetchReply(firstMessage);
      }
      const input = document.getElementById('oec-overlay-input');
      if (input) input.focus();
    }, 560);
  }

  /* ── Conversación guardada → mensajes en pantalla (sin animación) ── */
  function renderSaved() {
    const list = document.getElementById('oec-overlay-messages');
    if (!list || list.children.length || !saved.length) return;
    saved.forEach(m => {
      if (m.u !== undefined) { appendMsg('user', m.u); return; }
      const bubbles = String(m.a || '').split('|||').map(p => p.trim()).filter(Boolean).map(part => {
        const b = createAssistantBubble();
        if (b) b.innerHTML = renderMarkdown(part);
        return b;
      }).filter(Boolean);
      if (m.f && m.f.length) {
        upgradeFormationLinks(bubbles, m.f);
        renderCards(m.f);
      }
    });
    updateEntryPoints();
    scrollBody();
  }

  /* ── Close overlay ───────────────────────────────────────── */
  function closeOverlay() {
    if (!overlayOpen) return;
    overlayOpen = false;

    const overlay = document.getElementById('oec-ai-overlay');
    overlay.classList.remove('oec-overlay--open');

    setTimeout(() => {
      overlay.classList.remove('oec-overlay--active');
      fitToViewport();
      document.body.classList.remove('oec-chat-open');
      const messages = document.getElementById('oec-overlay-messages');
      if (messages) messages.innerHTML = '';
      const hi = document.getElementById('oec-ai-header-input');
      if (hi) hi.focus();
    }, 580);
  }

  /* ── Markdown renderer ───────────────────────────────────── */
  function renderMarkdown(raw, streaming = false) {
    // 1. Escape HTML
    let html = raw
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
    // 2. Markdown images — skip during streaming to avoid layout jumps when image loads
    html = html.replace(/!\[([^\]]*)\]\(([^)]+)\)/g, (_, alt, src) => {
      if (streaming) return '';
      const caption = alt.trim()
        ? `<span class="oec-md-img-caption">${alt}</span>`
        : '';
      return `<figure class="oec-md-figure"><img src="${src}" alt="${alt}" class="oec-md-img" loading="lazy">${caption}</figure>`;
    });
    // 2a. Fotos seguidas → una fila propia, debajo del texto (no pegadas a la
    //     línea). Se comen los saltos de línea de alrededor para no dejar <br> sueltos.
    html = html.replace(
      /[ \t]*\n*((?:<figure class="oec-md-figure">.*?<\/figure>\s*)+)\n*/g,
      (_, figs) => '<div class="oec-md-gallery">' + figs.replace(/\s+(?=<figure)|\s+$/g, '') + '</div>'
    );
    // 3. Markdown links [text](url)
    html = html.replace(
      /\[([^\]]+)\]\(([^)]+)\)/g,
      '<a href="$2" class="oec-md-link" target="_blank" rel="noopener noreferrer">$1</a>'
    );
    // 2b. Auto-link bare https:// URLs not already inside an <a> tag
    html = html.replace(
      /(?<![="'])(https?:\/\/[^\s<"']+)/g,
      '<a href="$1" class="oec-md-link" target="_blank" rel="noopener noreferrer">$1</a>'
    );
    // 3. Block: headings
    html = html
      .replace(/^### (.+)$/gm, '<strong class="oec-md-h3">$1</strong>')
      .replace(/^## (.+)$/gm,  '<strong class="oec-md-h2">$1</strong>')
      .replace(/^# (.+)$/gm,   '<strong class="oec-md-h1">$1</strong>');
    // 4. Block: HR
    html = html.replace(/^---+$/gm, '<hr class="oec-md-hr">');
    // 5. Block: numbered list
    html = html.replace(/^(\d+)\. (.+)$/gm,
      '<span class="oec-md-ol"><em class="oec-md-num">$1.</em>$2</span>');
    // 6. Block: bullet list
    html = html.replace(/^[*-] (.+)$/gm,
      '<span class="oec-md-li"><span class="oec-md-li__dot" aria-hidden="true"></span>$1</span>');
    // 7. Inline: bold & italic
    html = html
      .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
      .replace(/\*(.+?)\*/g,     '<em>$1</em>');
    // 7c. Collapse \n between consecutive list items so they don't get a <br> between them
    html = html.replace(/<\/span>\n(<span class="oec-md-(?:li|ol)")/g, '</span>$1');
    // 8. Line breaks
    return html.replace(/\n/g, '<br>');
  }

  /* ── Create an assistant bubble in the messages list ────── */
  function createAssistantBubble() {
    const list = document.getElementById('oec-overlay-messages');
    if (!list) return null;
    const wrap   = document.createElement('div');
    wrap.className = 'oec-msg-row oec-msg-row--assistant';
    const bubble = document.createElement('div');
    bubble.className = 'oec-msg oec-msg--assistant';
    wrap.appendChild(bubble);
    list.appendChild(wrap);
    scrollBody();
    return bubble;
  }

  /* ── Message rendering ───────────────────────────────────── */
  function appendMsg(role, text) {
    const list = document.getElementById('oec-overlay-messages');
    if (!list) return;

    const wrap = document.createElement('div');
    wrap.className = 'oec-msg-row oec-msg-row--' + role;

    const bubble = document.createElement('div');
    bubble.className = 'oec-msg oec-msg--' + role;
    bubble.innerHTML = renderMarkdown(text);

    wrap.appendChild(bubble);
    list.appendChild(wrap);
    scrollBody();
  }

  /* Formación de otra comunidad (swimming.science…): logo redondo + ícono
     de enlace externo, y el link se abre en otra pestaña. */
  function communityBadge(f) {
    if (!f.external) return '';
    return '<span class="oec-community oec-community--chat" title="Formación de ' + escAttr(f.community) + '">'
      + (f.logo ? '<img class="oec-community__logo" src="' + escAttr(f.logo) + '" alt="" width="16" height="16" loading="lazy">' : '')
      + '<span class="oec-community__name">' + escText(f.community) + '</span>'
      + '<i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></span>';
  }
  function markExternal(a, f) {
    if (!f.external) return;
    a.target = '_blank';
    a.rel    = 'noopener';
  }

  /* ── Course cards ────────────────────────────────────────── */
  function renderCards(formations) {
    if (!formations || formations.length === 0) return;
    const list = document.getElementById('oec-overlay-messages');
    if (!list) return;

    // Wrap in an assistant row so cards sit inside the conversation flow
    const row = document.createElement('div');
    row.className = 'oec-msg-row oec-msg-row--assistant';

    const wrap = document.createElement('div');
    wrap.className = 'oec-course-cards';

    formations.forEach(f => {
      const card = document.createElement('a');
      card.href = f.path || f.url || '#';
      card.className = 'oec-course-card';
      card.dataset.id = f.id || '';
      card.setAttribute('aria-label', (f.title || '') + (f.external ? ' (' + f.community + ', se abre en otra pestaña)' : ''));
      markExternal(card, f);

      const imgHtml = f.image
        ? `<img src="${escAttr(f.image)}" class="oec-course-card__img" alt="" loading="lazy">`
        : `<div class="oec-course-card__img oec-course-card__img--empty"></div>`;

      card.innerHTML = `
        ${imgHtml}
        <div class="oec-course-card__body">
          <span class="oec-course-card__type">${escText(f.type || '')}</span>
          <strong class="oec-course-card__title">${escText(f.title || '')}</strong>
          <span class="oec-course-card__org">${escText(f.org || '')}</span>
          ${communityBadge(f)}
        </div>
        <svg class="oec-course-card__arrow" width="14" height="14" viewBox="0 0 24 24"
             fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <polyline points="9 18 15 12 9 6"/>
        </svg>
      `;

      wrap.appendChild(card);
    });

    row.appendChild(wrap);
    list.appendChild(row);
    scrollBody();
  }

  /* ── Typing indicator ────────────────────────────────────── */
  function showTyping() {
    const list = document.getElementById('oec-overlay-messages');
    if (!list || document.getElementById('oec-typing')) return;
    const row = document.createElement('div');
    row.className = 'oec-msg-row oec-msg-row--assistant';
    row.innerHTML = '<div id="oec-typing" class="oec-msg oec-msg--assistant oec-typing">'
                  + '<span></span><span></span><span></span>'
                  + '<em class="oec-typing-status" hidden></em></div>';
    list.appendChild(row);
    scrollBody();
  }

  function hideTyping() {
    document.getElementById('oec-typing')?.closest('.oec-msg-row')?.remove();
  }

  function updateTypingStatus(text) {
    const el     = document.getElementById('oec-typing');
    if (!el) return;
    const status = el.querySelector('.oec-typing-status');
    if (!status) return;

    // Si llegan varios estados seguidos, gana el último (sin fundidos viejos pendientes)
    const swapping = !!status._swapTimer;
    clearTimeout(status._swapTimer);
    status._swapTimer = null;
    status._nextText  = text;

    const show = () => {
      status._swapTimer = null;
      status.textContent = status._nextText;
      status.hidden = false;
      requestAnimationFrame(() => status.classList.add('oec-typing-status--in'));
    };

    if (swapping || status.classList.contains('oec-typing-status--in')) {
      status.classList.remove('oec-typing-status--in');
      status._swapTimer = setTimeout(show, 220);
    } else {
      show();
    }
  }

  function setBusy(state) {
    busy = state;
    const send  = document.getElementById('oec-overlay-send');
    const input = document.getElementById('oec-overlay-input');
    if (send)  send.disabled  = state || !(input?.value.trim());
    if (input) input.disabled = state;
    if (state) showTyping(); else hideTyping();
  }

  /* ── Upgrade plain formation URLs to inline cards ───────── */
  function upgradeFormationLinks(bubbles, formations) {
    if (!formations || !formations.length || !bubbles.length) return;
    bubbles.forEach(function (bubble) {
      bubble.querySelectorAll('a.oec-md-link').forEach(function (link) {
        const href = (link.getAttribute('href') || '').trim();
        const f = formations.find(function (f) { return f.url && f.url.trim() === href; });
        if (!f) return;
        const card = document.createElement('a');
        card.href      = f.path || f.url;
        card.className = 'oec-inline-card';
        card.dataset.id = f.id || '';
        markExternal(card, f);
        card.innerHTML = '<strong class="oec-inline-card__title">' + escText(f.title) + '</strong>'
                       + '<span class="oec-inline-card__meta">'
                       + escText(f.type || '')
                       + (f.org ? ' · ' + escText(f.org) : '')
                       + '</span>'
                       + communityBadge(f);
        link.replaceWith(card);
      });
    });
  }

  /* ── Fetch AI reply via SSE streaming ───────────────────── */
  // Estados locales mientras se espera: si un proxy bufferiza el stream, los
  // estados del servidor llegan junto con el texto y nunca llegarían a verse.
  const LOCAL_STATUSES = [
    'Analizando tu consulta...',
    'Revisando el catálogo de formaciones...',
    'Buscando las opciones que mejor se ajustan...',
    'Preparando la respuesta...',
  ];

  async function fetchReply(message) {
    let fullText   = '';   // complete reply text for history
    let bubText    = '';   // text for the current bubble only
    let bubble     = null;
    let allBubbles = [];   // track every bubble for post-stream URL upgrade
    let started    = false;
    let pending    = '';   // texto recibido que aún no se tipeó
    let streamEnded = false;
    let doneData   = null;

    let statusIdx = 0;
    updateTypingStatus(LOCAL_STATUSES[0]);
    let statusTimer = setInterval(() => {
      if (statusIdx < LOCAL_STATUSES.length - 1) updateTypingStatus(LOCAL_STATUSES[++statusIdx]);
    }, 1800);
    const stopLocalStatus = () => { clearInterval(statusTimer); statusTimer = null; };

    // Pinta un fragmento de texto: separa burbujas por ||| y muestra el cursor
    function renderChunk(chunk) {
      fullText += chunk;
      bubText  += chunk;

      if (!started) {
        started = true;
        stopLocalStatus();
        hideTyping();
        bubble = createAssistantBubble();
        allBubbles.push(bubble);
      }

      while (bubText.includes('|||')) {
        const sepIdx = bubText.indexOf('|||');
        const before = bubText.slice(0, sepIdx).trim();
        bubText      = bubText.slice(sepIdx + 3).trimStart();

        if (bubble) {
          try { bubble.innerHTML = renderMarkdown(before); }
          catch (_) { bubble.textContent = before; }
        }
        bubble = createAssistantBubble();
        allBubbles.push(bubble);
      }

      // No pintar un "|" suelto que puede ser el comienzo de un separador
      const visible = bubText.replace(/\|{1,2}$/, '');
      if (bubble) {
        try {
          bubble.innerHTML = renderMarkdown(visible, true) +
            '<span class="oec-cursor" aria-hidden="true">▌</span>';
        } catch (_) {
          bubble.textContent = visible;
        }
        scrollBody();
      }
    }

    // Efecto de tipeo a ritmo constante, independiente de cómo llegue la red:
    // ~2 caracteres cada 16 ms, acelerando si se acumula texto pendiente.
    // Con la pestaña oculta los timers se frenan: ahí se vuelca todo junto.
    const typed = new Promise(resolve => {
      (function step() {
        if (pending) {
          let n = document.hidden ? pending.length : Math.max(2, Math.ceil(pending.length / 40));
          if (/[\uD800-\uDBFF]/.test(pending.charAt(n - 1))) n++; // no cortar emojis
          renderChunk(pending.slice(0, n));
          pending = pending.slice(n);
        }
        if (pending || !streamEnded) setTimeout(step, 16);
        else resolve();
      })();
    });

    try {
      const req = {
        method:  'POST',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
        body:    JSON.stringify({
          message, history, country, currency, mentioned_ids: mentionedFormationIds,
          cid, turn: saved.filter(m => m.u !== undefined).length, page: location.pathname,
        }),
      };
      let res = await fetch(streamEndpoint, req);
      // Si una regla del servidor intercepta la ruta exacta y da 404, la misma
      // ruta con "/" final la atiende WordPress directamente.
      if (res.status === 404) res = await fetch(streamEndpoint.replace(/\/?$/, '/'), req);

      if (!res.ok || !res.body) throw new Error('stream_unavailable');

      const reader  = res.body.getReader();
      const decoder = new TextDecoder();
      let sseBuf    = '';

      while (true) {
        const { done, value } = await reader.read();
        if (done) break;

        sseBuf += decoder.decode(value, { stream: true });

        const lines = sseBuf.split('\n');
        sseBuf = lines.pop();
        let liveStatus = null;

        for (const line of lines) {
          if (!line.startsWith('data: ')) continue;
          let data;
          try { data = JSON.parse(line.slice(6)); } catch { continue; }

          /* ── status update ── */
          if (data.status !== undefined) {
            liveStatus = data.status;
          }

          /* ── text chunk → cola de tipeo ── */
          if (data.t !== undefined) {
            pending += data.t;
          }

          /* ── stream complete ── */
          if (data.done !== undefined) {
            doneData = data;
          }

          /* ── error event ── */
          if (data.message !== undefined && !data.done) {
            stopLocalStatus();
            setBusy(false);
            appendMsg('assistant', data.message);
          }
        }

        // El estado del servidor solo se muestra si llegó en vivo: si vino en
        // la misma lectura que el texto, el stream está bufferizado y ya pasó.
        if (liveStatus && !started && !pending) {
          stopLocalStatus();
          updateTypingStatus(liveStatus);
        }
      }
    } catch (_) {
      if (!started && !pending) {
        stopLocalStatus();
        setBusy(false);
        appendMsg('assistant', 'Ocurrió un error de conexión. Por favor, intentá de nuevo.');
      }
    }

    streamEnded = true;
    await typed;
    stopLocalStatus();

    if (busy) setBusy(false);

    // Just remove the cursor — no rerender, bubbles already split
    if (bubble) {
      try { bubble.innerHTML = renderMarkdown(bubText.trim()); }
      catch (_) { bubble.textContent = bubText.trim(); }
      scrollBody();
    }

    // Guard: if stream ended without a 'done' event
    if (!doneData) return;

    // Upgrade formation URLs inside bubbles to inline cards, then show card strip
    const formations = doneData.formations;
    if (formations && formations.length) {
      formations.forEach(f => {
        const id = String(f.id);
        mentionedFormationIds = [id, ...mentionedFormationIds.filter(x => x !== id)].slice(0, 5);
      });
      upgradeFormationLinks(allBubbles, formations);
      await new Promise(r => setTimeout(r, 300));
      renderCards(formations);
    }

    // Update conversation history
    const cleanReply = fullText.replace(/\|\|\|/g, '\n\n').trim();
    history.push({ role: 'user',      content: message    });
    history.push({ role: 'assistant', content: cleanReply });
    if (history.length > 10) history = history.slice(-10);

    // Guardar para seguirla en otra página
    saved.push({ u: message }, { a: fullText.trim(), f: formations || [] });
    saveConversation();
  }

  /* ── Send from overlay input ─────────────────────────────── */
  function sendOverlayMessage() {
    if (busy) return;
    const input = document.getElementById('oec-overlay-input');
    if (!input) return;
    const msg = input.value.trim();
    if (!msg) return;

    input.value = '';
    input.style.height = 'auto';
    document.getElementById('oec-overlay-send').disabled = true;

    appendMsg('user', msg);
    setBusy(true);
    fetchReply(msg);
  }

  /* ── Trigger from header input ───────────────────────────── */
  function triggerFromInput(inputEl) {
    const msg = inputEl.value.trim();
    if (busy || (!msg && !hasConversation())) return;
    openWithMorph(msg); // vacío + conversación guardada = reabrirla
  }

  /* ── Hero "¿Qué quieres aprender?" → tipea "Hola" y abre el chat ── */
  let helloRunning = false;

  function typeInto(inputEl, text, done) {
    let i = 0;
    inputEl.value = '';
    (function next() {
      if (i < text.length) {
        inputEl.value += text.charAt(i++);
        inputEl.dispatchEvent(new Event('input', { bubbles: true }));
        setTimeout(next, 90);
      } else {
        setTimeout(done, 300);
      }
    })();
  }

  // Espera a que la página llegue arriba (el header no es sticky) o 900 ms.
  function scrollToTop(done) {
    if (window.scrollY <= 0) { done(); return; }
    window.scrollTo({ top: 0, behavior: 'smooth' });
    const start = Date.now();
    (function check() {
      if (window.scrollY <= 0 || Date.now() - start > 900) { done(); return; }
      requestAnimationFrame(check);
    })();
  }

  function startHello(text) {
    if (helloRunning || overlayOpen || busy) return;
    helloRunning = true;
    const finish = (inputEl) => typeInto(inputEl, text, () => {
      helloRunning = false;
      triggerFromInput(inputEl);
    });

    // Desktop: el input del header está visible
    const desktop = document.getElementById('oec-ai-header-input');
    if (desktop && desktop.getClientRects().length) {
      desktop.scrollIntoView({ behavior: 'smooth', block: 'center' });
      desktop.focus();
      finish(desktop);
      return;
    }

    // Mobile: subir, desplegar el input mobile y recién ahí tipear
    const bar    = document.getElementById('header-search-mobile');
    const toggle = document.getElementById('search-toggle');
    const mobile = document.getElementById('oec-ai-mobile-input');
    if (!bar || !mobile) { helloRunning = false; return; }
    scrollToTop(() => {
      if (bar.hidden && toggle) toggle.click();
      setTimeout(() => { mobile.focus(); finish(mobile); }, 250);
    });
  }

  /* ── Event wiring ────────────────────────────────────────── */
  function wireEvents() {
    document.addEventListener('keydown', (e) => {
      const id = e.target?.id;
      if ((id === 'oec-ai-header-input' || id === 'oec-ai-mobile-input') && e.key === 'Enter') {
        e.preventDefault();
        triggerFromInput(e.target);
        return;
      }
      if (id === 'oec-overlay-input' && e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendOverlayMessage();
        return;
      }
      if (e.key === 'Escape' && overlayOpen) {
        closeOverlay();
      }
    });

    document.addEventListener('click', (e) => {
      const hero = e.target.closest('#oec-ai-hero-trigger');
      if (hero) {
        startHello(hero.dataset.aiText || 'Hola');
        return;
      }
      if (e.target.closest('#oec-ai-header-send')) {
        const input = document.getElementById('oec-ai-header-input');
        if (input) triggerFromInput(input);
        return;
      }
      if (e.target.closest('#oec-ai-mobile-send')) {
        const input = document.getElementById('oec-ai-mobile-input');
        if (input) triggerFromInput(input);
        return;
      }
      if (e.target.closest('#oec-overlay-close') || e.target.closest('#oec-overlay-logo-close')) { closeOverlay(); return; }
      if (e.target.closest('#oec-overlay-send'))  { sendOverlayMessage(); return; }
      if (e.target.closest('#oec-overlay-new'))   { if (!busy) resetConversation(); return; }
      const card = e.target.closest('.oec-course-card[data-id], .oec-inline-card[data-id]');
      if (card) trackClick(card.dataset.id);
    });

    document.addEventListener('input', (e) => {
      const id = e.target?.id;
      if (id === 'oec-ai-header-input' || id === 'oec-ai-mobile-input') {
        ensureLocation();
        const hasText = !!e.target.value.trim() || hasConversation();
        if (id === 'oec-ai-header-input') {
          const btn = document.getElementById('oec-ai-header-send');
          if (btn) btn.disabled = !hasText;
        } else {
          const btn = document.getElementById('oec-ai-mobile-send');
          if (btn) btn.disabled = !hasText;
        }
        return;
      }
      if (id === 'oec-overlay-input') { ensureLocation(); }
      if (id === 'oec-overlay-input') {
        const el  = e.target;
        const btn = document.getElementById('oec-overlay-send');
        el.style.height = 'auto';
        el.style.height = Math.min(el.scrollHeight, 160) + 'px';
        if (btn) btn.disabled = busy || !el.value.trim();
      }
    });

    // Mobile search toggle → focus mobile AI input
    document.addEventListener('click', (e) => {
      if (e.target.closest('#search-toggle')) {
        const mobileBar = document.getElementById('header-search-mobile');
        if (mobileBar && !mobileBar.hidden) {
          setTimeout(() => {
            const inp = document.getElementById('oec-ai-mobile-input');
            if (inp) inp.focus();
          }, 50);
        }
      }
    });
  }

  /* ── Init ────────────────────────────────────────────────── */
  function init() {
    loadConversation();
    buildOverlay();
    wireEvents();
    updateEntryPoints();
    if (window.visualViewport) {
      window.visualViewport.addEventListener('resize', fitToViewport);
      window.visualViewport.addEventListener('scroll', fitToViewport);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
