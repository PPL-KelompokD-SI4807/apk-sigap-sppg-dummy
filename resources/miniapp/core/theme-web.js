// Tema WEB (siang/malam). Dua keadaan yang terlihat: 'terang' (Siang) dan 'gelap' (Malam).
// Pengunjung yang belum memilih (pilihan = null) MENGIKUTI prefers-color-scheme sistem; ini bukan keadaan yang tampil/tersimpan.
// Begitu pengguna menekan sakelar, pilihannya eksplisit dan disimpan di localStorage (kunci khusus tema; BUKAN token/sesi, yang
// tetap hanya di cookie HttpOnly). Nilai tersimpan lain ('sistem' dari versi lama, nilai asing/rusak) dianggap belum memilih.
// Hasil akhir ditulis ke <html data-theme="light|dark"> dan color-scheme agar kontrol bawaan peramban ikut.
// Hanya dipakai web-main.js. Mini App Telegram tetap memakai core/theme.js (themeParams Telegram): modul ini tidak
// diimpor di sana, dan core/theme.js tidak dipanggil di web sehingga tidak ada yang menimpa data-theme.
export const PILIHAN = ['terang', 'gelap'];
export const KUNCI = 'sigap_tema_web';
export const PERISTIWA = 'sigap:tema';

/** Teks tombol untuk tema yang SEDANG tampil: 'light' -> Siang, 'dark' -> Malam. */
export const labelTema = (tema) => (tema === 'dark' ? 'Malam' : 'Siang');

/** 'terang' | 'gelap' | null. Nilai lain (termasuk 'sistem' lama) -> null = belum memilih, ikut sistem. */
export function normalisasi(v) {
  return PILIHAN.includes(v) ? v : null;
}

/** Pilihan + preferensi sistem -> 'dark' | 'light'. Pilihan eksplisit menang; tanpa pilihan ikut sistem. */
export function tentukanTema(pilihan, sistemGelap) {
  const p = normalisasi(pilihan);
  if (p === 'gelap') return 'dark';
  if (p === 'terang') return 'light';
  return sistemGelap ? 'dark' : 'light';
}

/** Pilihan yang dihasilkan menekan sakelar saat tema sekarang `tema` ('dark' -> 'terang', 'light' -> 'gelap'). */
export function berikutnya(tema) {
  return tema === 'dark' ? 'terang' : 'gelap';
}

const gudang = () => { try { return globalThis.localStorage ?? null; } catch { return null; } };

export function bacaPilihan(g = gudang()) {
  try { return normalisasi(g?.getItem(KUNCI)); } catch { return null; }
}

export function simpanPilihan(pilihan, g = gudang()) {
  const p = normalisasi(pilihan);
  if (p === null) return false; // hanya pilihan eksplisit yang disimpan
  try { g?.setItem(KUNCI, p); return true; } catch { return false; }
}

const sistemGelap = () => { try { return globalThis.matchMedia?.('(prefers-color-scheme: dark)').matches === true; } catch { return false; } };

/** Tulis tema hasil ke akar dokumen. Mengembalikan 'dark' | 'light'. */
export function terapkanTema(pilihan, { root = globalThis.document?.documentElement, gelapSistem = sistemGelap() } = {}) {
  const tema = tentukanTema(pilihan, gelapSistem);
  if (root) {
    root.dataset.theme = tema;
    root.dataset.temaPilihan = normalisasi(pilihan) ?? 'otomatis';
    if (root.style) root.style.colorScheme = tema === 'dark' ? 'dark' : 'light'; // CSSOM, bukan atribut style (CSP)
  }
  return tema;
}

let pilihanAktif = null;
let temaAktif = tentukanTema(null, false);
/** Pilihan eksplisit sekarang: 'terang' | 'gelap' | null (belum memilih, ikut sistem). */
export const pilihanSaatIni = () => pilihanAktif;
/** Tema yang sedang tampil: 'dark' | 'light'. */
export const temaSaatIni = () => temaAktif;

/**
 * Tab lain mengganti tema (peristiwa `storage` hanya terbit di tab SELAIN penulisnya): baca ulang pilihan dan terapkan.
 * Tidak menulis balik ke penyimpanan (simpan:false), jadi tidak ada putaran penulisan antar tab.
 * key === null berarti penyimpanan dikosongkan (clear()) -> kembali ikut sistem (pilihan null).
 */
export function tanggapiStorage(e) {
  if (!e || (e.key !== null && e.key !== KUNCI)) return false;
  const baru = bacaPilihan();
  if (baru === pilihanAktif) return false;
  ubahTema(baru, { simpan: false });
  return true;
}

/** Pasang tema saat memuat + ikuti perubahan sistem selagi pengguna belum memilih (pilihan null). */
export function initTemaWeb() {
  pilihanAktif = bacaPilihan();
  temaAktif = terapkanTema(pilihanAktif);
  try {
    globalThis.matchMedia?.('(prefers-color-scheme: dark)').addEventListener?.('change', () => {
      if (pilihanAktif === null) ubahTema(null, { simpan: false });
    });
  } catch { /* peramban lama */ }
  try { globalThis.addEventListener?.('storage', tanggapiStorage); } catch { /* lingkungan uji */ }
  return pilihanAktif;
}

/** Ganti pilihan: simpan, terapkan, lalu umumkan agar sakelar dan grafik menyesuaikan. */
export function ubahTema(pilihan, { simpan = true } = {}) {
  pilihanAktif = normalisasi(pilihan);
  if (simpan) simpanPilihan(pilihanAktif);
  const tema = temaAktif = terapkanTema(pilihanAktif);
  try { globalThis.dispatchEvent?.(new CustomEvent(PERISTIWA, { detail: { pilihan: pilihanAktif, tema } })); } catch { /* lingkungan uji */ }
  return tema;
}
