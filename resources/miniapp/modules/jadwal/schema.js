// Skema Jadwal Produksi + Distribusi (fitur 9). Kontrak: docs/api/paths/jadwal.yaml, components/jadwal.yaml.
import { buildPayload } from '../../core/schema-helpers.js';
import { notBeforeToday, todayISO } from '../../core/validators.js';

export const STATUS_PRODUKSI = [
  { value: 'direncanakan', label: 'Direncanakan' },
  { value: 'dimasak', label: 'Dimasak' },
  { value: 'selesai', label: 'Selesai' },
  { value: 'dibatalkan', label: 'Dibatalkan' },
];
export const STATUS_DISTRIBUSI = [
  { value: 'menunggu', label: 'Menunggu' },
  { value: 'siap', label: 'Siap' },
  { value: 'berangkat', label: 'Berangkat' },
  { value: 'diterima', label: 'Diterima' },
  { value: 'dibatalkan', label: 'Dibatalkan' },
];
const labelOf = (list, v) => list.find((s) => s.value === v)?.label ?? v ?? '-';
export const statusProduksiLabel = (v) => labelOf(STATUS_PRODUKSI, v);
export const statusDistribusiLabel = (v) => labelOf(STATUS_DISTRIBUSI, v);
const BADGE = { direncanakan: 'badge-info', dimasak: 'badge-warn', selesai: 'badge-ok', dibatalkan: 'badge-danger', menunggu: 'badge-info', siap: 'badge-warn', berangkat: 'badge-warn', diterima: 'badge-ok' };
export const statusBadge = (v) => BADGE[v] || '';

// Transisi status valid (contract: StatusJadwalProduksi / StatusJadwalDistribusi).
export const TRANSISI = {
  produksi: { direncanakan: ['dimasak', 'dibatalkan'], dimasak: ['selesai', 'dibatalkan'], selesai: [], dibatalkan: [] },
  distribusi: { menunggu: ['siap', 'dibatalkan'], siap: ['berangkat', 'dibatalkan'], berangkat: ['diterima', 'dibatalkan'], diterima: [], dibatalkan: [] },
};
export const transisiValid = (kind, status) => [...(TRANSISI[kind]?.[status] ?? [])];
export const aksiLabel = (kind, to) => ({
  dimasak: 'Mulai masak', selesai: 'Tandai selesai', dibatalkan: 'Batalkan jadwal',
  siap: 'Tandai siap', berangkat: 'Berangkatkan', diterima: 'Tandai diterima',
}[to] ?? (kind === 'produksi' ? statusProduksiLabel(to) : statusDistribusiLabel(to)));

/** Tab yang boleh dilihat: relawan dapur = produksi saja, relawan distribusi = distribusi saja, peran lain keduanya. */
export function unitBoleh(user, kind) {
  if (!user) return false;
  if (user.role !== 'relawan') return true;
  return kind === 'produksi' ? user.unit === 'dapur' : user.unit === 'distribusi';
}

/** Relawan hanya mengubah/menghapus data buatannya sendiri (server menegakkan juga batas "hari yang sama"). */
export function bolehUbahMilik(user, rec, ownerKey = 'dibuat_oleh') {
  if (user?.role !== 'relawan') return true;
  return rec?.[ownerKey] == null || rec[ownerKey] === user.id;
}

/** Hak per baris: sembunyikan U/D sesuai kepemilikan relawan dan status yang dapat dihapus. */
export function rowPermsJadwal(kind, user, rec, p) {
  const dapatHapus = kind === 'produksi' ? ['direncanakan', 'dibatalkan'] : ['menunggu', 'siap', 'dibatalkan'];
  const milik = bolehUbahMilik(user, rec);
  return { ...p, U: p.U && milik, D: p.D && milik && dapatHapus.includes(rec.status) };
}

export const jadwalOptionLabel = (j) => `${j.tanggal_produksi} · ${j.menu?.nama_menu ?? 'Menu'} (${j.target_porsi} porsi)`;

