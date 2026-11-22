// Pembuat elemen DOM aman (tanpa innerHTML) agar data server tidak pernah dieksekusi sebagai HTML.
const ATRIBUT_URL = new Set(['href', 'src', 'action', 'formaction', 'xlink:href', 'poster', 'data']);

/**
 * Nilai URL aman untuk atribut: tolak skema yang bisa menjalankan kode (javascript:, vbscript:) dan data: selain
 * gambar. Karakter kontrol/spasi di depan dibuang dulu karena peramban mengabaikannya saat membaca skema.
 */
export function urlAman(v, { gambar = false } = {}) {
  const s = String(v).replace(/[\u0000-\u0020\u007f-\u009f]+/g, '');
  if (/^(javascript|vbscript):/i.test(s)) return false;
  if (/^data:/i.test(s)) return gambar && /^data:image\/(png|jpe?g|gif|webp);/i.test(s);
  return true;
}

/**
 * Buat elemen. Aman terhadap data server: anak selalu text node; atribut `on*` hanya menerima fungsi (string
 * ditolak, jadi tak ada handler inline); `srcdoc` ditolak; atribut URL disaring urlAman(); `style` string
 * diterapkan lewat CSSOM (el.style.cssText), bukan atribut style, sehingga kode kita sendiri tidak memerlukan
 * 'unsafe-inline' untuk atribut style. Catatan CSP: telegram-web-app.js (pihak ketiga) menyuntik elemen <style>, jadi
 * style-src tetap memerlukan 'unsafe-inline' selama skrip itu dimuat; pembatasan ini tidak menghapus kebutuhan tersebut.
 */
export function h(tag, attrs = {}, ...children) {
  const el = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs || {})) {
    if (v == null || v === false) continue;
    const kunci = k.toLowerCase();
    if (k === 'class') el.className = v;
    else if (k === 'dataset') Object.assign(el.dataset, v);
    else if (kunci.startsWith('on')) { if (typeof v === 'function') el.addEventListener(kunci.slice(2), v); }
    else if (kunci === 'srcdoc') continue;
    else if (kunci === 'style') { if (typeof v === 'string') el.style.cssText = v; }
    else if (ATRIBUT_URL.has(kunci) && !urlAman(v, { gambar: kunci === 'src' || kunci === 'poster' })) continue;
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
