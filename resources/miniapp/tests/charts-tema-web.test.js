// Grafik ikut tema penuh: warna seri (dihitung ulang oleh pemilik grafik, termasuk larik doughnut), grid, sumbu, label, legenda.
// Logika murni; kesamaan piksel dengan muat langsung diuji terpisah di peramban.
import test from 'node:test';
import assert from 'node:assert/strict';
import { perbaruiGrafik } from '../core/charts-tema-web.js';

const css = (m) => ({ getPropertyValue: (k) => m[k] ?? '' });
const gelap = css({ '--separator': 'rgba(255, 255, 255, 0.09)', '--hint': '#9a98a5' });

test('perbaruiGrafik: warnaTema() dipanggil ulang dan hasilnya (string, larik, borderColor) menimpa dataset', () => {
  let tema = 'terang';
  const warnaTema = () => (tema === 'terang'
    ? [{ backgroundColor: ['#d9481f', '#1f9d6e'], borderColor: '#ffffff' }]
    : [{ backgroundColor: ['#f2653f', '#1f9d6e'], borderColor: '#16161b' }]);
  const c = { warnaTema, data: { datasets: [{ data: [1, 2], borderWidth: 2, ...warnaTema()[0] }] }, options: {} };
  tema = 'gelap';
  perbaruiGrafik(c, gelap);
  assert.deepEqual(c.data.datasets[0].backgroundColor, ['#f2653f', '#1f9d6e']);
  assert.equal(c.data.datasets[0].borderColor, '#16161b');
  assert.equal(c.data.datasets[0].borderWidth, 2, 'sifat non-warna tidak disentuh');
  assert.deepEqual(c.data.datasets[0].data, [1, 2]);
});

test('perbaruiGrafik: dataset tanpa entri warna dan grafik tanpa warnaTema (warna tetap) dibiarkan', () => {
  const c = { warnaTema: () => [{ borderColor: '#111111' }], data: { datasets: [{ borderColor: 'a' }, { borderColor: 'b' }] }, options: {} };
  perbaruiGrafik(c, gelap);
  assert.deepEqual(c.data.datasets.map((d) => d.borderColor), ['#111111', 'b']);
  const tetap = { data: { datasets: [{ backgroundColor: ['#2481cc'] }] }, options: {} };
  perbaruiGrafik(tetap, gelap);
  assert.deepEqual(tetap.data.datasets[0].backgroundColor, ['#2481cc']);
});

test('perbaruiGrafik: grid, border, tick, judul sumbu, dan label legenda mengikuti tema pada semua skala', () => {
  const c = {
    data: { datasets: [] },
    options: {
      scales: { x: { grid: { display: false }, ticks: {}, border: {} }, y: { grid: { color: 'x' }, ticks: { color: 'x' }, border: { color: 'x' }, title: { color: 'x' } } },
      plugins: { legend: { labels: { color: 'x' } } },
    },
  };
  perbaruiGrafik(c, gelap);
  for (const id of ['x', 'y']) {
    assert.equal(c.options.scales[id].grid.color, 'rgba(255, 255, 255, 0.09)');
    assert.equal(c.options.scales[id].border.color, 'rgba(255, 255, 255, 0.09)');
    assert.equal(c.options.scales[id].ticks.color, '#9a98a5');
  }
  assert.equal(c.options.scales.y.title.color, '#9a98a5');
  assert.equal(c.options.plugins.legend.labels.color, '#9a98a5');
});

test('perbaruiGrafik: doughnut tanpa skala dan tanpa legenda tidak melempar galat; token kosong tidak menimpa dengan undefined', () => {
  assert.doesNotThrow(() => perbaruiGrafik({ data: { datasets: [{ data: [1] }] }, options: { plugins: { legend: { display: false } } } }, gelap));
  const c = { data: { datasets: [] }, options: { scales: { y: { grid: { color: 'x' }, ticks: { color: 'x' } } } } };
  perbaruiGrafik(c, css({}));
  assert.equal(c.options.scales.y.grid.color, 'x');
  assert.equal(c.options.scales.y.ticks.color, 'x');
});
