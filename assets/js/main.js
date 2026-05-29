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

  if (menuToggle && mainNav) {
    menuToggle.addEventListener('click', () => {
      const open = mainNav.classList.toggle('is-open');
      menuToggle.setAttribute('aria-expanded', String(open));
    });

    document.addEventListener('click', (e) => {
      if (!mainNav.contains(e.target) && !menuToggle.contains(e.target)) {
        mainNav.classList.remove('is-open');
        menuToggle.setAttribute('aria-expanded', 'false');
      }
    });

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && mainNav.classList.contains('is-open')) {
        mainNav.classList.remove('is-open');
        menuToggle.setAttribute('aria-expanded', 'false');
        menuToggle.focus();
      }
    });
  }

  /* ── Mobile: toggle buscador ────────────────────────────── */
  const searchToggle = document.getElementById('search-toggle');
  const searchMobile = document.getElementById('header-search-mobile');

  if (searchToggle && searchMobile) {
    searchToggle.addEventListener('click', () => {
      const open = searchMobile.hasAttribute('hidden') === false
        ? true  // ya está abierto → cerrar
        : false; // cerrado → abrir

      if (open) {
        searchMobile.hidden = true;
        searchToggle.setAttribute('aria-expanded', 'false');
      } else {
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

    // Desktop: hover
    item.addEventListener('mouseenter', () => openMega(item, trigger));
    item.addEventListener('mouseleave', () => closeMega(item, trigger));

    // Keyboard / touch
    trigger.addEventListener('click', () => {
      const isOpen = item.classList.contains('is-open');
      // Cerrar todos los otros
      document.querySelectorAll('.nav-item--has-sub.is-open').forEach((el) => {
        if (el !== item) closeMega(el, el.querySelector('.nav-trigger'));
      });
      isOpen ? closeMega(item, trigger) : openMega(item, trigger);
    });

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
