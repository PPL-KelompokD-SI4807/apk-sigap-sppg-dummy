// Bagian MURNI SPPG aktif (tanpa state dan tanpa storage): dipakai bersama oleh sppg-aktif.js (Mini App, sessionStorage)
// dan sppg-aktif-web.js (web, hanya memori). Pilihan hanya kemudahan klien: server memvalidasi header X-SPPG-Id tiap request.
export const HEADER_SPPG = 'X-SPPG-Id';

export function normalisasiId(v) {
  const n = Number(v);
  return Number.isInteger(n) && n > 0 ? n : null;
}

/** Tambahkan X-SPPG-Id ke objek header bila id ada. Mengembalikan objek yang sama. */
export function terapkanHeader(headers, id) {
  if (id != null) headers[HEADER_SPPG] = String(id);
  return headers;
}

/** Galat yang berarti pilihan SPPG harus diulang: 409 belum dipilih / 403 ditolak. */
export function perluPilihUlangSppg(status, data) {
  return (status === 409 && data?.alasan === 'sppg_aktif_belum_dipilih')
    || (status === 403 && data?.alasan === 'sppg_aktif_ditolak');
}

/** 403 tanpa SPPG sama sekali: tidak ada yang bisa dipilih. */
export const tanpaSppg = (status, data) => status === 403 && data?.alasan === 'tanpa_sppg';

export const PESAN_TANPA_SPPG = 'Akun Anda belum ditautkan ke SPPG mana pun. Hubungi Admin.';
export const PESAN_PILIH_ULANG = 'Pilih SPPG yang ingin dilihat.';

/**
 * Tentukan langkah dari respons GET /sppg-saya:
 * 'kosong' (tidak punya SPPG) | 'pilih' (tampilkan pemilih) | {langkah:'siap', id}.
 * Pilihan tersimpan dipakai hanya bila masih ada dalam daftar; satu SPPG langsung terpilih.
 */
export function tentukanSppgAktif(res, tersimpan = null) {
  const daftar = Array.isArray(res?.data) ? res.data : [];
  if (!daftar.length) return { langkah: 'kosong' };
  const ids = daftar.map((s) => s.id);
  if (daftar.length === 1) return { langkah: 'siap', id: ids[0] };
  const simpan = normalisasiId(tersimpan);
  if (simpan != null && ids.includes(simpan) && !res?.wajib_pilih) return { langkah: 'siap', id: simpan };
  const server = normalisasiId(res?.sppg_aktif_id);
  if (server != null && ids.includes(server) && !res?.wajib_pilih) return { langkah: 'siap', id: server };
  return { langkah: 'pilih' };
}

/** SPPG yang masih bisa ditautkan = semua SPPG dikurangi yang sudah dimiliki. */
export function sppgBelumDimiliki(semua, dimiliki) {
  const punya = new Set((dimiliki || []).map((s) => s.id));
  return (semua || []).filter((s) => !punya.has(s.id));
}
