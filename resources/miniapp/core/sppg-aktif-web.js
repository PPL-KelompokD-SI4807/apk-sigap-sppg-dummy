// SPPG aktif mode WEB: HANYA di memori (tanpa localStorage/sessionStorage; hilang saat halaman dimuat ulang, lalu dipilih lagi
// dari GET /sppg-saya). Bentuk ekspor sama dengan sppg-aktif.js; dipilih lewat alias Vite pada `--mode web`.
import { normalisasiId, terapkanHeader } from './sppg-aktif-logika.js';

export {
  HEADER_SPPG, normalisasiId, perluPilihUlangSppg, tanpaSppg, PESAN_TANPA_SPPG, PESAN_PILIH_ULANG, tentukanSppgAktif, sppgBelumDimiliki,
} from './sppg-aktif-logika.js';

let aktif = null;

export const getSppgAktif = () => aktif;
export function setSppgAktif(id) { aktif = normalisasiId(id); }
export const hapusSppgAktif = () => { aktif = null; };

/** Tambahkan X-SPPG-Id ke objek header bila ada pilihan. Mengembalikan objek yang sama. */
export const terapkanHeaderSppg = (headers, id = aktif) => terapkanHeader(headers, id);