// ---- Kapasitas ----
/** Sisa kapasitas harian: jadwal tidak dibatalkan pada tanggal itu, tidak menghitung jadwal yang sedang diubah. */
export function hitungKapasitas({ kapasitas, jadwal = [], excludeId = null, target = null }) {
  const terjadwal = jadwal.filter((j) => j.status !== 'dibatalkan' && j.id !== excludeId).reduce((a, j) => a + Number(j.target_porsi || 0), 0);
  const sisa = kapasitas == null ? null : Math.max(0, Number(kapasitas) - terjadwal);
  const t = target == null || target === '' ? null : Number(target);
  const melebihi = sisa != null && t != null && !Number.isNaN(t) && t > sisa;
  return { kapasitas: kapasitas == null ? null : Number(kapasitas), terjadwal, sisa, melebihi };
}

/** Sisa porsi yang dapat dialokasikan ke distribusi dari satu jadwal produksi. */
export function sisaPorsiDistribusi(jadwal, jumlahDiubah = 0) {
  if (!jadwal) return null;
  return Math.max(0, Number(jadwal.target_porsi || 0) - Number(jadwal.porsi_terjadwal_distribusi || 0) + Number(jumlahDiubah || 0));
}

// ---- Skema form produksi ----
export function produksiFields({ mode, record } = {}) {
  const berjalan = mode === 'update' && record && record.status !== 'direncanakan'; // hanya catatan yang dapat diubah
  return [
    { name: 'menu_id', label: 'Menu', type: 'relasi', required: true, readonly: berjalan, relation: { endpoint: '/menu-gizi', label: 'nama_menu', value: 'id', as: 'menu' } },
    { name: 'tanggal_produksi', label: 'Tanggal produksi', type: 'date', required: true, readonly: berjalan, default: todayISO(), validate: (v) => (mode === 'create' ? notBeforeToday(v, 'Tanggal produksi') : '') },
    { name: 'jam_mulai_masak', label: 'Jam mulai masak', type: 'time', readonly: berjalan },
    { name: 'target_porsi', label: 'Target porsi', type: 'number', required: true, min: 1, step: 1, readonly: berjalan, help: 'Dibatasi kapasitas harian SPPG.' },
    { name: 'catatan', label: 'Catatan', type: 'textarea', maxlength: 2000 },
  ];
}
export const produksiDetailFields = [
  { name: 'menu', label: 'Menu', display: (r) => r.menu?.nama_menu ?? '-' },
  { name: 'tanggal_produksi', label: 'Tanggal produksi', type: 'date' },
  { name: 'jam_mulai_masak', label: 'Jam mulai masak', display: (r) => (r.jam_mulai_masak ? String(r.jam_mulai_masak).slice(0, 5) : '-') },
  { name: 'target_porsi', label: 'Target porsi', type: 'number' },
  { name: 'status', label: 'Status', display: (r) => r.status_label || statusProduksiLabel(r.status) },
  { name: 'porsi_terjadwal_distribusi', label: 'Sudah dialokasikan ke distribusi', display: (r) => `${Number(r.porsi_terjadwal_distribusi ?? 0).toLocaleString('id-ID')} porsi` },
  { name: 'status_mutu', label: 'Pemeriksaan mutu', display: (r) => ({ layak: 'Layak', tidak_layak: 'Tidak layak' }[r.status_mutu] ?? 'Belum diperiksa') },
  { name: 'catatan', label: 'Catatan' },
];
export const produksiPayload = (values) => buildPayload(values, {
  keys: ['menu_id', 'tanggal_produksi', 'jam_mulai_masak', 'target_porsi', 'catatan'],
  ints: ['menu_id', 'target_porsi'],
  nullable: ['jam_mulai_masak', 'catatan'],
});

