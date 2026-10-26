// SPPG aktif untuk peran multi-SPPG (Investor). Murni (tanpa DOM) agar bisa diuji dengan node --test.
// Pilihan hanya kemudahan klien: server tetap memvalidasi header X-SPPG-Id pada setiap request.
export const HEADER_SPPG = 'X-SPPG-Id';
const KEY = 'sigap_sppg_aktif';

let aktif = null;
try { aktif = normalisasiId(sessionStorage.getItem(KEY)); } catch { /* mode privat / bukan peramban */ }

export function normalisasiId(v) {
  const n = Number(v);
  return Number.isInteger(n) && n > 0 ? n : null;
}

export const getSppgAktif = () => aktif;

export function setSppgAktif(id) {
  aktif = normalisasiId(id);
  try { aktif ? sessionStorage.setItem(KEY, String(aktif)) : sessionStorage.removeItem(KEY); } catch { /* abaikan */ }
}
export const hapusSppgAktif = () => setSppgAktif(null);

/** Tambahkan X-SPPG-Id ke objek header bila ada pilihan. Mengembalikan objek yang sama. */
export function terapkanHeaderSppg(headers, id = aktif) {
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

export const isInvestor = (user) => user?.role === 'investor';

/** SPPG yang masih bisa ditautkan = semua SPPG dikurangi yang sudah dimiliki. */
export function sppgBelumDimiliki(semua, dimiliki) {
  const punya = new Set((dimiliki || []).map((s) => s.id));
  return (semua || []).filter((s) => !punya.has(s.id));
}
