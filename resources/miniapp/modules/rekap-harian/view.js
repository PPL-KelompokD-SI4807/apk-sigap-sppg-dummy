// Tampilan DOM kartu KPI + grafik Chart.js (dimuat lazy lewat core/charts.js). Warna dari variabel tema.
import { h } from '../../core/dom.js';
import { renderChart } from '../../core/charts.js';
import { kpiCards, distribusiSeries, trendSeries, withAlpha } from './schema.js';

/** Palet dari CSS tema (terang/gelap): --accent, --danger, --hint; hijau/kuning tetap terbaca di kedua mode. */
export function palette() {
  const css = getComputedStyle(document.documentElement);
  const v = (n, fb) => css.getPropertyValue(n).trim() || fb;
  return { accent: v('--accent', '#2481cc'), danger: v('--danger', '#d32f2f'), ok: '#1f9d6e', warn: '#d9831f', muted: v('--hint', '#707579'), card: v('--bg-section', '#ffffff') };
}

export function kpiGrid(r) {
  return h('div', { class: 'kpi-grid', role: 'list', 'aria-label': 'Indikator utama' },
    kpiCards(r).map((c) => h('div', { class: `kpi kpi-${c.tone}`, role: 'listitem', dataset: { kpi: c.id } },
      h('span', { class: 'kpi-label' }, c.label),
      h('strong', { class: 'kpi-value' }, c.value),
      c.progress != null ? h('div', { class: 'progress', role: 'progressbar', 'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-valuenow': String(Math.round(c.progress)), 'aria-label': c.label }, h('span', { style: `width:${c.progress}%` })) : null,
      c.sub ? h('span', { class: 'kpi-sub' }, c.sub) : null)));
}

/**
 * Grafik: batang capaian porsi (tren) + donat status distribusi. Mengembalikan {el, destroy}.
 * Setiap grafik punya ringkasan teks (sr-only + legenda HTML) agar tidak bergantung pada warna saja.
 */
export function chartsBlock(r, snapshots = [], { tren = true } = {}) {
  const trend = tren ? trendSeries(snapshots, r) : { labels: [], values: [], tanggal: [], aktif: -1 };
  const dist = distribusiSeries(r);
  const instances = [];
  let dead = false;

  const c1 = h('canvas', { role: 'img', 'aria-label': `Capaian porsi: ${trend.labels.map((l, i) => `${l} ${Math.round(trend.values[i])} persen`).join(', ') || 'belum ada data'}` });
  const c2 = h('canvas', { role: 'img', 'aria-label': `Status distribusi: ${dist.items.map((x) => `${x.label} ${x.value}`).join(', ')}` });
  const legend = h('ul', { class: 'legend' }, dist.items.map((x) => h('li', {}, h('span', { class: `dot dot-${x.key}`, 'aria-hidden': 'true' }), `${x.label}: `, h('strong', {}, String(x.value)))));
  const el = h('div', { class: 'stack' },
    tren ? h('div', { class: 'panel' }, h('h2', { class: 'panel-title' }, 'Capaian porsi 7 hari terakhir'), h('p', { class: 'kpi-sub' }, 'Realisasi dibanding target (%)'),
      trend.values.length ? h('div', { class: 'chart-box' }, c1) : h('p', { class: 'kpi-sub' }, 'Belum ada data riwayat.')) : null,
    h('div', { class: 'panel' }, h('h2', { class: 'panel-title' }, 'Status distribusi'),
      dist.total ? h('div', { class: 'chart-box chart-box-sm' }, c2) : h('p', { class: 'kpi-sub' }, 'Belum ada distribusi.'), legend));

  (async () => {
    try {
      const col = palette();
      if (trend.values.length) {
        const chart = await renderChart(c1, {
          type: 'bar',
          data: { labels: trend.labels, datasets: [{ label: 'Capaian (%)', data: trend.values, borderRadius: 6, backgroundColor: trend.values.map((_, i) => (i === trend.aktif ? col.accent : withAlpha(col.accent, 0.4))) }] },
          options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, suggestedMax: 100, ticks: { callback: (x) => `${x}%` } }, x: { grid: { display: false } } } },
        });
        if (dead) chart.destroy(); else instances.push(chart);
      }
      if (dist.total) {
        const chart = await renderChart(c2, {
          type: 'doughnut',
          data: { labels: dist.items.map((x) => x.label), datasets: [{ data: dist.items.map((x) => x.value), backgroundColor: dist.items.map((x) => col[x.key]), borderColor: col.card, borderWidth: 2 }] },
          options: { cutout: '62%', plugins: { legend: { display: false } } },
        });
        if (dead) chart.destroy(); else instances.push(chart);
      }
    } catch { /* grafik gagal dimuat: KPI dan legenda teks tetap tersedia */ }
  })();

  return { el, destroy: () => { dead = true; instances.splice(0).forEach((c) => { try { c.destroy(); } catch { /* abaikan */ } }); } };
}
