// Kolom kustom "Komposisi bahan": baris dinamis (bahan + takaran gram per porsi). Dipakai form-view type 'custom'.
import { h, clear } from '../../core/dom.js';
import { fetchList } from '../../core/api.js';
import { validasiKomposisi, MAX_BAHAN } from './schema.js';

/** Dapat dipakai langsung sebagai field.build. rows awal: [{bahan_baku_id, gram_per_porsi, nama?, satuan?}] */
export function buildKomposisi({ value }) {
  let rows = Array.isArray(value) && value.length ? value.map((r) => ({ bahan_baku_id: r.bahan_baku_id ?? '', gram_per_porsi: r.gram_per_porsi ?? '' })) : [];
  let options = null; // [{id,nama,satuan}] setelah dimuat
  const list = h('div', { class: 'komposisi' });
  const err = h('p', { class: 'field-error', role: 'alert' });
  const addBtn = h('button', { class: 'btn btn-block', type: 'button', onclick: () => { rows.push({ bahan_baku_id: '', gram_per_porsi: '' }); render(); } }, '+ Tambah bahan');
  const el = h('div', {}, list, addBtn, h('p', { class: 'muted small' }, 'Takaran dalam gram per porsi. Maksimal 50 bahan, tanpa bahan ganda.'), err);

  function render() {
    clear(list);
    rows.forEach((r, i) => {
      const sel = h('select', { 'aria-label': `Bahan baris ${i + 1}` }, h('option', { value: '' }, options ? '- Pilih bahan -' : 'Memuat bahan...'));
      (options || []).forEach((o) => sel.append(h('option', { value: o.id }, `${o.nama} (${o.satuan})`)));
      // bahan lama yang tak ada di daftar (mis. nonaktif) tetap dipertahankan sebagai opsi
      if (options && r.bahan_baku_id && !options.some((o) => String(o.id) === String(r.bahan_baku_id))) sel.append(h('option', { value: r.bahan_baku_id }, `Bahan #${r.bahan_baku_id}`));
      sel.value = r.bahan_baku_id ?? '';
      sel.addEventListener('change', () => { r.bahan_baku_id = sel.value; });
      const gram = h('input', { type: 'number', min: 0, step: 'any', inputmode: 'decimal', placeholder: 'gram', 'aria-label': `Takaran gram baris ${i + 1}`, value: r.gram_per_porsi });
      gram.addEventListener('input', () => { r.gram_per_porsi = gram.value; });
      list.append(h('div', { class: 'komposisi-row' }, sel, gram,
        h('button', { class: 'btn btn-danger', type: 'button', 'aria-label': `Hapus baris ${i + 1}`, onclick: () => { rows.splice(i, 1); render(); } }, '×')));
    });
    addBtn.disabled = rows.length >= MAX_BAHAN;
  }
  render();
  fetchList('/bahan-baku', { perPage: 100 }).then(({ items }) => { options = items.map((b) => ({ id: b.id, nama: b.nama, satuan: b.satuan })); render(); })
    .catch(() => { options = []; render(); err.textContent = 'Daftar bahan gagal dimuat.'; });

  return {
    el,
    get: () => rows.filter((r) => r.bahan_baku_id || r.gram_per_porsi).map((r) => ({ ...r })),
    hasValue: () => rows.some((r) => r.bahan_baku_id || r.gram_per_porsi),
    setError: (m) => { err.textContent = m || ''; },
  };
}

export const komposisiField = { name: 'bahan', label: 'Komposisi bahan', type: 'custom', build: buildKomposisi, validate: (v) => validasiKomposisi(v) };
