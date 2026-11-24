// Penjaga zona waktu: tes tidak boleh menghitung "hari ini" dengan toISOString() (tanggal UTC),
// sedangkan aplikasi memakai tanggal LOKAL lewat todayISO() di core/validators.js.
// Keduanya berbeda di luar UTC (di Asia/Jakarta: pukul 00.00-07.00 WIB), sehingga tes yang
// memakai UTC akan merah tanpa ada perubahan kode. CI berjalan di UTC jadi tidak pernah terkena.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { todayISO } from '../core/validators.js';

const dir = fileURLToPath(new URL('.', import.meta.url));

test('todayISO memakai komponen tanggal lokal, bukan UTC', () => {
  const d = new Date(Date.UTC(2026, 10, 23, 17, 30)); // 2026-11-24 00.30 WIB, masih 2026-11-23 di UTC
  const p = (n) => String(n).padStart(2, '0');
  const lokal = `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
  assert.equal(todayISO(d), lokal);
});

test('tidak ada berkas tes yang menghitung tanggal hari ini dari toISOString', () => {
  const pola = /new Date\(\)\s*\.toISOString\(\)\s*\.slice\(\s*0\s*,\s*10\s*\)/;
  const pelanggar = readdirSync(dir)
    .filter((f) => f.endsWith('.test.js'))
    .filter((f) => pola.test(readFileSync(new URL(f, import.meta.url), 'utf8')));
  assert.deepEqual(pelanggar, [], `pakai todayISO() dari core/validators.js di: ${pelanggar.join(', ')}`);
});
