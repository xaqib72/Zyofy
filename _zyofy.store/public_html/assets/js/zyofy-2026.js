/**
 * Zyofy — storefront presentation enhancements (2026 design layer).
 *
 * Strictly visual: it never submits, intercepts or rewrites application data.
 * Everything degrades gracefully if this file fails to load.
 */
(() => {
  'use strict';

  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* 1. Scroll reveal for opted-in blocks. */
  const revealTargets = document.querySelectorAll('[data-zf-reveal]');
  if (revealTargets.length) {
    if (reduceMotion || !('IntersectionObserver' in window)) {
      revealTargets.forEach((el) => el.classList.add('zf-reveal', 'is-visible'));
    } else {
      revealTargets.forEach((el) => el.classList.add('zf-reveal'));
      const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
          if (!entry.isIntersecting) return;
          const el = entry.target;
          const delay = Number(el.getAttribute('data-zf-reveal')) || 0;
          window.setTimeout(() => el.classList.add('is-visible'), delay);
          observer.unobserve(el);
        });
      }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });
      revealTargets.forEach((el) => observer.observe(el));
    }
  }

  /* 2. Header state on scroll — hairline + shadow only. */
  const header = document.querySelector('.zf-header');
  if (header) {
    let ticking = false;
    const sync = () => {
      const scrolled = window.scrollY > 6;
      header.classList.toggle('is-stuck', scrolled);
      ticking = false;
    };
    window.addEventListener('scroll', () => {
      if (ticking) return;
      ticking = true;
      window.requestAnimationFrame(sync);
    }, { passive: true });
    sync();
  }

  /* 3. Reading progress on guide articles (purely decorative). */
  const article = document.querySelector('.guide-article-body');
  if (article && !reduceMotion) {
    const bar = document.createElement('div');
    bar.className = 'zf-progress';
    bar.setAttribute('aria-hidden', 'true');
    document.body.appendChild(bar);
    let frame = false;
    const paint = () => {
      const rect = article.getBoundingClientRect();
      const total = rect.height - window.innerHeight * 0.4;
      const scrolled = Math.min(Math.max(-rect.top / Math.max(total, 1), 0), 1);
      bar.style.transform = `scaleX(${scrolled})`;
      frame = false;
    };
    window.addEventListener('scroll', () => {
      if (frame) return;
      frame = true;
      window.requestAnimationFrame(paint);
    }, { passive: true });
    paint();
  }
})();
