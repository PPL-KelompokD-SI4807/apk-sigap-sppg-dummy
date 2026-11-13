// Tes layar Masuk web (R04-7) dan Sesi/Browser Tepercaya (R04-9): logika murni + klien API.
// Jalankan: node --test resources/miniapp/tests/*.test.js
import test from 'node:test';
import assert from 'node:assert/strict';

const larang = (nama) => new Proxy({}, { get: () => { throw new Error(`${nama} tidak boleh dipakai klien web`); }, set: () => { throw new Error(`${nama} tidak boleh ditulis`); } });
globalThis.localStorage = larang('localStorage');
globalThis.sessionStorage = larang('sessionStorage');

const { buatKlienWeb, ApiError } = await import('../core/api-web.js');
const masukLogika = await import('../modules/auth-web/logika.js');
const sesiLogika = await import('../modules/sesi/logika.js');

const resp = (status, body, headers = {}) => ({ ok: status >= 200 && status < 300, status, headers: { get: (k) => headers[k] ?? null }, json: async () => body });

// ---------- R04-7 ----------
test('bacaHasilMasuk: kode, selesai (browser tepercaya), galat', () => {
  assert.equal(masukLogika.bacaHasilMasuk({ langkah: 'kode', message: 'ok', tantangan: { id: 'x' } }).langkah, 'kode');
  assert.equal(masukLogika.bacaHasilMasuk({ langkah: 'selesai', csrf: 'a' }).langkah, 'selesai');
  assert.equal(masukLogika.bacaHasilMasuk({}).langkah, 'galat');
  assert.equal(masukLogika.bacaHasilMasuk(null).langkah, 'galat');
});

test('browser tepercaya: Admin selalu kode tidak dianggap diingat', () => {
  const admin = { langkah: 'selesai', browser_tepercaya: { diingat: false, alasan: 'admin_selalu_kode' } };
  assert.deepEqual(masukLogika.bacaBrowserTepercaya(admin), { admin: true, diingat: false });
  assert.match(masukLogika.pesanSelesai(admin, { ingatDiminta: true }), /Admin selalu memakai kode/);
  assert.equal(masukLogika.pesanSelesai(admin, { ingatDiminta: false }), null);
  assert.match(masukLogika.pesanSelesai({ browser_tepercaya: { diingat: true, berlaku_sampai: 'x' } }), /diingat 30 hari/);
  assert.match(masukLogika.pesanSelesai({ browser_tepercaya: { dipakai: true, diingat: false } }), /kode dilewati/);
  assert.equal(masukLogika.pesanSelesai({ langkah: 'selesai' }), null);
});

test('klasifikasiGalat: kredensial_salah, kode_tidak_valid, 429, 403, offline, validasi', () => {
  const e = (status, data, extra = {}) => Object.assign(new ApiError(status, data?.message || 'm', data?.errors ?? null, data), extra);
  assert.equal(masukLogika.klasifikasiGalat(e(422, { alasan: 'kredensial_salah' })).tipe, 'kredensial');
  assert.equal(masukLogika.klasifikasiGalat(e(422, { alasan: 'kode_tidak_valid' })).tipe, 'kode');
  assert.equal(masukLogika.klasifikasiGalat(e(422, { message: 'x', errors: { identitas: ['Wajib'] } })).tipe, 'validasi');
  assert.equal(masukLogika.klasifikasiGalat(e(403, { alasan: 'csrf_ditolak' })).tipe, 'csrf');
  assert.equal(masukLogika.klasifikasiGalat(new ApiError(0, 'x')).tipe, 'offline');
  const laju = masukLogika.klasifikasiGalat(e(429, {}, { retryAfter: 120 }));
  assert.equal(laju.tipe, 'laju');
  assert.match(laju.pesan, /2 menit/);
  const kunci = masukLogika.klasifikasiGalat(e(429, { alasan: 'terkunci', retry_after: 45 }));
  assert.match(kunci.pesan, /45 detik/);
  assert.match(kunci.pesan, /percobaan gagal/);
});

test('peringatan komputer bersama dan petunjuk sandi tersedia', () => {
  assert.match(masukLogika.PERINGATAN_INGAT, /komputer bersama/);
  assert.match(masukLogika.CATATAN_IDLE, /12 jam/);
  assert.match(masukLogika.PETUNJUK_LUPA_SANDI, /Mini App/);
});

// ---------- R04-9 ----------
const SESI = [
  { id: 51, jenis: 'miniapp', perangkat: 'Android', terakhir_dipakai_pada: '2026-11-13T09:00:00+07:00', sesi_ini: false },
  { id: 60, jenis: 'web', perangkat: 'Firefox', terakhir_dipakai_pada: '2026-11-13T07:00:00+07:00', sesi_ini: true },
  { id: 61, jenis: 'web', perangkat: 'Chrome', terakhir_dipakai_pada: '2026-11-13T08:00:00+07:00', sesi_ini: false, browser_tepercaya_id: 7 },
];

