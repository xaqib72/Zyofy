/**
 * Pickwell — Global Interactive Scripts
 * Mobile navigation, accessible clipboard copy, micro-interactions.
 * Performance: IIFE, zero dependencies, runs after DOMContentLoaded via defer.
 */

(() => {
  'use strict';

  const body = document.body;

  // ── Mobile Navigation Drawer ──────────────────────────────────────────────
  const toggle = document.querySelector('[data-menu-toggle]');
  const menu = document.querySelector('[data-mobile-menu]');
  if (toggle && menu) {
    const openMenu = () => {
      menu.classList.add('is-open');
      toggle.classList.add('is-open');
      toggle.setAttribute('aria-expanded', 'true');
      body.style.overflow = 'hidden';
    };
    const closeMenu = () => {
      menu.classList.remove('is-open');
      toggle.classList.remove('is-open');
      toggle.setAttribute('aria-expanded', 'false');
      body.style.overflow = '';
    };
    toggle.addEventListener('click', () => {
      menu.classList.contains('is-open') ? closeMenu() : openMenu();
    });

    // Close on outside click
    document.addEventListener('click', (event) => {
      if (!toggle.contains(event.target) && !menu.contains(event.target) && menu.classList.contains('is-open')) {
        closeMenu();
      }
    });

    // Close on Escape key
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && menu.classList.contains('is-open')) {
        closeMenu();
        toggle.focus();
      }
    });

    // Close on in-menu navigation
    menu.querySelectorAll('a').forEach(link => {
      link.addEventListener('click', closeMenu);
    });
  }

  // ── Accessible Clipboard Copy ──────────────────────────────────────────────
  document.querySelectorAll('[data-copy]').forEach(button => {
    button.addEventListener('click', async () => {
      const selector = button.getAttribute('data-copy');
      const target = document.querySelector(selector);
      if (!target) return;

      const value = (target.value || target.textContent || '').trim();
      let copied = false;
      try {
        copied = await navigator.clipboard.writeText(value);
      } catch (_) {
        if (target.select) {
          target.select();
          copied = document.execCommand('copy');
        }
      }
      if (copied) {
        const originalHTML = button.innerHTML;
        const originalClass = button.className;
        button.innerHTML = 'Copied!';
        button.classList.add('is-copied');
        setTimeout(() => {
          button.innerHTML = originalHTML;
          button.className = originalClass;
        }, 1800);
      }
    });
  });
})();
