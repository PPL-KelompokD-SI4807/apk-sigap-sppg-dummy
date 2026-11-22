// Guard rute Mini App untuk Laporan BGN (register(), bukan registerWeb): router memanggil guard(params), sehingga guard harus
// membaca pengguna sesi sendiri. Regresi: params pernah terbaca sebagai `user` sehingga semua peran ditolak.
import test from 'node:test';
import assert from 'node:assert/strict';

const mem = () => { const m = new Map(); return { getItem: (k) => m.get(k) ?? null, setItem: (k, v) => m.set(k, String(v)), removeItem: (k) => m.delete(k) }; };
globalThis.localStorage = mem();
globalThis.sessionStorage = mem();
globalThis.window = globalThis.window ?? {};

const { setUserSesi } = await import('../core/auth.js');
const { cekAkses } = await import('../core/router.js');
const { default: modul } = await import('../modules/laporan-bgn/index.js');
const { menuUntuk } = await import('../modules/menu.js');
const { salin } = await import('./fixtures/peta.js');

modul.register(); // jalur Mini App (bukan registerWeb)

const HARAP = {
  admin: 'boleh', kepala_sppg: 'boleh', akuntan: 'boleh', investor: 'boleh',
  ahli_gizi: 'ditolak', staf_gudang: 'ditolak', relawan_dapur: 'ditolak', relawan_distribusi: 'ditolak', relawan_tanpa_unit: 'ditolak',
};

test('rute Mini App /laporan-bgn: peran dengan aksi lihat_laporan_bgn* boleh, lainnya ditolak', () => {
  for (const [nama, harap] of Object.entries(HARAP)) {
    setUserSesi(salin(nama));
    assert.equal(cekAkses('/laporan-bgn'), harap, nama);
  }
});

test('menu dan guard rute Laporan BGN konsisten per profil', () => {
  for (const nama of Object.keys(HARAP)) {
    const user = salin(nama);
    setUserSesi(user);
    const adaKartu = menuUntuk(user, [modul]).length === 1;
    assert.equal(adaKartu, cekAkses('/laporan-bgn') === 'boleh', nama);
  }
});
