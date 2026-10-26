/**
 * LemboFlow — shared UI behaviour, loaded on every app page.
 *
 * Exposes window.LF:
 *   LF.toast(message, type)        type: success | error | warning | info
 *   LF.confirm(message, options)   → Promise<boolean>
 *   LF.fetchJSON(url, options)     fetch with CSRF header + JSON parsing
 *   LF.debounce(fn, ms)
 *   LF.url(path)
 *
 * Markup hooks:
 *   [data-sidebar-open] / [data-sidebar-close]
 *   [data-drawer-open="id"] / [data-drawer-close]
 *   [data-modal-open="id"] / [data-modal-close]   (on native <dialog>)
 *   <dialog data-open-on-load> opens as soon as the page loads
 *   <form data-confirm="Delete this client?" data-confirm-label="Delete">
 *   <form data-no-loading> opts out of the double-submit guard
 */
(function () {
  'use strict';

  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  const meta = (name) => $(`meta[name="${name}"]`)?.getAttribute('content') || '';
  const appUrl = meta('app-url').replace(/\/$/, '');
  const iconUrl = `${appUrl}/assets/images/icons.svg`;

  const icon = (name, cls = '') =>
    `<svg class="icon ${cls}" aria-hidden="true" focusable="false"><use href="${iconUrl}#i-${name}"></use></svg>`;

  const escapeHtml = (s) =>
    String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  const debounce = (fn, ms = 250) => {
    let t;
    return (...args) => {
      clearTimeout(t);
      t = setTimeout(() => fn(...args), ms);
    };
  };

  // -------------------------------------------------------------------------
  // Focus helpers for overlays
  // -------------------------------------------------------------------------
  const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

  function trapFocus(container, event) {
    if (event.key !== 'Tab') return;
    const items = $$(FOCUSABLE, container).filter((el) => el.offsetParent !== null);
    if (!items.length) return;
    const first = items[0];
    const last = items[items.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  }

  // -------------------------------------------------------------------------
  // Sidebar (off-canvas below 1024px)
  // -------------------------------------------------------------------------
  const app = $('[data-app]');
  const sidebar = $('#sidebar');
  const sidebarToggle = $('[data-sidebar-open]');
  const mobileNav = window.matchMedia('(max-width: 1023.98px)');

  function setSidebar(open) {
    if (!app) return;
    app.classList.toggle('is-nav-open', open);
    sidebarToggle?.setAttribute('aria-expanded', String(open));
    document.body.style.overflow = open ? 'hidden' : '';
    if (open) {
      $(FOCUSABLE, sidebar)?.focus();
    } else if (sidebar?.contains(document.activeElement)) {
      sidebarToggle?.focus();
    }
  }

  $$('[data-sidebar-open]').forEach((el) => el.addEventListener('click', () => setSidebar(true)));
  $$('[data-sidebar-close]').forEach((el) => el.addEventListener('click', () => setSidebar(false)));
  sidebar?.addEventListener('keydown', (e) => {
    if (app?.classList.contains('is-nav-open')) trapFocus(sidebar, e);
  });
  mobileNav.addEventListener('change', (e) => { if (!e.matches) setSidebar(false); });

  // -------------------------------------------------------------------------
  // Drawers (LemboAI)
  // -------------------------------------------------------------------------
  let openDrawer = null;
  let drawerReturnFocus = null;

  function setDrawer(drawer, open) {
    if (!drawer) return;
    drawer.classList.toggle('is-open', open);
    drawer.setAttribute('aria-hidden', String(!open));
    $$(`[data-drawer-open="${drawer.id}"]`).forEach((b) => b.setAttribute('aria-expanded', String(open)));
    document.body.classList.toggle('drawer-open', open);
    document.body.style.overflow = open ? 'hidden' : '';

    if (open) {
      openDrawer = drawer;
      drawerReturnFocus = document.activeElement;
      setTimeout(() => {
        ($('input:not([disabled]), textarea:not([disabled])', drawer) || $(FOCUSABLE, drawer))?.focus();
      }, 50);
      drawer.dispatchEvent(new CustomEvent('drawer:open'));
    } else {
      openDrawer = null;
      drawerReturnFocus?.focus?.();
    }
  }

  $$('[data-drawer-open]').forEach((btn) =>
    btn.addEventListener('click', () => setDrawer(document.getElementById(btn.dataset.drawerOpen), true))
  );
  $$('[data-drawer-close]').forEach((el) => el.addEventListener('click', () => setDrawer(openDrawer, false)));
  $$('[data-drawer]').forEach((d) => d.addEventListener('keydown', (e) => trapFocus(d, e)));

  // -------------------------------------------------------------------------
  // Modals (native <dialog>)
  // -------------------------------------------------------------------------
  function openModal(dialog) {
    if (!dialog || dialog.open) return;
    dialog.showModal();
    dialog.dispatchEvent(new CustomEvent('modal:open'));
  }

  document.addEventListener('click', (e) => {
    const opener = e.target.closest('[data-modal-open]');
    if (opener) {
      e.preventDefault();
      openModal(document.getElementById(opener.dataset.modalOpen));
      return;
    }
    const closer = e.target.closest('[data-modal-close]');
    if (closer) {
      closer.closest('dialog')?.close('cancel');
    }
  });

  // A dialog the server wants open (e.g. a form that came back with errors).
  $$('dialog[data-open-on-load]').forEach((dialog) => openModal(dialog));

  // Click on the backdrop closes the dialog.
  $$('dialog.modal').forEach((dialog) => {
    dialog.addEventListener('click', (e) => {
      if (e.target !== dialog) return;
      const r = dialog.getBoundingClientRect();
      const inside = e.clientX >= r.left && e.clientX <= r.right && e.clientY >= r.top && e.clientY <= r.bottom;
      if (!inside) dialog.close('cancel');
    });
  });

  // -------------------------------------------------------------------------
  // Confirm dialog
  // -------------------------------------------------------------------------
  const confirmModal = $('#confirm-modal');

  function confirmDialog(message, { title = 'Are you sure?', confirmLabel = 'Confirm', danger = true } = {}) {
    if (!confirmModal) return Promise.resolve(window.confirm(message));

    $('#confirm-title', confirmModal).textContent = title;
    $('[data-confirm-message]', confirmModal).textContent = message;
    const accept = $('[data-confirm-accept]', confirmModal);
    accept.textContent = confirmLabel;
    accept.className = `btn ${danger ? 'btn--danger' : 'btn--primary'}`;

    return new Promise((resolve) => {
      const onAccept = () => confirmModal.close('accept');
      const onClose = () => {
        accept.removeEventListener('click', onAccept);
        resolve(confirmModal.returnValue === 'accept');
      };
      confirmModal.returnValue = '';
      accept.addEventListener('click', onAccept);
      confirmModal.addEventListener('close', onClose, { once: true });
      openModal(confirmModal);
      accept.focus();
    });
  }

  // -------------------------------------------------------------------------
  // Forms: confirm before submit, and block double submits
  // -------------------------------------------------------------------------
  document.addEventListener('submit', async (e) => {
    const form = e.target;
    if (!(form instanceof HTMLFormElement)) return;

    if (form.dataset.confirm && form.dataset.confirmed !== '1') {
      e.preventDefault();
      const ok = await confirmDialog(form.dataset.confirm, {
        title: form.dataset.confirmTitle || 'Are you sure?',
        confirmLabel: form.dataset.confirmLabel || 'Confirm',
        danger: form.dataset.confirmVariant !== 'primary',
      });
      if (ok) {
        form.dataset.confirmed = '1';
        form.requestSubmit(e.submitter || undefined);
      }
      return;
    }

    if (e.defaultPrevented || form.hasAttribute('data-no-loading')) return;

    if (form.dataset.submitting === '1') {
      e.preventDefault();
      return;
    }
    form.dataset.submitting = '1';
    const button = e.submitter || $('[type="submit"]', form);
    button?.classList.add('is-loading');
    button?.setAttribute('aria-busy', 'true');
  });

  // Re-enable forms when the page is restored from the back/forward cache.
  window.addEventListener('pageshow', (e) => {
    if (!e.persisted) return;
    $$('form[data-submitting]').forEach((f) => { delete f.dataset.submitting; delete f.dataset.confirmed; });
    $$('.is-loading').forEach((b) => { b.classList.remove('is-loading'); b.removeAttribute('aria-busy'); });
  });

  // -------------------------------------------------------------------------
  // Show / hide password (<button data-password-toggle> next to the input)
  // -------------------------------------------------------------------------
  $$('[data-password-toggle]').forEach((btn) => {
    const input = btn.parentElement.querySelector('input');
    btn.addEventListener('click', () => {
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.setAttribute('aria-pressed', String(show));
      btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
      input.focus();
    });
  });

  // -------------------------------------------------------------------------
  // Dropdowns (<details data-dropdown>)
  // -------------------------------------------------------------------------
  document.addEventListener('click', (e) => {
    $$('details[data-dropdown][open]').forEach((d) => {
      if (!d.contains(e.target)) d.removeAttribute('open');
    });
  });

  // -------------------------------------------------------------------------
  // Global Escape key
  // -------------------------------------------------------------------------
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    if ($('dialog[open]')) return; // native dialogs handle Escape themselves
    const dropdown = $('details[data-dropdown][open]');
    if (dropdown) {
      dropdown.removeAttribute('open');
      $('summary', dropdown)?.focus();
    } else if (openDrawer) {
      setDrawer(openDrawer, false);
    } else if (app?.classList.contains('is-nav-open')) {
      setSidebar(false);
    }
  });

  // -------------------------------------------------------------------------
  // Toasts
  // -------------------------------------------------------------------------
  const toastRegion = $('[data-toasts]');
  const TOAST_ICONS = { success: 'check-circle', error: 'alert', warning: 'alert', info: 'info' };

  function toast(message, type = 'info', { timeout = 5000 } = {}) {
    if (!toastRegion) return;
    if (type === 'danger') type = 'error';
    if (!TOAST_ICONS[type]) type = 'info';

    const el = document.createElement('div');
    el.className = `toast toast--${type}`;
    el.setAttribute('role', type === 'error' ? 'alert' : 'status');
    el.innerHTML = `
      <span class="toast__icon">${icon(TOAST_ICONS[type])}</span>
      <p class="toast__message">${escapeHtml(message)}</p>
      <button class="toast__close" type="button" aria-label="Dismiss">${icon('x')}</button>`;

    const remove = () => {
      if (el.classList.contains('is-leaving')) return;
      el.classList.add('is-leaving');
      el.addEventListener('animationend', () => el.remove(), { once: true });
      setTimeout(() => el.remove(), 400);
    };

    $('.toast__close', el).addEventListener('click', remove);

    let timer = timeout ? setTimeout(remove, timeout) : null;
    el.addEventListener('mouseenter', () => clearTimeout(timer));
    el.addEventListener('mouseleave', () => { if (timeout) timer = setTimeout(remove, 2500); });

    toastRegion.appendChild(el);
    while (toastRegion.children.length > 4) toastRegion.firstElementChild.remove();
  }

  // Flash messages from PHP
  try {
    const flashes = JSON.parse($('#flash-data')?.textContent || '[]');
    flashes.forEach((f) => toast(f.message, f.type));
  } catch (_) { /* no flashes */ }

  // -------------------------------------------------------------------------
  // fetch() wrapper
  // -------------------------------------------------------------------------
  async function fetchJSON(url, { method = 'GET', body = null, signal } = {}) {
    const headers = {
      Accept: 'application/json',
      'X-Requested-With': 'fetch',
    };
    const init = { method, headers, signal, credentials: 'same-origin' };

    if (method !== 'GET') {
      headers['X-CSRF-Token'] = meta('csrf-token');
      if (body instanceof FormData) {
        init.body = body;
      } else if (body !== null) {
        headers['Content-Type'] = 'application/json';
        init.body = JSON.stringify(body);
      }
    }

    const res = await fetch(url, init);
    let data = null;
    try { data = await res.json(); } catch (_) { /* non-JSON response */ }

    if (res.status === 401) {
      window.location.href = `${appUrl}/auth/login.php`;
      throw new Error('Please log in again.');
    }
    if (!res.ok || (data && data.ok === false)) {
      throw new Error(data?.error || `Request failed (${res.status}).`);
    }
    return data;
  }

  window.LF = {
    toast,
    confirm: confirmDialog,
    fetchJSON,
    debounce,
    escapeHtml,
    icon,
    url: (path = '') => `${appUrl}/${String(path).replace(/^\//, '')}`,
  };
})();
