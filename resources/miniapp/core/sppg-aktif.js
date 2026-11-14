// SPPG aktif untuk peran multi-SPPG (Investor), Mini App: disimpan di sessionStorage. Murni (tanpa DOM) agar bisa diuji dengan node --test.
// Pilihan hanya kemudahan klien: server tetap memvalidasi header X-SPPG-Id pada setiap request.
// Web memakai core/sppg-aktif-web.js (hanya memori) lewat alias Vite; bagian murni ada di sppg-aktif-logika.js.
import { normalisasiId, terapkanHeader } from './sppg-aktif-logika.js';

export {
  HEADER_SPPG, normalisasiId, perluPilihUlangSppg, tanpaSppg, PESAN_TANPA_SPPG, PESAN_PILIH_ULANG, tentukanSppgAktif, sppgBelumDimiliki,
} from './sppg-aktif-logika.js';

const KEY = 'sigap_sppg_aktif';

let aktif = null;
try { aktif = normalisasiId(sessionStorage.getItem(KEY)); } catch { /* mode privat / bukan peramban */ }

export const getSppgAktif = () => aktif;

export function setSppgAktif(id) {
  aktif = normalisasiId(id);
  try { aktif ? sessionStorage.setItem(KEY, String(aktif)) : sessionStorage.removeItem(KEY); } catch { /* abaikan */ }
}
export const hapusSppgAktif = () => setSppgAktif(null);

/** Tambahkan X-SPPG-Id ke objek header bila ada pilihan. Mengembalikan objek yang sama. */
export const terapkanHeaderSppg = (headers, id = aktif) => terapkanHeader(headers, id);
