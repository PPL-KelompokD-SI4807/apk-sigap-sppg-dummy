// Logika murni Persetujuan Menu (R-06b-1), tanpa DOM agar bisa diuji node --test.
// Kontrak: docs/api/paths/persetujuan-menu.yaml, components/persetujuan-menu.yaml. Server tetap otoritas akhir.
import { normalisasiId } from '../../core/sppg-aktif.js';

export const SLUG = 'persetujuan-menu';
export const MIN_CATATAN = 5;
export const MAKS_CATATAN = 1000;

export const STATUS_MENU = [
  { value: 'draf', label: 'Draf' },
  { value: 'diajukan', label: 'Diajukan' },
  { value: 'disetujui', label: 'Disetujui' },
  { value: 'ditolak', label: 'Ditolak' },
  { value: 'perlu_revisi', label: 'Perlu revisi' },
];
export const STATUS_PENGAJUAN = [
  { value: 'diajukan', label: 'Diajukan' },
  { value: 'disetujui', label: 'Disetujui' },
  { value: 'ditolak', label: 'Ditolak' },
  { value: 'perlu_revisi', label: 'Perlu revisi' },
  { value: 'dibatalkan', label: 'Dibatalkan' },
];
export const KEPUTUSAN = [
  { value: 'disetujui', label: 'Setujui' },
  { value: 'ditolak', label: 'Tolak' },
  { value: 'perlu_revisi', label: 'Perlu Revisi' },
];

const BADGE = { draf: '', diajukan: 'badge-info', disetujui: 'badge-ok', ditolak: 'badge-danger', perlu_revisi: 'badge-warn', dibatalkan: '' };
export const statusBadge = (s) => BADGE[s] ?? '';
export const statusLabel = (s) => [...STATUS_MENU, ...STATUS_PENGAJUAN].find((x) => x.value === s)?.label ?? s ?? '-';

export const SUMBER_PORSI = {
  jadwal_produksi: 'Dari jadwal produksi',
  penerima_manfaat_kelompok: 'Penerima manfaat kelompok sasaran',
  penerima_manfaat_total: 'Total penerima manfaat aktif',
};
export const sumberPorsiLabel = (s) => SUMBER_PORSI[s] ?? s ?? '-';

/** Hak C modul persetujuan-menu (ahli_gizi) dan status menu yang boleh diajukan. */
export const STATUS_BISA_DIAJUKAN = ['draf', 'ditolak', 'perlu_revisi'];
export const bolehAjukan = (permsPersetujuan, statusMenu) => !!permsPersetujuan?.C && STATUS_BISA_DIAJUKAN.includes(statusMenu);

/** Investor = pengguna dengan user.keputusan berisi "persetujuan-menu". */
export const bolehMemutuskan = (user) => Array.isArray(user?.keputusan) && user.keputusan.includes(SLUG);
export const tombolKeputusanTampil = (user, pengajuan) => bolehMemutuskan(user) && pengajuan?.status === 'diajukan' && !pengajuan?.kedaluwarsa;

/** Catatan wajib (min 5 karakter) untuk ditolak/perlu_revisi; opsional untuk disetujui. '' bila valid. */
export function validasiCatatan(keputusan, catatan) {
  const c = String(catatan ?? '').trim();
  if (c.length > MAKS_CATATAN) return `Catatan maksimal ${MAKS_CATATAN} karakter.`;
  if (keputusan !== 'disetujui' && c.length < MIN_CATATAN) return `Catatan wajib diisi (minimal ${MIN_CATATAN} karakter) untuk keputusan ini.`;
  return '';
}

/** Muatan keputusan: hanya keputusan, catatan, versi_menu. Tidak pernah memuat harga/biaya/porsi. */
export function payloadKeputusan({ keputusan, catatan, versiMenu }) {
  const c = String(catatan ?? '').trim();
  const out = { keputusan, versi_menu: Math.trunc(Number(versiMenu)) };
  if (c) out.catatan = c;
  return out;
}

/** Muatan ajukan: hanya versi dan catatan_pengaju (opsional). Harga/biaya/porsi dihitung server. */
export function payloadAjukan({ versi, catatan }) {
  const c = String(catatan ?? '').trim();
  const out = { versi: Math.trunc(Number(versi)) };
  if (c) out.catatan_pengaju = c;
  return out;
}

/** Menu yang boleh dipilih di Jadwal Produksi: hanya status_persetujuan 'disetujui'. */
export const menuDisetujui = (menu) => menu?.status_persetujuan === 'disetujui';
export const filterMenuDisetujui = (items) => (items || []).filter(menuDisetujui);

/** Label pilihan menu untuk Jadwal; non-disetujui diberi keterangan jika ikut ditampilkan. */
export const labelPilihanMenu = (m) => (menuDisetujui(m) ? m.nama_menu : `${m.nama_menu} (${statusLabel(m.status_persetujuan)}, belum dapat dipilih)`);

/** Ringkasan bahan bermasalah dari rincian biaya / galat 422. */
export const bahanBermasalah = (rincian) => (rincian?.bahan ?? []).filter((b) => b.masalah);

/** Petunjuk SPPG dari deep link: ?sppg= pada query string, atau pada hash "#/rute?sppg=". null bila tidak valid. */
export function parseSppgHint(search = '', hash = '') {
  const dariHash = hash.startsWith('#/') && hash.includes('?') ? new URLSearchParams(hash.slice(hash.indexOf('?') + 1)).get('sppg') : null;
  const dariSearch = new URLSearchParams(search).get('sppg');
  return normalisasiId(dariHash ?? dariSearch);
}

/**
 * Pesan galat untuk aksi persetujuan. 409 memakai pesan server (alasan spesifik) dan meminta muat ulang;
 * 422 menampilkan galat per field + daftar bahan tanpa harga.
 */
export function ringkasGalat(e, fallback = 'Aksi gagal.') {
  const status = e?.status;
  if (status === 403) return { pesan: 'Anda tidak berhak melakukan aksi ini.', muatUlang: false, fields: {}, bahan: [] };
  if (status === 409) return { pesan: e.data?.message || e.message || 'Data sudah berubah atau sudah diputuskan.', muatUlang: true, fields: {}, bahan: [] };
  if (status === 422) {
    const fields = {};
    for (const [k, v] of Object.entries(e.errors || {})) { const m = Array.isArray(v) ? v[0] : v; if (m) fields[k] = String(m); }
    const bahan = (e.data?.bahan_bermasalah ?? []).map((b) => ({ nama: b.nama, masalah: b.masalah }));
    const pesan = Object.values(fields)[0] || e.message || 'Data tidak valid.';
    return { pesan, muatUlang: false, fields, bahan };
  }
  return { pesan: e?.message || fallback, muatUlang: false, fields: {}, bahan: [] };
}
