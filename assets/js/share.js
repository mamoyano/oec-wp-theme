/* OEC Theme — share.js
   Barra de compartir: abre cada red en un popup (no navega afuera del
   artículo), copia el link con feedback visual, y en mobile suma "Más"
   con el share nativo del sistema (navigator.share). Cada click empuja
   un evento "share" a dataLayer para que GTM lo mande a GA4. */
(function () {
  'use strict';

  function trackShare(bar, method) {
    if (!window.dataLayer) return;
    window.dataLayer.push({
      event: 'share',
      method: method,
      content_type: bar.dataset.contentType || '',
      item_id: bar.dataset.itemId || '',
      item_name: bar.dataset.shareTitle || '',
    });
  }

  function openPopup(shareUrl) {
    const w = 600;
    const h = 500;
    const left = Math.max(0, (window.screen.width - w) / 2);
    const top  = Math.max(0, (window.screen.height - h) / 2);
    window.open(
      shareUrl,
      'oec-share',
      `width=${w},height=${h},left=${left},top=${top},noopener,noreferrer`
    );
  }

  function showCopiedTooltip(btn) {
    const existing = btn.querySelector('.share-bar__tooltip');
    if (existing) existing.remove();

    const tip = document.createElement('span');
    tip.className = 'share-bar__tooltip';
    tip.setAttribute('role', 'status');
    tip.textContent = 'Enlace copiado';
    btn.appendChild(tip);
    setTimeout(() => tip.remove(), 1800);
  }

  function copyLink(btn, url) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(url).then(() => showCopiedTooltip(btn)).catch(() => {});
      return;
    }
    // Fallback para navegadores sin Clipboard API.
    const input = document.createElement('input');
    input.value = url;
    input.style.position = 'fixed';
    input.style.opacity = '0';
    document.body.appendChild(input);
    input.select();
    try { document.execCommand('copy'); showCopiedTooltip(btn); } catch (_e) {}
    document.body.removeChild(input);
  }

  document.querySelectorAll('.share-bar').forEach((bar) => {
    const url   = bar.dataset.shareUrl || window.location.href;
    const title = bar.dataset.shareTitle || document.title;

    const moreBtn = bar.querySelector('[data-share="more"]');
    if (moreBtn && navigator.share) {
      moreBtn.hidden = false;
    }

    bar.querySelectorAll('[data-share]').forEach((btn) => {
      btn.addEventListener('click', (e) => {
        const method = btn.dataset.share;
        e.preventDefault();

        switch (method) {
          case 'whatsapp':
            trackShare(bar, 'whatsapp');
            openPopup('https://wa.me/?text=' + encodeURIComponent(title + ' ' + url));
            break;
          case 'linkedin':
            trackShare(bar, 'linkedin');
            openPopup('https://www.linkedin.com/sharing/share-offsite/?url=' + encodeURIComponent(url));
            break;
          case 'x':
            trackShare(bar, 'x');
            openPopup('https://twitter.com/intent/tweet?url=' + encodeURIComponent(url) + '&text=' + encodeURIComponent(title));
            break;
          case 'copy':
            trackShare(bar, 'copy_link');
            copyLink(btn, url);
            break;
          case 'more':
            trackShare(bar, 'more');
            if (navigator.share) {
              navigator.share({ title: title, url: url }).catch(() => {});
            }
            break;
        }
      });
    });
  });
})();
