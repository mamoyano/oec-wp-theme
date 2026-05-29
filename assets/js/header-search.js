/* OEC Theme — header-search.js
   Autocomplete del buscador del header.
   Llama a /wp-json/oec/v1/search?q=... (proxy interno, no expone el token).
*/
(function () {
  'use strict';

  const cfg       = window.oecSearch || {};
  const endpoint  = cfg.endpoint || '/wp-json/oec/v1/search';
  const searchUrl = cfg.searchUrl || '/?s=';

  const input    = document.getElementById('oec-search-input');
  const dropdown = document.getElementById('oec-search-dropdown');
  const form     = document.getElementById('oec-search-form');

  if ( !input || !dropdown ) return;

  let timer    = null;
  let lastQ    = '';
  let active   = -1; // keyboard nav index

  /* ── Eventos ────────────────────────────────────────────── */
  input.addEventListener('input', () => {
    const q = input.value.trim();
    clearTimeout(timer);
    if ( q.length < 2 ) { close(); return; }
    if ( q === lastQ )   return;
    timer = setTimeout(() => search(q), 350);
  });

  input.addEventListener('keydown', e => {
    const items = dropdown.querySelectorAll('.oec-dd-item');
    if ( e.key === 'ArrowDown' ) {
      e.preventDefault();
      active = Math.min(active + 1, items.length - 1);
      highlight(items);
    } else if ( e.key === 'ArrowUp' ) {
      e.preventDefault();
      active = Math.max(active - 1, -1);
      highlight(items);
    } else if ( e.key === 'Enter' ) {
      if ( active >= 0 && items[active] ) {
        e.preventDefault();
        items[active].click();
      }
      // else let the form submit normally → search.php
    } else if ( e.key === 'Escape' ) {
      close();
      input.blur();
    }
  });

  document.addEventListener('click', e => {
    if ( !form || !form.contains(e.target) ) close();
  });

  /* ── Buscar ─────────────────────────────────────────────── */
  async function search(q) {
    lastQ  = q;
    active = -1;

    showLoading();

    try {
      const res  = await fetch(`${endpoint}?q=${encodeURIComponent(q)}`);
      const data = await res.json();
      render(data.data || [], q);
    } catch {
      close();
    }
  }

  /* ── Render dropdown ────────────────────────────────────── */
  function render(items, q) {
    if ( !items.length ) { close(); return; }

    const top = items.slice(0, 6);

    dropdown.innerHTML = top.map((item, i) => {
      const img    = item.image
        ? `<img src="${esc(item.image)}" alt="" class="oec-dd-thumb" loading="lazy">`
        : `<div class="oec-dd-thumb oec-dd-thumb--empty"></div>`;
      const type   = item.type   ? `<span class="oec-dd-type">${esc(item.type)}</span>` : '';
      const org    = item.organization?.shortName || '';
      const title  = highlight_text(esc(item.title), q);
      const url    = item.url || `${searchUrl}${encodeURIComponent(item.title)}`;

      return `<a href="${esc(url)}" class="oec-dd-item" data-index="${i}" tabindex="-1">
        ${img}
        <div class="oec-dd-info">
          <span class="oec-dd-title">${title}</span>
          ${org ? `<span class="oec-dd-org">${esc(org)}</span>` : ''}
        </div>
        ${type}
      </a>`;
    }).join('');

    // "Ver todos los resultados" footer
    dropdown.innerHTML += `<a href="${searchUrl}${encodeURIComponent(q)}" class="oec-dd-footer">
      Ver todos los resultados para <strong>${esc(q)}</strong>
    </a>`;

    open();
  }

  /* ── Helpers ────────────────────────────────────────────── */
  function showLoading() {
    dropdown.innerHTML = '<div class="oec-dd-loading"><span></span><span></span><span></span></div>';
    open();
  }

  function open()  {
    dropdown.classList.add('oec-dd--open');
    input.setAttribute('aria-expanded', 'true');
  }

  function close() {
    dropdown.classList.remove('oec-dd--open');
    input.setAttribute('aria-expanded', 'false');
    active = -1;
  }

  function highlight(items) {
    items.forEach((el, i) => el.classList.toggle('oec-dd-item--active', i === active));
    if ( active >= 0 ) items[active].scrollIntoView({ block: 'nearest' });
  }

  function esc(str) {
    const d = document.createElement('div');
    d.textContent = String(str || '');
    return d.innerHTML;
  }

  // Resalta el término buscado en el título
  function highlight_text(html, q) {
    if ( !q ) return html;
    const re = new RegExp(`(${q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'gi');
    return html.replace(re, '<mark>$1</mark>');
  }

})();
