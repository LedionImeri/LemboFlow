/**
 * Settings page: preview a chosen logo before it is uploaded.
 */
(function () {
  'use strict';

  const input = document.querySelector('[data-logo-input]');
  const preview = document.querySelector('[data-logo-preview]');
  if (!input || !preview) return;

  const MAX_BYTES = 2 * 1024 * 1024;
  const TYPES = ['image/png', 'image/jpeg', 'image/webp'];
  let objectUrl = null;

  input.addEventListener('change', () => {
    const file = input.files && input.files[0];
    if (!file) return;

    // Quick checks for instant feedback; the server checks the real file contents again.
    if (!TYPES.includes(file.type)) {
      LF.toast('The logo must be a PNG, JPG or WebP image.', 'error');
      input.value = '';
      return;
    }
    if (file.size > MAX_BYTES) {
      LF.toast('The logo must be 2 MB or smaller.', 'error');
      input.value = '';
      return;
    }

    if (objectUrl) URL.revokeObjectURL(objectUrl);
    objectUrl = URL.createObjectURL(file);

    const img = document.createElement('img');
    img.src = objectUrl;
    img.alt = 'New logo preview';
    preview.replaceChildren(img);
  });
})();

// Keep the active tab visible when the tab bar scrolls sideways on phones.
document.querySelector('.tabs__link.is-active')?.scrollIntoView({ block: 'nearest', inline: 'center' });
