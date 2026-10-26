import { h, clear } from '../core/dom.js';
import { api } from '../core/api.js';
import { getSppgAktif, setSppgAktif, hapusSppgAktif, tentukanSppgAktif, PESAN_TANPA_SPPG } from '../core/sppg-aktif.js';
import { errorMessage } from '../core/errors.js';

/**
 * Ambil /sppg-saya dan tentukan SPPG aktif. Hasil: {langkah:'siap'|'pilih'|'kosong', daftar, id?}.
 * Pilihan tersimpan yang ditolak server dibuang lalu dicoba sekali tanpa header.
 */
export async function muatSppgSaya() {
  let res;
  try {
    res = await api.get('/sppg-saya', undefined, { silent: true });
  } catch (e) {
    if (e.status === 403 && e.data?.alasan === 'sppg_aktif_ditolak') {
      hapusSppgAktif();
      res = await api.get('/sppg-saya', undefined, { silent: true });
    } else throw e;
  }
  const hasil = tentukanSppgAktif(res, getSppgAktif());
  if (hasil.langkah === 'siap') setSppgAktif(hasil.id); else if (hasil.langkah === 'pilih') hapusSppgAktif();
  return { ...hasil, daftar: res?.data ?? [] };
}

/** Layar pilih SPPG aktif. onPilih(id) dipanggil setelah pilihan disimpan. */
export async function renderPemilihSppg(el, { onPilih }) {
  clear(el);
  el.append(h('p', { class: 'muted pad' }, 'Memuat daftar SPPG...'));
  let hasil;
  try {
    hasil = await muatSppgSaya();
  } catch (e) {
    clear(el);
    el.append(h('div', { class: 'empty' }, h('p', { class: 'muted' }, errorMessage(e, 'Gagal memuat daftar SPPG.')),
      h('button', { class: 'btn btn-primary', type: 'button', onclick: () => renderPemilihSppg(el, { onPilih }) }, 'Coba lagi')));
    return;
  }
  clear(el);
  if (!hasil.daftar.length) {
    el.append(h('div', { class: 'empty' }, h('h2', {}, 'Belum ada SPPG'), h('p', { class: 'muted' }, PESAN_TANPA_SPPG)));
    return;
  }
  const aktif = getSppgAktif();
  const list = h('div', { class: 'stack' });
  for (const s of hasil.daftar) {
    list.append(h('button', {
      class: `btn btn-block${s.id === aktif ? ' btn-primary' : ''}`, type: 'button',
      onclick: () => { setSppgAktif(s.id); onPilih?.(s.id); },
    }, h('strong', {}, s.nama), h('span', { class: 'muted small' }, ` ${s.kode}${s.status_operasional ? ` · ${s.status_operasional}` : ''}`)));
  }
  el.append(h('section', { class: 'hero' }, h('h2', {}, 'Pilih SPPG'),
    h('p', { class: 'muted small' }, 'Data yang tampil mengikuti SPPG yang Anda pilih. Anda dapat menggantinya kapan saja dari beranda.')), list);
}
