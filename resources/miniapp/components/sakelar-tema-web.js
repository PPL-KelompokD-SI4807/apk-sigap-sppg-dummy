// Sakelar tema web: satu tombol ikon (>= 44 px) dengan DUA keadaan (siang <-> malam). Tombol menampilkan keadaan SEKARANG
// (matahari + "Siang" / bulan + "Malam"); menekannya membalik ke yang satunya dan menjadikannya pilihan eksplisit.
import { h } from '../core/dom.js';
import { ikon } from '../core/ikon-web.js';
import { temaSaatIni, ubahTema, berikutnya, labelTema, PERISTIWA } from '../core/theme-web.js';

export function buatSakelarTema() {
  const tombol = h('button', { class: 'tema-btn', type: 'button' });
  const gambar = () => {
    const tema = temaSaatIni();
    const nama = labelTema(tema);
    const lanjut = labelTema(tema === 'dark' ? 'light' : 'dark');
    tombol.setAttribute('aria-label', `Tema tampilan: ${nama}. Ketuk untuk ganti ke ${lanjut}.`);
    tombol.setAttribute('title', `Tema: ${nama}`);
    tombol.dataset.tema = tema;
    tombol.replaceChildren(ikon(tema === 'dark' ? 'bulan' : 'matahari', { ukuran: 18 }), h('span', { class: 'tema-teks' }, nama));
  };
  tombol.addEventListener('click', () => { ubahTema(berikutnya(temaSaatIni())); });
  globalThis.addEventListener?.(PERISTIWA, gambar);
  gambar();
  return tombol;
}
