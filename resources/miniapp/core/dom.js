// Pembuat elemen DOM aman (tanpa innerHTML) agar data server tidak pernah dieksekusi sebagai HTML.
export function h(tag, attrs = {}, ...children) {
  const el = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs || {})) {
    if (v == null || v === false) continue;
    if (k === 'class') el.className = v;
    else if (k === 'dataset') Object.assign(el.dataset, v);
    else if (k.startsWith('on') && typeof v === 'function') el.addEventListener(k.slice(2).toLowerCase(), v);
    else el.setAttribute(k, v === true ? '' : v);
  }
  appendAll(el, children);
  return el;
}

export function appendAll(el, children) {
  for (const c of children.flat(Infinity)) {
    if (c == null || c === false) continue;
    el.append(c instanceof Node ? c : document.createTextNode(String(c)));
  }
  return el;
}

export const clear = (el) => el.replaceChildren();

export function debounce(fn, ms = 350) {
  let t;
  return (...a) => {
    clearTimeout(t);
    t = setTimeout(() => fn(...a), ms);
  };
}

export function formatValue(v, type) {
  if (v == null || v === '') return '-';
  if (type === 'date') {
    const d = new Date(v);
    return isNaN(d) ? String(v) : d.toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
  }
  if (type === 'datetime') {
    const d = new Date(v);
    return isNaN(d) ? String(v) : d.toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' });
  }
  if (type === 'number') return Number(v).toLocaleString('id-ID');
  if (type === 'rupiah') return 'Rp ' + Number(v).toLocaleString('id-ID');
  if (typeof v === 'boolean') return v ? 'Ya' : 'Tidak';
  if (typeof v === 'object') return v.nama ?? v.name ?? JSON.stringify(v);
  return String(v);
}
