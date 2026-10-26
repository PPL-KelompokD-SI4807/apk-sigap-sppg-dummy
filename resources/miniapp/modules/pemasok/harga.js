// Harga bahan per pemasok (R-06b-1): daftar + riwayat, dan form tambah (append-only; koreksi = entri baru).
import { h, formatValue } from '../../core/dom.js';
import { api, request, newKey, fetchList } from '../../core/api.js';
import { toast } from '../../core/ui.js';
import { errorMessage, mapValidationErrors } from '../../core/errors.js';
import { todayISO } from '../../core/validators.js';
import { kelompokHarga, validasiHarga, hargaPayload, perluGramPerSatuan } from './schema.js';

const rp = (v) => formatValue(v, 'rupiah');

function riwayat(g) {
  return h('details', { class: 'harga-riwayat' }, h('summary', { class: 'small' }, `Riwayat (${g.entri.length})`),
    h('div', { class: 'tbl' }, g.entri.map((x) => h('div', { class: 'tbl-row' },
      h('span', {}, `${rp(x.harga)}/${x.satuan ?? 'satuan'}`, h('div', { class: 'muted small' }, `berlaku ${formatValue(x.berlaku_mulai, 'date')}${x.sudah_berlaku ? '' : ' (belum berlaku)'}${x.catatan ? ` · ${x.catatan}` : ''}`)),
      x.gram_per_satuan ? h('span', { class: 'muted small' }, `${x.gram_per_satuan} g/satuan`) : null))));
}

async function daftarHarga(box, pemasokId) {
  box.replaceChildren(h('p', { class: 'muted small' }, 'Memuat harga...'));
  let items;
  try { items = (await api.get(`/pemasok/${pemasokId}/harga`, { per_page: 100 }, { silent: true }))?.data ?? []; } catch (e) {
    box.replaceChildren(h('p', { class: 'muted' }, errorMessage(e, 'Harga gagal dimuat.')));
    return;
  }
  const grup = kelompokHarga(items);
  box.replaceChildren();
  if (!grup.length) box.append(h('p', { class: 'muted' }, 'Belum ada harga tercatat untuk pemasok ini.'));
  for (const g of grup) {
    box.append(h('div', { class: 'stack-tight' },
      h('div', { class: 'card-row' }, h('strong', {}, g.nama),
        h('span', {}, g.terkini ? `${rp(g.terkini.harga)}/${g.satuan ?? 'satuan'}` : h('span', { class: 'badge badge-warn' }, 'Belum berlaku'))),
      riwayat(g)));
  }
}

function formTambah(rec, onSaved) {
  const bahanSel = h('select', { class: 'input', id: 'hg-bahan', 'aria-label': 'Bahan' }, h('option', { value: '' }, 'Memuat bahan...'));
  const satuanBahan = new Map();
  fetchList('/bahan-baku', { perPage: 100 }).then(({ items }) => {
    bahanSel.replaceChildren(h('option', { value: '' }, 'Pilih bahan'));
    for (const b of items.filter((x) => x.sppg_id == null || rec.sppg_id == null || x.sppg_id === rec.sppg_id)) {
      satuanBahan.set(String(b.id), b.satuan);
      bahanSel.append(h('option', { value: b.id }, `${b.nama} (${b.satuan})`));
    }
  }).catch(() => bahanSel.replaceChildren(h('option', { value: '' }, 'Gagal memuat bahan')));

  const harga = h('input', { class: 'input', id: 'hg-harga', type: 'number', min: '1', step: 'any', inputmode: 'decimal', 'aria-label': 'Harga per satuan (Rp)' });
  const gram = h('input', { class: 'input', id: 'hg-gram', type: 'number', min: '0', step: 'any', inputmode: 'decimal', 'aria-label': 'Gram per satuan' });
  const gramWrap = h('div', { class: 'field', hidden: true }, h('label', { class: 'label', for: 'hg-gram' }, 'Gram per satuan *'), gram);
  const mulai = h('input', { class: 'input', id: 'hg-mulai', type: 'date', value: todayISO(), 'aria-label': 'Berlaku mulai' });
  const catatan = h('input', { class: 'input', id: 'hg-catatan', type: 'text', maxlength: '255', 'aria-label': 'Catatan' });
  const err = h('p', { class: 'field-error', role: 'alert' });
  const btn = h('button', { class: 'btn btn-primary', type: 'button' }, 'Simpan harga');
  const field = (label, id, ctl) => h('div', { class: 'field' }, h('label', { class: 'label', for: id }, label), ctl);

  bahanSel.addEventListener('change', () => { gramWrap.hidden = !perluGramPerSatuan(satuanBahan.get(bahanSel.value)); });
  btn.addEventListener('click', async () => {
    const v = { bahan_baku_id: bahanSel.value, harga: harga.value, gram_per_satuan: gram.value, berlaku_mulai: mulai.value, catatan: catatan.value };
    const e = validasiHarga(v, satuanBahan.get(bahanSel.value));
    err.textContent = Object.values(e)[0] || '';
    if (Object.keys(e).length) return;
    btn.disabled = true;
    try {
      await request('POST', `/pemasok/${rec.id}/harga`, { body: hargaPayload({ ...v, gram_per_satuan: gramWrap.hidden ? '' : v.gram_per_satuan }), key: newKey(), silent: true });
      toast('Harga tersimpan.', 'success');
      harga.value = ''; gram.value = ''; catatan.value = '';
      onSaved();
    } catch (ex) {
      if (ex?.status === 401) throw ex;
      err.textContent = ex?.status === 422 ? Object.values(mapValidationErrors(ex.errors))[0] || errorMessage(ex) : errorMessage(ex, 'Harga gagal disimpan.');
    } finally { btn.disabled = false; }
  });
  return h('div', { class: 'stack-tight' }, h('h3', { class: 'panel-title' }, 'Tambah harga'),
    h('p', { class: 'muted small' }, 'Riwayat harga tidak dapat diubah atau dihapus. Untuk koreksi, catat harga baru.'),
    field('Bahan', 'hg-bahan', bahanSel), field('Harga per satuan (Rp)', 'hg-harga', harga), gramWrap, field('Berlaku mulai', 'hg-mulai', mulai), field('Catatan', 'hg-catatan', catatan), err, btn);
}

/** Panel harga untuk detail pemasok. perms.C = peran boleh menambah harga. */
export function hargaPanel(rec, perms) {
  const list = h('div', { class: 'stack' });
  const panel = h('div', { class: 'panel' }, h('h2', { class: 'panel-title' }, 'Harga bahan'), list);
  daftarHarga(list, rec.id);
  if (perms?.C) panel.append(formTambah(rec, () => daftarHarga(list, rec.id)));
  return panel;
}
