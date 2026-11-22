// Chart.js dimuat malas (chunk terpisah) agar aset awal tetap kecil.
let promise;
export function loadChart() {
  promise ??= import('chart.js/auto').then((m) => m.default);
  return promise;
}

// Warna dibaca dari token CSS pada <body> (web: body.web memetakan ulang per tema; Mini App: body mewarisi :root/themeParams,
// jadi hasilnya sama seperti sebelumnya). Token --chart-N hanya ada di web; tanpanya dipakai palet bawaan Mini App.
const PALET_BAWAAN = ['#2481cc', '#d9831f', '#1f9d6e', '#d0504d', '#8e55d6', '#5a7a99'];
const gaya = () => getComputedStyle(document.body ?? document.documentElement);
export function paletGrafik() {
  const css = gaya();
  return PALET_BAWAAN.map((bawaan, i) => css.getPropertyValue(`--chart-${i + 1}`).trim() || bawaan);
}
export function terapkanDefault(Chart) {
  const css = gaya();
  Chart.defaults.color = css.getPropertyValue('--hint').trim() || '#707579';
  Chart.defaults.borderColor = css.getPropertyValue('--separator').trim() || '#e0e0e0';
}

export const grafikSudahDimuat = () => promise !== undefined;

/** renderChart(canvas, {type, data, options}) -> instance Chart. Warna mengikuti tema. */
export async function renderChart(canvas, config) {
  const Chart = await loadChart();
  terapkanDefault(Chart);
  return new Chart(canvas, { ...config, options: { responsive: true, maintainAspectRatio: false, ...config.options } });
}
