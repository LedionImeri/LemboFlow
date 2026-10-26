/**
 * Invoice form: line items and live totals.
 *
 * The maths mirrors calculate_invoice_totals() in src/invoices.php step by step:
 * amounts become integer hundredths (BigInt), lines are rounded half-up, the
 * discount is applied after tax. The result is only a preview; the server
 * recalculates everything on save.
 */
(function () {
  'use strict';

  const form = document.querySelector('[data-invoice-form]');
  if (!form) return;

  const linesEl = form.querySelector('[data-lines]');
  const template = document.getElementById('line-template');
  const picker = form.querySelector('[data-product-picker]');
  const discountType = form.querySelector('[data-discount-type]');
  const discountValue = form.querySelector('[data-discount-value]');
  const issueDate = form.querySelector('#issue_date');
  const dueDate = form.querySelector('#due_date');

  const moneyTemplate = form.dataset.moneyTemplate || '€0.00';
  const paymentTerms = Number(form.dataset.paymentTerms || 0);
  let products = [];
  try { products = JSON.parse(document.getElementById('products-data')?.textContent || '[]'); } catch (_) { /* none */ }

  // ---------------------------------------------------------------------
  // Exact decimal maths (same rules as PHP)
  // ---------------------------------------------------------------------

  /** Same as normalize_decimal() in PHP: "1.234,50" → "1234.50". */
  function normalize(value) {
    let v = String(value ?? '').replace(/[\s ]/g, '');
    if (v.includes(',') && v.includes('.')) {
      v = v.lastIndexOf(',') > v.lastIndexOf('.') ? v.replace(/\./g, '').replace(',', '.') : v.replace(/,/g, '');
    } else if (v.includes(',')) {
      v = v.replace(',', '.');
    }
    return v;
  }

  /** "12.5" → 1250n, or null when it isn't a valid non-negative 2-decimal number. */
  function toHundredths(value, emptyAs = null) {
    const v = normalize(value);
    if (v === '') return emptyAs;
    if (!/^\d+(\.\d{1,2})?$/.test(v)) return null;
    const [whole, fraction = ''] = v.split('.');
    return BigInt(whole) * 100n + BigInt((fraction + '00').slice(0, 2));
  }

  /** Half-up division for non-negative BigInts. */
  const divRound = (a, b) => (2n * a + b) / (2n * b);

  /** 123456n → "€1,234.56" using the server's currency format. */
  function formatMoney(cents) {
    const negative = cents < 0n;
    const abs = negative ? -cents : cents;
    const whole = (abs / 100n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const fraction = (abs % 100n).toString().padStart(2, '0');
    return (negative ? '−' : '') + moneyTemplate.replace('0.00', `${whole}.${fraction}`);
  }

  // ---------------------------------------------------------------------
  // Totals
  // ---------------------------------------------------------------------

  const out = (name) => form.querySelectorAll(`[data-out="${name}"]`);
  const setOut = (name, text) => out(name).forEach((el) => { el.textContent = text; });

  function recalc() {
    let subtotal = 0n;
    let tax = 0n;
    let valid = true;

    linesEl.querySelectorAll('[data-line]').forEach((line) => {
      const field = (f) => line.querySelector(`[data-field="${f}"]`);
      const qty = toHundredths(field('quantity').value);
      const price = toHundredths(field('unit_price').value, 0n);
      const rate = toHundredths(field('tax_rate').value, 0n);
      const totalEl = line.querySelector('[data-line-total]');

      if (qty === null || price === null || rate === null || rate > 10000n) {
        totalEl.textContent = '—';
        valid = false;
        return;
      }

      const lineSubtotal = divRound(qty * price, 100n);
      const lineTax = divRound(lineSubtotal * rate, 10000n);
      subtotal += lineSubtotal;
      tax += lineTax;
      totalEl.textContent = formatMoney(lineSubtotal);
    });

    const gross = subtotal + tax;
    let discount = 0n;
    const type = discountType.value;
    const dv = toHundredths(discountValue.value, 0n);

    if (type !== 'none' && dv === null) valid = false;
    if (type === 'fixed' && dv !== null) discount = dv > gross ? gross : dv;
    if (type === 'percent' && dv !== null) discount = divRound(gross * (dv > 10000n ? 10000n : dv), 10000n);

    const total = gross - discount;
    const paid = toHundredths(form.dataset.paid || '0', 0n) ?? 0n;

    setOut('subtotal', formatMoney(subtotal));
    setOut('tax', formatMoney(tax));
    setOut('discount', '−' + formatMoney(discount));
    setOut('total', formatMoney(total));
    setOut('balance', formatMoney(total - paid > 0n ? total - paid : 0n));

    form.classList.toggle('has-invalid-lines', !valid);
  }

  // ---------------------------------------------------------------------
  // Lines
  // ---------------------------------------------------------------------

  // Indexes only need to be unique; PHP re-numbers lines on save.
  let nextIndex = linesEl.querySelectorAll('[data-line]').length;

  function addLine(values = {}) {
    const html = template.innerHTML.replaceAll('__INDEX__', String(nextIndex++));
    linesEl.insertAdjacentHTML('beforeend', html);
    const line = linesEl.lastElementChild;
    Object.entries(values).forEach(([key, value]) => {
      const input = line.querySelector(`[data-field="${key}"]`);
      if (input && value !== null && value !== undefined) input.value = value;
    });
    return line;
  }

  const isBlank = (line) =>
    ['name', 'unit_price', 'product_id'].every((f) => line.querySelector(`[data-field="${f}"]`).value.trim() === '');

  function fillFromProduct(product) {
    const values = {
      product_id: product.id,
      name: product.name,
      description: product.description || '',
      unit: product.unit,
      quantity: '1',
      unit_price: product.unit_price,
      tax_rate: String(Number(product.tax_rate)),
    };
    // Reuse an empty last line instead of leaving it dangling.
    const last = linesEl.lastElementChild;
    if (last && isBlank(last)) {
      Object.entries(values).forEach(([key, value]) => {
        const input = last.querySelector(`[data-field="${key}"]`);
        if (input) input.value = value;
      });
      return last;
    }
    return addLine(values);
  }

  form.querySelector('[data-add-line]')?.addEventListener('click', () => {
    const line = addLine();
    line.querySelector('[data-field="name"]').focus();
    recalc();
  });

  picker?.addEventListener('change', () => {
    const product = products.find((p) => String(p.id) === picker.value);
    picker.value = '';
    if (!product) return;
    const line = fillFromProduct(product);
    line.querySelector('[data-field="quantity"]').select();
    recalc();
    markDirty();
  });

  linesEl.addEventListener('click', (e) => {
    const button = e.target.closest('[data-remove-line]');
    if (!button) return;
    const line = button.closest('[data-line]');
    if (linesEl.querySelectorAll('[data-line]').length === 1) {
      // Keep one line: clear it instead of removing it.
      line.querySelectorAll('input').forEach((input) => {
        const f = input.dataset.field;
        input.value = f === 'quantity' ? '1' : f === 'tax_rate' ? input.defaultValue || '0' : '';
      });
    } else {
      line.remove();
    }
    recalc();
    markDirty();
  });

  // Typing a different name than the catalog item unlinks the product.
  linesEl.addEventListener('change', (e) => {
    if (e.target.dataset.field !== 'name') return;
    const line = e.target.closest('[data-line]');
    const productId = line.querySelector('[data-field="product_id"]');
    const product = products.find((p) => String(p.id) === productId.value);
    if (product && product.name !== e.target.value.trim()) productId.value = '';
  });

  // ---------------------------------------------------------------------
  // Discount & dates
  // ---------------------------------------------------------------------

  function syncDiscount() {
    const none = discountType.value === 'none';
    discountValue.disabled = none;
    discountValue.placeholder = discountType.value === 'percent' ? '%' : '0.00';
    if (none) discountValue.value = '';
  }
  discountType.addEventListener('change', () => { syncDiscount(); recalc(); });

  // The due date follows the issue date (issue + payment terms) until the user picks one.
  let dueTouched = false;
  dueDate?.addEventListener('change', () => { dueTouched = true; });
  issueDate?.addEventListener('change', () => {
    if (dueTouched || !issueDate.value) return;
    const d = new Date(`${issueDate.value}T00:00:00`);
    d.setDate(d.getDate() + paymentTerms);
    const pad = (n) => String(n).padStart(2, '0');
    dueDate.value = `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  });

  // ---------------------------------------------------------------------
  // Unsaved changes warning
  // ---------------------------------------------------------------------

  let dirty = false;
  function markDirty() { dirty = true; }
  form.addEventListener('input', () => { markDirty(); recalc(); });
  form.addEventListener('submit', () => { dirty = false; });
  window.addEventListener('beforeunload', (e) => {
    if (!dirty) return;
    e.preventDefault();
    e.returnValue = '';
  });

  syncDiscount();
  recalc();

  // Expose the maths for quick checks in the console.
  window.LF.invoiceMath = { toHundredths, divRound, formatMoney };
})();
