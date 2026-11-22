// Sakelar tema web: satu tombol ikon (>= 44 px) yang memutar sistem -> siang -> malam. Label ARIA menyebut keadaan sekarang.
import { h } from '../core/dom.js';
import { ikon } from '../core/ikon-web.js';
import { pilihanSaatIni, ubahTema, berikutnya, labelPilihan, PERISTIWA } from '../core/theme-web.js';

const IKON = { sistem: 'sistem', terang: 'matahari', gelap: 'bulan' };

export function buatSakelarTema() {
  const tombol = h('button', { class: 'tema-btn', type: 'button' });
  const gambar = () => {
    const p = pilihanSaatIni();
    const nama = labelPilihan(p);
    const lanjut = labelPilihan(berikutnya(p));
    tombol.setAttribute('aria-label', `Tema tampilan: ${nama}. Ketuk untuk ganti ke ${lanjut}.`);
    tombol.setAttribute('title', `Tema: ${nama}`);
    tombol.dataset.pilihan = p;
    tombol.replaceChildren(ikon(IKON[p], { ukuran: 18 }), h('span', { class: 'tema-teks' }, nama));
  };
  tombol.addEventListener('click', () => { ubahTema(berikutnya(pilihanSaatIni())); });
  globalThis.addEventListener?.(PERISTIWA, gambar);
  gambar();
  return tombol;
}