test('urutkanSesi: sesi ini dulu, lalu terbaru; masukan tidak diubah', () => {
  const urut = sesiLogika.urutkanSesi(SESI);
  assert.deepEqual(urut.map((s) => s.id), [60, 51, 61]);
  assert.deepEqual(SESI.map((s) => s.id), [51, 60, 61]);
});

test('kuota: hitung per jenis, batas dari meta, browser dari panjang daftar', () => {
  const r = sesiLogika.ringkasKuota(SESI, [{ id: 7 }], { kuota: { miniapp: 5, web: 3, browser_tepercaya: 5 } }, { kuota: 5, masa_hari: 30 });
  assert.equal(sesiLogika.teksKuota(r), '1/5 Mini App, 2/3 web, 1/5 browser tepercaya');
  const bawaan = sesiLogika.ringkasKuota([], [], undefined, undefined);
  assert.equal(sesiLogika.teksKuota(bawaan), '0/5 Mini App, 0/3 web, 0/5 browser tepercaya');
});

test('konfirmasi cabut membedakan sesi/browser ini', () => {
  assert.match(sesiLogika.konfirmasiCabutSesi({ sesi_ini: true }), /sedang Anda pakai/);
  assert.doesNotMatch(sesiLogika.konfirmasiCabutSesi({ sesi_ini: false }), /sedang Anda pakai/);
  assert.match(sesiLogika.konfirmasiCabutBrowser({ browser_ini: true }), /sedang Anda pakai/);
  assert.match(sesiLogika.konfirmasiCabutBrowser({ browser_ini: false }), /sesi web yang berasal darinya/);
});

test('sisaHari membulat ke atas dan tidak negatif', () => {
  const now = Date.parse('2026-11-13T00:00:00Z');
  assert.equal(sesiLogika.sisaHari('2026-12-13T00:00:00Z', now), 30);
  assert.equal(sesiLogika.sisaHari('2026-11-13T01:00:00Z', now), 1);
  assert.equal(sesiLogika.sisaHari('2026-11-01T00:00:00Z', now), 0);
  assert.equal(sesiLogika.sisaHari('bukan-tanggal', now), 0);
});

function pasang(skrip) {
  const calls = [];
  const fetchFn = async (url, init) => {
    const k = `${init.method} ${url.replace('/api/v1', '')}`;
    calls.push({ k, headers: init.headers, credentials: init.credentials });
    const r = skrip[k];
    if (!r) throw new Error(`fetch tak terduga: ${k}`);
    return r;
  };
  return { calls, klien: buatKlienWeb({ fetchFn }) };
}
const me = (csrf) => resp(200, { jenis_sesi: 'web', csrf, user: { id: 1, name: 'Siti' } });

test('daftarBrowserTepercaya: GET dengan header web, tanpa CSRF; hasil lengkap dengan meta', async () => {
  const body = { data: [{ id: 7, label: 'Firefox', browser_ini: true }], meta: { kuota: 5, masa_hari: 30 } };
  const { calls, klien } = pasang({ 'GET /auth/browser-tepercaya': resp(200, body) });
  const r = await klien.daftarBrowserTepercaya();
  assert.deepEqual(r, body);
  assert.equal(calls[0].headers['X-Sigap-Klien'], 'web');
  assert.equal(calls[0].credentials, 'same-origin');
  assert.equal('Authorization' in calls[0].headers, false);
});

test('cabutBrowserTepercaya: DELETE dengan CSRF; browser_ini mengosongkan sesi di memori, lainnya tidak', async () => {
  const { calls, klien } = pasang({
    'GET /auth/me': me('c'.repeat(64)),
    'DELETE /auth/browser-tepercaya/7': resp(200, { message: 'ok', browser_ini: false }),
    'DELETE /auth/browser-tepercaya/8': resp(200, { message: 'ok', browser_ini: true }),
  });
  await klien.muatSesi();
  await klien.cabutBrowserTepercaya(7);
  assert.equal(calls[1].headers['X-CSRF-Token'], 'c'.repeat(64));
  assert.equal(klien.adaSesi(), true);
  await klien.cabutBrowserTepercaya(8);
  assert.equal(klien.adaSesi(), false);
});

test('cabutBrowserTepercaya 404 dilempar sebagai ApiError tanpa menghapus sesi', async () => {
  const { klien } = pasang({ 'GET /auth/me': me('d'.repeat(64)), 'DELETE /auth/browser-tepercaya/9': resp(404, { message: 'Tidak ditemukan.' }) });
  await klien.muatSesi();
  await assert.rejects(() => klien.cabutBrowserTepercaya(9), (e) => e.status === 404);
  assert.equal(klien.adaSesi(), true);
});

test('keluarSemua membersihkan memori', async () => {
  const { klien } = pasang({ 'GET /auth/me': me('e'.repeat(64)), 'POST /auth/keluar-semua': resp(200, { message: 'ok', jumlah_sesi: 2 }) });
  await klien.muatSesi();
  const r = await klien.keluarSemua();
  assert.equal(r.jumlah_sesi, 2);
  assert.equal(klien.adaSesi(), false);
});
