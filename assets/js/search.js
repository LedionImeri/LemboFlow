/**
 * Live search for list pages.
 *
 *   <form data-live-search data-endpoint="api/clients.php" data-target="#results"> … </form>
 *
 * Typing or changing a filter fetches { html } from the endpoint and swaps it into
 * the target. Pagination links inside the target load the same way. The address
 * bar is kept in sync, so refresh and back keep the current search. Without
 * JavaScript the form is a normal GET form.
 */
(function () {
  'use strict';

  document.querySelectorAll('form[data-live-search]').forEach((form) => {
    const endpoint = form.dataset.endpoint;
    const target = document.querySelector(form.dataset.target);
    if (!endpoint || !target) return;

    const pageUrl = form.getAttribute('action') || window.location.pathname;
    let controller = null;
    let lastQuery = null;

    const buildQuery = (page) => {
      const params = new URLSearchParams();
      new FormData(form).forEach((value, key) => {
        if (String(value).trim() !== '') params.set(key, String(value).trim());
      });
      if (page && page > 1) params.set('page', String(page));
      return params.toString();
    };

    async function load(query) {
      if (query === lastQuery) return;
      lastQuery = query;

      controller?.abort();
      controller = new AbortController();

      // Dim the old results only if the request is noticeably slow.
      const dimTimer = setTimeout(() => target.classList.add('is-loading'), 150);
      target.setAttribute('aria-busy', 'true');

      try {
        const data = await LF.fetchJSON(`${endpoint}${query ? '?' + query : ''}`, { signal: controller.signal });
        target.innerHTML = data.html;
        history.replaceState(null, '', `${pageUrl}${query ? '?' + query : ''}`);
      } catch (err) {
        if (err.name === 'AbortError') return;
        lastQuery = null;
        LF.toast(err.message || 'Search failed. Please try again.', 'error');
      } finally {
        clearTimeout(dimTimer);
        target.classList.remove('is-loading');
        target.removeAttribute('aria-busy');
      }
    }

    const search = LF.debounce(() => load(buildQuery(1)), 250);

    form.addEventListener('input', (e) => {
      if (e.target.matches('input[type="search"], input[type="text"]')) search();
    });
    form.addEventListener('change', (e) => {
      if (e.target.matches('select, input[type="checkbox"], input[type="radio"], input[type="date"]')) load(buildQuery(1));
    });
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      load(buildQuery(1));
    });

    // Pagination inside the results.
    target.addEventListener('click', (e) => {
      const link = e.target.closest('.pagination a[href]');
      if (!link) return;
      e.preventDefault();
      const page = new URL(link.href).searchParams.get('page') || '1';
      load(buildQuery(Number(page))).then(() => {
        form.closest('.card')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    });

    // Escape clears the search box.
    form.querySelector('input[type="search"]')?.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && e.target.value !== '') {
        e.target.value = '';
        load(buildQuery(1));
      }
    });
  });
})();
