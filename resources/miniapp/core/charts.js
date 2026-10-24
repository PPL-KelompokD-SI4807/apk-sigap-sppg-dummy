// Chart.js dimuat malas (chunk terpisah) agar aset awal tetap kecil.
let promise;
export function loadChart() {
  promise ??= import('chart.js/auto').then((m) => m.default);
  return promise;
}

/** renderChart(canvas, {type, data, options}) -> instance Chart. Warna mengikuti tema. */
export async function renderChart(canvas, config) {
  const Chart = await loadChart();
  const css = getComputedStyle(document.documentElement);
  Chart.defaults.color = css.getPropertyValue('--hint').trim() || '#707579';
  Chart.defaults.borderColor = css.getPropertyValue('--separator').trim() || '#e0e0e0';
  return new Chart(canvas, { ...config, options: { responsive: true, maintainAspectRatio: false, ...config.options } });
}
