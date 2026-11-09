// Bagian formulir yang dipakai bersama untuk draf Agen AI (crud-module dan layar khusus seperti mutasi stok).
import { h } from '../core/dom.js';
import { api } from '../core/api.js';
import { toast } from '../core/ui.js';
import { perluDiperbaiki } from '../core/draf-galat.js';

const unwrap = (res) => (res && res.data !== undefined && !Array.isArray(res) ? res.data : res);

/** GET /ai-drafts/{uuid} -> draf | null (galat ditampilkan sebagai toast; tidak melempar). */
export async function muatDraf(uuid) {
  try {
    return unwrap(await api.get(`/ai-drafts/${encodeURIComponent(uuid)}`, null, { silent: true }));
  } catch (e) {
    toast(e.status === 404 ? 'Draf AI tidak ditemukan atau sudah kedaluwarsa.' : 'Draf AI gagal dimuat.', 'error');
    return null;
  }
}

/**
 * Pemberitahuan draf di atas formulir: {info, alert, pasang(fv)}.
 *  info = "Diisi otomatis..." (hanya bila ada draf); alert = galat draf "perlu diperbaiki" (tersembunyi bila tidak ada).
 *  pasang(fv) menerapkan galat per kolom/baris dan pilihan referensi setelah formulir dibuat. Tidak menyimpan apa pun.
 */
export function pemberitahuanDraf(draft) {
  const info = draft ? h('div', { class: 'notice' }, 'Diisi otomatis oleh Agen AI. Periksa kolom bertanda "Perlu dicek", lalu simpan.') : null;
  const alert = h('div', { class: 'alert-danger', role: 'alert', hidden: true });
  function pasang(fv) {
    if (!draft) return;
    if (perluDiperbaiki(draft)) {
      const lainnya = fv.terapkanDraf({ errors: draft.errors || {}, pilihan: draft.pilihan_referensi || [] });
      alert.replaceChildren(h('strong', {}, 'Draf dari Agen AI perlu diperbaiki'),
        h('p', { class: 'small' }, 'Perbaiki kolom bertanda merah di bawah, lalu tekan Simpan. Tidak ada yang disimpan otomatis.'),
        lainnya.length ? h('ul', { class: 'small' }, lainnya.map((m) => h('li', {}, m))) : null);
      alert.hidden = false;
    } else fv.terapkanDraf({ errors: {}, pilihan: draft.pilihan_referensi || [] });
  }
  return { info, alert, pasang };
}