// ---- Skema form distribusi ----
const before = (a, b) => a && b && String(a).slice(0, 5) >= String(b).slice(0, 5);
export function distribusiFields({ mode, record } = {}) {
  const berjalan = mode === 'update' && record && !['menunggu', 'siap'].includes(record.status);
  const ro = berjalan;
  return [
    ...(mode === 'create' ? [{ name: 'jadwal_produksi_id', label: 'Jadwal produksi', type: 'relasi', required: true, relation: { endpoint: '/jadwal', filter: (j) => j.status !== 'dibatalkan', format: jadwalOptionLabel } }] : []),
    { name: 'titik_layanan_id', label: 'Titik layanan', type: 'relasi', required: true, readonly: ro, relation: { endpoint: '/titik-layanan', label: 'nama', value: 'id', as: 'titik_layanan' } },
    { name: 'armada_id', label: 'Armada', type: 'relasi', readonly: ro, relation: { endpoint: '/armada', label: 'plat_nomor', value: 'id', filter: (a) => a.status !== 'servis', format: (a) => `${a.plat_nomor} (${a.jenis ?? 'armada'})` } },
    { name: 'pengemudi_id', label: 'Pengemudi', type: 'relasi', readonly: ro, relation: { endpoint: '/pengemudi', label: 'nama', value: 'id' } },
    { name: 'tanggal_distribusi', label: 'Tanggal distribusi', type: 'date', readonly: ro, help: 'Kosongkan untuk memakai tanggal produksi.' },
    { name: 'jam_berangkat', label: 'Jam berangkat', type: 'time', readonly: ro, validate: (v, all) => (!v && (all.armada_id || all.pengemudi_id) ? 'Jam berangkat wajib bila armada/pengemudi diisi.' : '') },
    { name: 'jam_rencana_tiba', label: 'Jam rencana tiba', type: 'time', readonly: ro, validate: (v, all) => (v && all.jam_berangkat && before(all.jam_berangkat, v) ? 'Jam tiba harus setelah jam berangkat.' : '') },
    { name: 'jumlah_porsi', label: 'Jumlah porsi', type: 'number', required: true, min: 1, step: 1, readonly: ro },
    { name: 'catatan', label: 'Catatan', type: 'textarea', maxlength: 2000 },
  ];
}
export const distribusiDetailFields = [
  { name: 'titik_layanan', label: 'Titik layanan', display: (r) => r.titik_layanan?.nama ?? '-' },
  { name: 'tanggal_distribusi', label: 'Tanggal', type: 'date' },
  { name: 'jam_berangkat', label: 'Berangkat', display: (r) => (r.jam_berangkat ? String(r.jam_berangkat).slice(0, 5) : '-') },
  { name: 'jam_rencana_tiba', label: 'Rencana tiba', display: (r) => (r.jam_rencana_tiba ? String(r.jam_rencana_tiba).slice(0, 5) : '-') },
  { name: 'jumlah_porsi', label: 'Jumlah porsi', type: 'number' },
  { name: 'armada', label: 'Armada', display: (r) => r.armada?.plat_nomor ?? '-' },
  { name: 'pengemudi', label: 'Pengemudi', display: (r) => r.pengemudi?.nama ?? '-' },
  { name: 'status', label: 'Status', display: (r) => r.status_label || statusDistribusiLabel(r.status) },
  { name: 'catatan', label: 'Catatan' },
];
export const distribusiPayload = (values) => buildPayload(values, {
  keys: ['jadwal_produksi_id', 'titik_layanan_id', 'armada_id', 'pengemudi_id', 'tanggal_distribusi', 'jam_berangkat', 'jam_rencana_tiba', 'jumlah_porsi', 'catatan'],
  ints: ['jadwal_produksi_id', 'titik_layanan_id', 'armada_id', 'pengemudi_id', 'jumlah_porsi'],
  nullable: ['armada_id', 'pengemudi_id', 'jam_berangkat', 'jam_rencana_tiba', 'catatan'],
});

// ---- Kebutuhan bahan ----
/** Klasifikasi satu baris: 'tidak_dapat' | 'kurang' | 'cukup'. */
export function statusKebutuhan(it) {
  if (!it.dapat_dibandingkan) return 'tidak_dapat';
  return Number(it.kekurangan) > 0 || it.cukup === false ? 'kurang' : 'cukup';
}
/** Ringkas dari data respons (hitung ulang agar tahan data parsial). */
export function ringkasKebutuhan(data) {
  const bahan = data?.bahan ?? [];
  const k = bahan.map(statusKebutuhan);
  return { kurang: k.filter((s) => s === 'kurang').length, tidakDapat: k.filter((s) => s === 'tidak_dapat').length, cukup: k.filter((s) => s === 'cukup').length, total: bahan.length };
}
export const catatanTidakDapat = (it) => `Satuan "${it.satuan}" tidak dapat dikonversi dari gram. Kebutuhan sekitar ${Number(it.kebutuhan_gram ?? 0).toLocaleString('id-ID')} g; cek stok secara manual.`;
