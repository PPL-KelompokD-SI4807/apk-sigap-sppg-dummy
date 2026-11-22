// Tema WEB (siang/malam). Tiga pilihan: 'sistem' (bawaan, mengikuti prefers-color-scheme), 'terang', 'gelap'.
// Pilihan disimpan di localStorage (kunci khusus tema; BUKAN token/sesi, yang tetap hanya di cookie HttpOnly).
// Hasil akhir ditulis ke <html data-theme="light|dark"> dan color-scheme agar kontrol bawaan peramban ikut.
// Hanya dipakai web-main.js. Mini App Telegram tetap memakai core/theme.js (themeParams Telegram): modul ini tidak
// diimpor di sana, dan core/theme.js tidak dipanggil di web sehingga tidak ada yang menimpa data-theme.
export const PILIHAN = ['sistem', 'terang', 'gelap'];
export const KUNCI = 'sigap_tema_web';
export const PERISTIWA = 'sigap:tema';

const label = { sistem: 'Sistem', terang: 'Siang', gelap: 'Malam' };
export const labelPilihan = (p) => label[normalisasi(p)];

/** Nilai asing/rusak -> 'sistem'. */
export function normalisasi(v) {
  return PILIHAN.includes(v) ? v : 'sistem';
}

/** Pilihan + preferensi sistem -> 'dark' | 'light'. Pilihan eksplisit selalu menang atas sistem. */
export function tentukanTema(pilihan, sistemGelap) {
  const p = normalisasi(pilihan);
  if (p === 'gelap') return 'dark';
  if (p === 'terang') return 'light';
  return sistemGelap ? 'dark' : 'light';
}

/** Pilihan berikutnya dalam putaran sistem -> terang -> gelap -> sistem. */
export function berikutnya(pilihan) {
  return PILIHAN[(PILIHAN.indexOf(normalisasi(pilihan)) + 1) % PILIHAN.length];
}

const gudang = () => { try { return globalThis.localStorage ?? null; } catch { return null; } };

export function bacaPilihan(g = gudang()) {
  try { return normalisasi(g?.getItem(KUNCI)); } catch { return 'sistem'; }
}

export function simpanPilihan(pilihan, g = gudang()) {
  try { g?.setItem(KUNCI, normalisasi(pilihan)); return true; } catch { return false; }
}

const sistemGelap = () => { try { return globalThis.matchMedia?.('(prefers-color-scheme: dark)').matches === true; } catch { return false; } };

/** Tulis tema hasil ke akar dokumen. Mengembalikan 'dark' | 'light'. */
export function terapkanTema(pilihan, { root = globalThis.document?.documentElement, gelapSistem = sistemGelap() } = {}) {
  const tema = tentukanTema(pilihan, gelapSistem);
  if (root) {
    root.dataset.theme = tema;
    root.dataset.temaPilihan = normalisasi(pilihan);
    if (root.style) root.style.colorScheme = tema === 'dark' ? 'dark' : 'light'; // CSSOM, bukan atribut style (CSP)
  }
  return tema;
}

let pilihanAktif = 'sistem';
export const pilihanSaatIni = () => pilihanAktif;

/**
 * Tab lain mengganti tema (peristiwa `storage` hanya terbit di tab SELAIN penulisnya): baca ulang pilihan dan terapkan.
 * Tidak menulis balik ke penyimpanan (simpan:false), jadi tidak ada putaran penulisan antar tab.
 * key === null berarti penyimpanan dikosongkan (clear()) -> kembali ke 'sistem'.
 */
export function tanggapiStorage(e) {
  if (!e || (e.key !== null && e.key !== KUNCI)) return false;
  const baru = bacaPilihan();
  if (baru === pilihanAktif) return false;
  ubahTema(baru, { simpan: false });
  return true;
}

/** Pasang tema saat memuat + ikuti perubahan sistem selagi pilihan = 'sistem'. */
export function initTemaWeb() {
  pilihanAktif = bacaPilihan();
  terapkanTema(pilihanAktif);
  try {
    globalThis.matchMedia?.('(prefers-color-scheme: dark)').addEventListener?.('change', () => {
      if (pilihanAktif === 'sistem') ubahTema('sistem', { simpan: false });
    });
  } catch { /* peramban lama */ }
  try { globalThis.addEventListener?.('storage', tanggapiStorage); } catch { /* lingkungan uji */ }
  return pilihanAktif;
}

/** Ganti pilihan: simpan, terapkan, lalu umumkan agar sakelar dan grafik menyesuaikan. */
export function ubahTema(pilihan, { simpan = true } = {}) {
  pilihanAktif = normalisasi(pilihan);
  if (simpan) simpanPilihan(pilihanAktif);
  const tema = terapkanTema(pilihanAktif);
  try { globalThis.dispatchEvent?.(new CustomEvent(PERISTIWA, { detail: { pilihan: pilihanAktif, tema } })); } catch { /* lingkungan uji */ }
  return tema;
}
