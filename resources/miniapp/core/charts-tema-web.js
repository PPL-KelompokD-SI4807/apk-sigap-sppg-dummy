// Web saja: saat tema diganti (peristiwa sigap:tema dari core/theme-web.js), warna grafik yang sedang tampil ikut diperbarui.
// Chart.js hanya disentuh bila chunk-nya sudah dimuat (ada grafik di layar); selain itu tidak ada pekerjaan.
import { loadChart, grafikSudahDimuat, terapkanDefault, paletGrafik } from './charts.js';

export function ikutiTemaGrafik() {
  addEventListener('sigap:tema', async () => {
    if (!grafikSudahDimuat()) return;
    const Chart = await loadChart();
    if (!Object.keys(Chart.instances).length) return;
    terapkanDefault(Chart);
    const palet = paletGrafik();
    for (const c of Object.values(Chart.instances)) {
      c.data.datasets.forEach((d, i) => {
        const w = palet[i % palet.length];
        d.borderColor = w;
        if (typeof d.backgroundColor === 'string') d.backgroundColor = `${w}${c.config.type === 'line' ? '33' : 'cc'}`;
      });
      c.update('none');
    }
  });
}
