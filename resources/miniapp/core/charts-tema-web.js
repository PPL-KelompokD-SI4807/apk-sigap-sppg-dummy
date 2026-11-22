// Web saja: saat tema diganti (peristiwa sigap:tema dari core/theme-web.js), grafik yang sedang tampil ikut diperbarui SEPENUHNYA,
// hasilnya sama dengan memuat langsung di tema itu: warna seri (string maupun larik, mis. doughnut), garis grid, garis sumbu,
// label sumbu, dan legenda. Chart.js hanya disentuh bila chunk-nya sudah dimuat (ada grafik di layar); selain itu tidak ada pekerjaan.
//
// Warna seri dihitung ulang oleh pemilik grafik lewat `warnaTema()` (lihat renderChart di core/charts.js), bukan ditebak dari nilai
// warna lama: dua token berbeda bisa sama di satu tema (mis. --accent dan --chart-1 di tema gelap) tetapi berbeda di tema lain.
import { loadChart, grafikSudahDimuat, terapkanDefault } from './charts.js';

const gaya = () => getComputedStyle(document.body ?? document.documentElement);
const token = (css, nama) => css.getPropertyValue(nama).trim() || undefined;

/** Perbarui satu instance Chart.js dengan token tema saat ini. Murni terhadap `c` dan `css` (dapat diuji tanpa peramban). */
export function perbaruiGrafik(c, css = gaya()) {
  if (typeof c.warnaTema === 'function') {
    const warna = c.warnaTema();
    (c.data?.datasets ?? []).forEach((d, i) => { if (warna[i]) Object.assign(d, warna[i]); });
  }
  const garis = token(css, '--separator');
  const label = token(css, '--hint');
  const skala = c.options?.scales;
  if (skala) {
    for (const id of Object.keys(skala)) {
      const s = skala[id];
      if (!s) continue;
      if (s.grid && garis) s.grid.color = garis;
      if (s.border && garis) s.border.color = garis;
      if (s.ticks && label) s.ticks.color = label;
      if (s.title && label) s.title.color = label;
      if (s.angleLines && garis) s.angleLines.color = garis;
      if (s.pointLabels && label) s.pointLabels.color = label;
    }
  }
  const lg = c.options?.plugins?.legend?.labels;
  if (lg && label) lg.color = label;
}

export function ikutiTemaGrafik() {
  addEventListener('sigap:tema', async () => {
    if (!grafikSudahDimuat()) return;
    const Chart = await loadChart();
    const semua = Object.values(Chart.instances);
    if (!semua.length) return;
    terapkanDefault(Chart);
    const css = gaya();
    for (const c of semua) {
      perbaruiGrafik(c, css);
      // 'resize' = transisi bawaan Chart.js berdurasi 0 (langsung, tanpa animasi) dan, tidak seperti 'none', ikut menyegarkan opsi titik/elemen yang dibagi.
      c.update('resize');
    }
  });
}
