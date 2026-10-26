/**
 * LemboAI chat drawer.
 *
 * The browser sends questions to api/lemboai.php (with the CSRF header) and never
 * sees the Groq key. The conversation is kept in sessionStorage for this tab only,
 * so it survives page changes but not closing the browser.
 *
 * Replies are rendered safely: the text is escaped first, then only **bold**,
 * "- " lists and line breaks are turned into HTML.
 */
(function () {
  'use strict';

  const drawer = document.querySelector('[data-lemboai]');
  if (!drawer) return;

  const enabled = drawer.dataset.enabled === '1';
  const endpoint = drawer.dataset.endpoint;
  const storageKey = `lemboai:${drawer.dataset.user || '0'}`;
  const messagesEl = drawer.querySelector('[data-lemboai-messages]');
  const intro = drawer.querySelector('[data-lemboai-intro]');
  const form = drawer.querySelector('[data-lemboai-form]');
  const input = form.querySelector('textarea');
  const sendBtn = form.querySelector('[type="submit"]');
  const clearBtn = drawer.querySelector('[data-lemboai-clear]');
  const MAX_HISTORY = 8;

  form.setAttribute('data-no-loading', '');

  let history = load();
  let busy = false;

  // ---------------------------------------------------------------------
  // Storage (wrapped: storage can be unavailable in private mode)
  // ---------------------------------------------------------------------

  function load() {
    try { return JSON.parse(sessionStorage.getItem(storageKey) || '[]'); } catch (_) { return []; }
  }
  function save() {
    try { sessionStorage.setItem(storageKey, JSON.stringify(history.slice(-20))); } catch (_) { /* ignore */ }
  }

  // ---------------------------------------------------------------------
  // Rendering
  // ---------------------------------------------------------------------

  /** Escape, then allow **bold**, "- " / "• " lists and paragraphs. Nothing else. */
  function formatReply(text) {
    const lines = LF.escapeHtml(text).split(/\r?\n/);
    let html = '';
    let inList = false;
    for (const raw of lines) {
      const line = raw.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
      const item = line.match(/^\s*(?:[-•*]|\d+\.)\s+(.*)$/);
      if (item) {
        if (!inList) { html += '<ul>'; inList = true; }
        html += `<li>${item[1]}</li>`;
        continue;
      }
      if (inList) { html += '</ul>'; inList = false; }
      if (line.trim() !== '') html += `<p>${line}</p>`;
    }
    if (inList) html += '</ul>';
    return html;
  }

  function bubble(role, text, { error = false } = {}) {
    const el = document.createElement('div');
    el.className = `chat__msg chat__msg--${role}${error ? ' chat__msg--error' : ''}`;
    if (role === 'assistant' && !error) {
      el.innerHTML = formatReply(text);
    } else {
      el.textContent = text;
    }
    messagesEl.appendChild(el);
    return el;
  }

  function typing() {
    const el = document.createElement('div');
    el.className = 'chat__msg chat__msg--assistant chat__typing';
    el.setAttribute('aria-label', 'LemboAI is thinking');
    el.innerHTML = '<span></span><span></span><span></span>';
    messagesEl.appendChild(el);
    return el;
  }

  function scrollToEnd() {
    messagesEl.scrollTop = messagesEl.scrollHeight;
  }

  function renderAll() {
    messagesEl.querySelectorAll('.chat__msg').forEach((m) => m.remove());
    intro.hidden = history.length > 0;
    clearBtn.hidden = history.length === 0;
    history.forEach((m) => bubble(m.role, m.content));
    scrollToEnd();
  }

  // ---------------------------------------------------------------------
  // Asking
  // ---------------------------------------------------------------------

  async function ask(question) {
    question = question.trim();
    if (!enabled || busy || question === '') return;

    busy = true;
    sendBtn.disabled = true;
    intro.hidden = true;
    clearBtn.hidden = false;

    bubble('user', question);
    const previous = history.slice(-MAX_HISTORY);
    history.push({ role: 'user', content: question });
    save();

    input.value = '';
    autosize();
    const dots = typing();
    scrollToEnd();

    try {
      const data = await LF.fetchJSON(endpoint, { method: 'POST', body: { message: question, history: previous } });
      dots.remove();
      bubble('assistant', data.reply);
      history.push({ role: 'assistant', content: data.reply });
      save();
    } catch (err) {
      dots.remove();
      bubble('assistant', err.message || 'Something went wrong. Please try again.', { error: true });
      // Drop the unanswered question so a retry doesn't send it twice.
      history.pop();
      save();
    } finally {
      busy = false;
      sendBtn.disabled = false;
      scrollToEnd();
      input.focus();
    }
  }

  form.addEventListener('submit', (e) => {
    e.preventDefault();
    ask(input.value);
  });

  // Enter sends, Shift+Enter adds a new line.
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
      e.preventDefault();
      ask(input.value);
    }
  });

  function autosize() {
    input.style.height = 'auto';
    input.style.height = `${Math.min(input.scrollHeight, 140)}px`;
  }
  input.addEventListener('input', autosize);

  drawer.querySelectorAll('[data-lemboai-ask]').forEach((chip) => {
    chip.addEventListener('click', () => ask(chip.textContent));
  });

  clearBtn.addEventListener('click', () => {
    history = [];
    save();
    renderAll();
    input.focus();
  });

  drawer.addEventListener('drawer:open', scrollToEnd);

  renderAll();
})();
