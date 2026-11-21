// Uji-peran P3: grid detail web 2/3 kolom memerlukan pasangan label+nilai dalam satu sel (dt+dd langsung di grid menggeser pasangan).
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const baca = (rel) => readFileSync(fileURLToPath(new URL(rel, import.meta.url)), 'utf8');

test('detail: setiap <dt>+<dd> dibungkus .detail-item di semua penyusun <dl class="detail">', () => {
  for (const rel of ['../components/crud-module.js', '../modules/pengguna/web.js', '../modules/persetujuan-menu/index.js']) {
    const src = baca(rel);
    assert.match(src, /detail-item/, `${rel}: pasangan dt/dd harus di dalam .detail-item`);
    assert.doesNotMatch(src, /\.append\(h\('dt'/, `${rel}: dt tidak boleh langsung jadi anak grid`);
  }
  assert.match(baca('../../css/miniapp.css'), /\.detail-item\s*\{/);
});
