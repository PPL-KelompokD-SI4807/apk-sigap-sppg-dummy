// Merek WEB: logo BGN dan penanda akademik. Hanya dipakai shell-web.js dan modules/auth-web/layar.js (tidak masuk bundel Mini App).
// Tim TIDAK punya izin memakai lambang BGN; penanda akademik adalah syarat mutlak agar halaman tidak tampak sebagai portal resmi.
// Jangan dihapus, dikecilkan, atau dibuat samar (dijaga tests/merek-web.test.js).
import { h } from './dom.js';

// Pola `new URL(..., import.meta.url)` dikenali Vite: berkas di-fingerprint dan diemit terpisah (assetsInlineLimit 0 pada mode web);
// di Node (tes) hasilnya URL file biasa, jadi modul ini tetap bisa diimpor tanpa pemuat khusus.
const URL_LOGO = new URL('../assets/logo-bgn.webp', import.meta.url).href;

export const TEKS_PENANDA_MASUK = 'Proyek akademik. Bukan sistem resmi Badan Gizi Nasional.';
export const TEKS_PENANDA_DALAM = 'Proyek akademik, bukan sistem resmi BGN.';

/** <img> logo BGN. Ukuran (px) diatur CSS (.logo / .logo-besar); width/height mencegah pergeseran tata letak. Dekoratif: teks "SiGAP SPPG" selalu di sebelahnya. */
export function logoBgn({ ukuran = 32, besar = false } = {}) {
  return h('img', {
    class: besar ? 'logo logo-besar' : 'logo', src: URL_LOGO, width: ukuran, height: ukuran,
    alt: '', 'aria-hidden': 'true', decoding: 'async',
  });
}

/** Penanda akademik. `dalam` = versi ringkas di puncak kolom isi setelah masuk. */
export function penandaAkademik({ dalam = false } = {}) {
  return h('p', { class: dalam ? 'penanda-akademik penanda-dalam' : 'penanda-akademik', role: 'note' }, dalam ? TEKS_PENANDA_DALAM : TEKS_PENANDA_MASUK);
}
