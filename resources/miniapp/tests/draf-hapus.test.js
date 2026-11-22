import test from 'node:test';
import assert from 'node:assert/strict';
import { pathHapus, tujuanDraf, draftCocok } from '../core/draf-tujuan.js';

test('pathHapus: tanpa draf = path lama, dengan draf = query ai_draft_id ter-encode', () => {
  assert.equal(pathHapus('/pemasok/5'), '/pemasok/5');
  assert.equal(pathHapus('/pemasok/5', null), '/pemasok/5');
  assert.equal(pathHapus('/pemasok/5', 'a b&c'), '/pemasok/5?ai_draft_id=a+b%26c');
});

test('draf hapus standar gizi menuju detail dan cocok dengan targetnya', () => {
  const d = { module: 'menu-gizi', action: 'delete', target_id: 7, payload: { _resource: 'standar_gizi' } };
  assert.equal(tujuanDraf(d), '/menu-gizi/standar-gizi/7');
  assert.ok(draftCocok(d, { module: 'menu-gizi', marker: 'standar_gizi', aksi: 'delete', targetId: 7 }));
  assert.ok(!draftCocok(d, { module: 'menu-gizi', marker: 'standar_gizi', aksi: 'delete', targetId: 8 }));
});
