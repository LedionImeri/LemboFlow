/**
 * Revenue chart: monthly payments as columns, drawn as plain SVG (no library).
 *
 * Marks follow the dataviz spec: columns at most 24px wide with a 4px rounded top
 * and square base, 1px recessive gridlines, compact y-ticks, one series colour
 * (validated #1A9A91) with the current month in the bronze accent and labelled,
 * so emphasis never relies on colour alone. Hover or keyboard focus on a month
 * shows a tooltip; the same numbers are in the "Show as table" view.
 */
(function () {
  'use strict';

  const root = document.querySelector('[data-revenue-chart]');
  if (!root) return;

  const SVG_NS = 'http://www.w3.org/2000/svg';
  const COLOR = { bar: '#1A9A91', current: '#B5733A', grid: '#E6E3DD', axis: '#808B94', ink: '#15212B' };
  const HEIGHT = 260;
  const PAD = { top: 26, right: 8, bottom: 30, left: 52 };

  const moneyTemplate = root.dataset.moneyTemplate || '€0.00';
  const endpoint = root.dataset.endpoint;
  const tableBody = document.querySelector('[data-revenue-table]');
  const totalEl = document.querySelector('[data-revenue-total]');

  let data = [];
  try { data = JSON.parse(document.getElementById('revenue-data')?.textContent || '[]'); } catch (_) { /* empty */ }

  // ---------------------------------------------------------------------
  // Formatting
  // ---------------------------------------------------------------------

  /** 1234.5 → "€1,234.50" in the account's currency format. */
  function money(amount) {
    const cents = Math.round(Math.abs(amount) * 100);
    const whole = Math.floor(cents / 100).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const fraction = String(cents % 100).padStart(2, '0');
    return moneyTemplate.replace('0.00', `${whole}.${fraction}`);
  }

  /** Axis ticks: 0, 500, 1.5K, 12K, 1.2M (with the currency symbol). */
  function compact(value) {
    let text;
    if (value >= 1e6) text = `${+(value / 1e6).toFixed(1)}M`;
    else if (value >= 1e3) text = `${+(value / 1e3).toFixed(1)}K`;
    else text = String(Math.round(value));
    return moneyTemplate.replace('0.00', text);
  }

  /** A "nice" axis maximum and step: 0 / 2.5K / 5K / 7.5K / 10K. */
  function niceScale(max, ticks = 4) {
    if (max <= 0) return { max: 1000, step: 250 };
    const raw = max / ticks;
    const magnitude = 10 ** Math.floor(Math.log10(raw));
    const step = [1, 2, 2.5, 5, 10].map((m) => m * magnitude).find((s) => s >= raw);
    return { max: step * Math.ceil(max / step), step };
  }

  const el = (name, attrs = {}) => {
    const node = document.createElementNS(SVG_NS, name);
    Object.entries(attrs).forEach(([k, v]) => node.setAttribute(k, v));
    return node;
  };

  /** Column with a 4px rounded top and a square base on the baseline. */
  function columnPath(x, y, w, h) {
    const r = Math.min(4, h, w / 2);
    return `M${x},${y + h} V${y + r} Q${x},${y} ${x + r},${y} H${x + w - r} Q${x + w},${y} ${x + w},${y + r} V${y + h} Z`;
  }

  // ---------------------------------------------------------------------
  // Render
  // ---------------------------------------------------------------------

  const tooltip = document.createElement('div');
  tooltip.className = 'chart__tooltip';
  tooltip.setAttribute('role', 'status');
  tooltip.hidden = true;

  function render() {
    const width = Math.max(280, root.clientWidth);
    const plotW = width - PAD.left - PAD.right;
    const plotH = HEIGHT - PAD.top - PAD.bottom;
    const maxValue = Math.max(0, ...data.map((d) => d.total));
    const scale = niceScale(maxValue);
    const y = (v) => PAD.top + plotH - (v / scale.max) * plotH;
    const band = plotW / data.length;
    const barW = Math.min(24, band * 0.62);

    const svg = el('svg', { viewBox: `0 0 ${width} ${HEIGHT}`, width, height: HEIGHT, class: 'chart__svg', 'aria-hidden': 'true' });

    // Gridlines and y ticks
    for (let v = 0; v <= scale.max + 1e-9; v += scale.step) {
      const yy = Math.round(y(v)) + 0.5;
      svg.appendChild(el('line', { x1: PAD.left, x2: width - PAD.right, y1: yy, y2: yy, stroke: COLOR.grid, 'stroke-width': 1 }));
      const t = el('text', { x: PAD.left - 10, y: yy + 4, 'text-anchor': 'end', class: 'chart__tick' });
      t.textContent = compact(v);
      svg.appendChild(t);
    }

    const bars = [];
    data.forEach((d, i) => {
      const cx = PAD.left + band * (i + 0.5);
      const h = Math.max(0, (d.total / scale.max) * plotH);
      const bar = el('path', {
        d: h > 0 ? columnPath(cx - barW / 2, PAD.top + plotH - h, barW, h) : '',
        fill: d.current ? COLOR.current : COLOR.bar,
        class: 'chart__bar',
      });
      svg.appendChild(bar);
      bars.push(bar);

      // Month labels; the current month is bold (second, non-colour cue).
      // On narrow screens label every other month, counting back from the current one.
      const skipLabel = band < 34 && (data.length - 1 - i) % 2 === 1;
      if (!skipLabel) {
        const label = el('text', { x: cx, y: HEIGHT - 10, 'text-anchor': 'middle', class: `chart__tick${d.current ? ' chart__tick--current' : ''}` });
        label.textContent = d.short;
        svg.appendChild(label);
      }

      // Direct label: only the current month gets its value on the cap.
      if (d.current && d.total > 0) {
        const value = el('text', { x: cx, y: PAD.top + plotH - h - 8, 'text-anchor': 'middle', class: 'chart__value' });
        value.textContent = compact(d.total);
        svg.appendChild(value);
      }
    });

    // Hit targets: the whole column band, larger than the mark, keyboard focusable.
    data.forEach((d, i) => {
      const hit = el('rect', {
        x: PAD.left + band * i, y: PAD.top, width: band, height: plotH,
        fill: 'transparent', class: 'chart__hit', tabindex: 0,
        'aria-label': `${d.label}: ${money(d.total)} from ${d.payments} ${d.payments === 1 ? 'payment' : 'payments'}`,
      });
      hit.removeAttribute('aria-hidden');
      const show = () => showTooltip(d, i, band, y(d.total), bars, width);
      hit.addEventListener('mouseenter', show);
      hit.addEventListener('focus', show);
      hit.addEventListener('mouseleave', () => hideTooltip(bars));
      hit.addEventListener('blur', () => hideTooltip(bars));
      svg.appendChild(hit);
    });
    // The hit layer must be reachable by keyboard and screen readers.
    svg.removeAttribute('aria-hidden');

    root.replaceChildren(svg, tooltip);

    if (maxValue === 0) {
      const empty = document.createElement('p');
      empty.className = 'chart__empty';
      empty.textContent = 'No payments in this period yet. Recorded payments appear here by month.';
      root.appendChild(empty);
    }
  }

  function showTooltip(d, i, band, barTop, bars, width) {
    bars.forEach((b, j) => b.classList.toggle('is-dimmed', j !== i));
    tooltip.innerHTML = `
      <span class="chart__tooltip-label">${LF.escapeHtml(d.label)}</span>
      <strong>${LF.escapeHtml(money(d.total))}</strong>
      <span class="chart__tooltip-meta">${d.payments} ${d.payments === 1 ? 'payment' : 'payments'}</span>`;
    tooltip.hidden = false;

    const cx = PAD.left + band * (i + 0.5);
    const tipW = tooltip.offsetWidth;
    const left = Math.min(Math.max(cx - tipW / 2, 0), width - tipW);
    const top = Math.max(0, Math.min(barTop, PAD.top + (HEIGHT - PAD.top - PAD.bottom)) - tooltip.offsetHeight - 10);
    tooltip.style.transform = `translate(${left}px, ${top}px)`;
  }

  function hideTooltip(bars) {
    tooltip.hidden = true;
    bars.forEach((b) => b.classList.remove('is-dimmed'));
  }

  function renderTable() {
    if (!tableBody) return;
    tableBody.innerHTML = [...data].reverse().map((d) =>
      `<tr><td>${LF.escapeHtml(d.label)}</td><td class="num">${d.payments}</td><td class="num">${LF.escapeHtml(money(d.total))}</td></tr>`
    ).join('');
    if (totalEl) totalEl.textContent = money(data.reduce((sum, d) => sum + d.total, 0));
  }

  // ---------------------------------------------------------------------
  // Period switch (6M / 12M)
  // ---------------------------------------------------------------------

  document.querySelectorAll('[data-range]').forEach((button) => {
    button.addEventListener('click', async () => {
      if (button.classList.contains('is-active')) return;
      document.querySelectorAll('[data-range]').forEach((b) => {
        const on = b === button;
        b.classList.toggle('is-active', on);
        b.setAttribute('aria-pressed', String(on));
      });
      root.classList.add('is-loading');
      try {
        const res = await LF.fetchJSON(`${endpoint}?months=${button.dataset.range}`);
        data = res.months;
        render();
        renderTable();
      } catch (err) {
        LF.toast(err.message || 'Could not load the chart.', 'error');
      } finally {
        root.classList.remove('is-loading');
      }
    });
  });

  // Draw immediately, then redraw on resize (sidebar open/close, rotation).
  // The first draw doesn't wait for ResizeObserver: browsers skip it in background tabs.
  render();
  let lastWidth = root.clientWidth;
  new ResizeObserver(() => {
    if (Math.abs(root.clientWidth - lastWidth) < 2) return;
    lastWidth = root.clientWidth;
    render();
  }).observe(root);
})();
