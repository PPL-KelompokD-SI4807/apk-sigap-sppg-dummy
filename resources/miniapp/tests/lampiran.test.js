// Tes unit unggah foto form (keluhan, mutu, log produksi): node --test resources/miniapp/tests/
import test from 'node:test';
import assert from 'node:assert/strict';
import { ApiError, uploadMultipart } from '../core/api.js';
import {
  TIPE_UNGGAH, MAKS_BYTE, MAKS_LAMPIRAN, validasiBerkasUnggah, pesanGalatUnggah, pesanBlokirSimpan,
  createUploadState, bodyUnggahTertunda, lampiranIdsPayload,
} from '../core/lampiran-uploader.js';
import { uploadLampiran, lampiranField } from '../components/lampiran-field.js';
import { PESAN_413, PESAN_429, PESAN_409 } from '../core/errors.js';
import * as keluhan from '../modules/keluhan/schema.js';
import * as mutu from '../modules/mutu/schema.js';
import * as log from '../modules/log-produksi/schema.js';

const foto = (size = 1000, type = 'image/jpeg') => ({ size, type, name: 'foto.jpg' });

// ---------- batas ukuran & tipe (cermin config/unggahan.php) ----------
test('validasiBerkasUnggah: jenis JPEG/PNG/WebP dan maks 5 MB sesuai config unggahan', () => {
  assert.deepEqual(TIPE_UNGGAH, ['image/jpeg', 'image/png', 'image/webp']);
  assert.equal(MAKS_BYTE, 5 * 1024 * 1024);
  assert.equal(MAKS_LAMPIRAN, 5);

  for (const t of TIPE_UNGGAH) assert.equal(validasiBerkasUnggah(foto(1000, t)), '', t);
  assert.equal(validasiBerkasUnggah(foto(MAKS_BYTE)), '', 'tepat 5 MB lolos');
  assert.match(validasiBerkasUnggah(foto(MAKS_BYTE + 1)), /maksimal 5 MB/);
  assert.match(validasiBerkasUnggah(foto(1000, 'image/gif')), /JPEG, PNG, atau WebP/);
  assert.match(validasiBerkasUnggah(foto(1000, 'application/pdf')), /JPEG, PNG, atau WebP/);
  assert.match(validasiBerkasUnggah(foto(1000, '')), /JPEG, PNG, atau WebP/);
  assert.match(validasiBerkasUnggah(foto(0)), /kosong/);
  assert.match(validasiBerkasUnggah(null), /Tidak ada berkas/);
});

test('pesanGalatUnggah memetakan offline/413/422/429/409/403/404/5xx ke bahasa Indonesia', () => {
  assert.match(pesanGalatUnggah(new ApiError(0, 'x')), /Tidak ada koneksi internet/);
  assert.equal(pesanGalatUnggah(new ApiError(413, 'x')), PESAN_413);
  assert.equal(pesanGalatUnggah(new ApiError(429, 'Too Many Attempts.')), PESAN_429);
  assert.equal(pesanGalatUnggah(new ApiError(409, 'x')), PESAN_409);
  assert.equal(pesanGalatUnggah(new ApiError(422, 'x', { berkas: ['Hanya gambar JPEG, PNG, atau WebP yang diizinkan.'] })), 'Hanya gambar JPEG, PNG, atau WebP yang diizinkan.');
  assert.match(pesanGalatUnggah(new ApiError(422, 'x')), /JPEG, PNG, atau WebP maksimal 5 MB/);
  assert.match(pesanGalatUnggah(new ApiError(403, 'x')), /hak/);
  assert.match(pesanGalatUnggah(new ApiError(404, 'x')), /tidak ditemukan/);
  assert.match(pesanGalatUnggah(new ApiError(503, 'x')), /gangguan/);
});

// ---------- status unggah ----------
const sukses = (id) => async ({ onProgress }) => { onProgress(40); onProgress(100); return { data: { id } }; };

test('createUploadState: unggah berhasil melapor progres dan mengumpulkan id untuk lampiran_ids', async () => {
  const kirim = [];
  const progres = [];
  const state = createUploadState({
    modul: 'keluhan', kategori: 'foto',
    upload: async (a) => { kirim.push({ modul: a.modul, kategori: a.kategori, key: a.key, sppgId: a.sppgId }); a.onProgress(50); progres.push(50); return { data: { id: 7 } }; },
  });
  const r = state.add({ file: foto(), key: 'k1', url: 'blob:1' });
  assert.equal(r.ok, true);
  assert.equal(r.item.status, 'mengunggah', 'status awal saat unggah berjalan');
  await r.selesai;

  assert.deepEqual(kirim, [{ modul: 'keluhan', kategori: 'foto', key: 'k1', sppgId: null }]);
  assert.equal(r.item.status, 'terunggah');
  assert.equal(r.item.progress, 100);
  assert.deepEqual(state.ids(), [7]);
  assert.deepEqual(state.hitung(), { mengunggah: 0, gagal: 0, terunggah: 1 });
  assert.equal(state.blokirSimpan(), '');
});

test('createUploadState: galat per foto tampil, Simpan diblokir, Coba lagi memakai kunci yang sama', async () => {
  const keys = [];
  let n = 0;
  const state = createUploadState({
    modul: 'mutu',
    upload: async (a) => { keys.push(a.key); if (++n === 1) throw new ApiError(0, 'offline'); return { data: { id: 21 } }; },
  });
  const r = state.add({ file: foto(), key: 'kunci-tetap' });
  await r.selesai;

  assert.equal(r.item.status, 'gagal');
  assert.match(r.item.error, /Tidak ada koneksi internet/);
  assert.deepEqual(state.ids(), []);
  assert.match(state.blokirSimpan(), /1 foto gagal diunggah/);

  await state.retry('kunci-tetap');
  assert.deepEqual(keys, ['kunci-tetap', 'kunci-tetap'], 'Idempotency-Key sama pada percobaan ulang');
  assert.equal(r.item.status, 'terunggah');
  assert.equal(r.item.error, '');
  assert.deepEqual(state.ids(), [21]);
  assert.equal(state.blokirSimpan(), '');
});

test('createUploadState: tiap kode galat server dipetakan ke pesan Indonesia per foto', async () => {
  for (const [status, cocok] of [[413, /5 MB/], [422, /ditolak|JPEG/], [429, /Terlalu banyak/], [403, /hak/], [500, /gangguan/]]) {
    const state = createUploadState({ modul: 'log-produksi', upload: async () => { throw new ApiError(status, 'x'); } });
    const r = state.add({ file: foto() });
    await r.selesai;
    assert.equal(r.item.status, 'gagal', String(status));
    assert.match(r.item.error, cocok, String(status));
  }
});

test('createUploadState: foto tidak sah ditolak sebelum dikirim; batas jumlah mengikuti config', async () => {
  let dikirim = 0;
  const state = createUploadState({ modul: 'keluhan', max: 3, tersimpan: 1, upload: async () => { dikirim++; return { data: { id: dikirim } }; } });

  assert.equal(state.add({ file: foto(MAKS_BYTE + 1) }).ok, false);
  assert.equal(state.add({ file: foto(100, 'image/gif') }).ok, false);
  assert.equal(dikirim, 0, 'tidak ada permintaan untuk berkas tak sah');
  assert.equal(state.items.length, 0);

  // Kapasitas 3, sudah ada 1 tersimpan -> 2 foto baru boleh, ketiga ditolak.
  const a = state.add({ file: foto() });
  const b = state.add({ file: foto() });
  await Promise.all([a.selesai, b.selesai]);
  const c = state.add({ file: foto() });
  assert.equal(c.ok, false);
  assert.match(c.error, /Batas 3 foto/);

  // Foto gagal tidak menghabiskan kapasitas.
  state.items[0].status = 'gagal';
  assert.equal(state.tersedia(), 1);
  assert.deepEqual(state.ids().length, 1);
});

test('createUploadState: hapus foto, id sppg Admin ikut dikirim, ids hanya yang terunggah', async () => {
  const diterima = [];
  const state = createUploadState({
    modul: 'keluhan', sppgId: () => '5',
    upload: async (a) => { diterima.push(a.sppgId); if (a.key === 'gagal') throw new ApiError(500, 'x'); return { data: { id: a.key === 'a' ? 1 : 2 } }; },
  });
  await Promise.all(['a', 'gagal', 'b'].map((key) => state.add({ file: foto(), key }).selesai));

  assert.deepEqual(diterima, ['5', '5', '5']);
  assert.deepEqual(state.ids(), [1, 2]);
  assert.deepEqual(state.hitung(), { mengunggah: 0, gagal: 1, terunggah: 2 });
  assert.equal(state.remove('gagal').key, 'gagal');
  assert.equal(state.blokirSimpan(), '');
  assert.equal(state.remove('tidak-ada'), null);
});

test('createUploadState: respons tanpa id valid dianggap gagal', async () => {
  const state = createUploadState({ modul: 'keluhan', upload: async () => ({ data: {} }) });
  const r = state.add({ file: foto() });
  await r.selesai;
  assert.equal(r.item.status, 'gagal');
  assert.deepEqual(state.ids(), []);
});

test('pesanBlokirSimpan: menunggu unggahan selesai atau memperbaiki yang gagal', () => {
  assert.equal(pesanBlokirSimpan({}), '');
  assert.match(pesanBlokirSimpan({ mengunggah: 2 }), /Tunggu 2 foto/);
  assert.match(pesanBlokirSimpan({ mengunggah: 1, gagal: 1 }), /Tunggu 1 foto/);
  assert.match(pesanBlokirSimpan({ gagal: 3 }), /3 foto gagal/);
});

test('bodyUnggahTertunda sesuai kontrak LampiranInput tanpa lampiranable_id; sppg_id hanya bila ada', () => {
  const berkas = foto();
  assert.deepEqual(bodyUnggahTertunda({ modul: 'keluhan', kategori: 'foto', file: berkas, sppgId: null }), { modul: 'keluhan', kategori: 'foto', berkas });
  assert.deepEqual(bodyUnggahTertunda({ modul: 'keluhan', kategori: 'foto', file: berkas, sppgId: '12' }), { modul: 'keluhan', kategori: 'foto', berkas, sppg_id: 12 });
  assert.equal('lampiranable_id' in bodyUnggahTertunda({ modul: 'mutu', kategori: 'foto', file: berkas }), false);
});

// ---------- payload simpan: lampiran_ids ----------
test('lampiranIdsPayload: kosong -> tidak dikirim, duplikat dibuang', () => {
  assert.equal(lampiranIdsPayload([]), undefined);
  assert.equal(lampiranIdsPayload(undefined), undefined);
  assert.deepEqual(lampiranIdsPayload([3, 4, 3, '5']), [3, 4, 5]);
});

test('payload keluhan/mutu/log produksi menyertakan lampiran_ids hanya bila ada foto terunggah', () => {
  const k = { sumber: 'guru', kategori: 'rasa', deskripsi: 'Sayur lembek', lampiran_ids: [11, 12] };
  assert.deepEqual(keluhan.keluhanPayload(k, { mode: 'create', user: { role: 'relawan' } }).lampiran_ids, [11, 12]);
  assert.equal('lampiran_ids' in keluhan.keluhanPayload({ ...k, lampiran_ids: [] }, { mode: 'create', user: { role: 'relawan' } }), false);
  assert.equal('lampiran_ids' in keluhan.keluhanPayload({ ...k, status: 'diproses' }, { mode: 'update', user: { role: 'kepala_sppg' } }), false, 'form tindak lanjut tanpa foto');
  assert.deepEqual(keluhan.keluhanPayload(k, { mode: 'update', user: { role: 'admin' } }).lampiran_ids, [11, 12]);

  const m = { jadwal_produksi_id: '3', kebersihan_dapur: 'true', kebersihan_petugas: 'true', rasa_baik: 'true', aroma_baik: 'true', tampilan_baik: 'true', hasil: 'layak', lampiran_ids: [8] };
  assert.deepEqual(mutu.mutuPayload(m, { mode: 'create' }).lampiran_ids, [8]);
  assert.equal('lampiran_ids' in mutu.mutuPayload({ ...m, lampiran_ids: [] }, { mode: 'create' }), false);

  const l = { jadwal_produksi_id: '3', realisasi_porsi: '900', lampiran_ids: [9] };
  assert.deepEqual(log.logPayload(l, { mode: 'create' }).lampiran_ids, [9]);
  assert.deepEqual(log.logPayload(l, { mode: 'update' }).lampiran_ids, [9]);
  assert.equal('lampiran_ids' in log.logPayload({ ...l, lampiran_ids: undefined }, { mode: 'create' }), false);
});

test('lampiranField: kolom custom lampiran_ids, tidak memblokir sebelum ada foto', () => {
  const f = lampiranField({ modul: 'keluhan', label: 'Foto keluhan', existing: [{ id: 1, url: '/api/v1/lampiran/1' }] });
  assert.equal(f.name, 'lampiran_ids');
  assert.equal(f.type, 'custom');
  assert.equal(typeof f.build, 'function');
  assert.equal(f.validate(), '', 'sebelum form dibangun tidak memblokir');
  assert.equal(f.required, undefined);
});

// ---------- unggah ke record yang sudah ada (panel lampiran) ----------
test('uploadLampiran menolak jenis/ukuran tak sah tanpa mengirim, dan memakai kunci idempotensi', async () => {
  const kirim = [];
  const deps = { request: async (m, p, o) => { kirim.push({ m, p, key: o.key }); return { data: { id: 1 } }; }, enqueue: async () => true };

  await assert.rejects(() => uploadLampiran({ modul: 'mutu', recordId: 4, file: foto(MAKS_BYTE + 1), key: 'k' }, deps), (e) => e.status === 422 && /5 MB/.test(e.errors.berkas[0]));
  await assert.rejects(() => uploadLampiran({ modul: 'mutu', recordId: 4, file: foto(10, 'image/gif'), key: 'k' }, deps), (e) => e.status === 422);
  assert.deepEqual(kirim, []);

  const r = await uploadLampiran({ modul: 'log-produksi', recordId: 4, file: foto(), key: 'kunci-9' }, deps);
  assert.equal(r.status, 'terunggah');
  assert.deepEqual(kirim, [{ m: 'POST', p: '/lampiran', key: 'kunci-9' }]);
});

// ---------- progres unggah (XMLHttpRequest) ----------
function fakeXhr({ status = 201, body = '{"data":{"id":5}}', error = false, progress = [[25, 100], [100, 100]] } = {}) {
  const x = {
    headers: {}, upload: {},
    open(m, u) { this.method = m; this.url = u; },
    setRequestHeader(k, v) { this.headers[k] = v; },
    send(fd) {
      this.body = fd;
      queueMicrotask(() => {
        if (error) return this.onerror();
        for (const [loaded, total] of progress) this.upload.onprogress({ lengthComputable: true, loaded, total });
        this.status = status;
        this.responseText = body;
        this.onload();
      });
    },
  };
  return x;
}

test('uploadMultipart: melapor progres 0-100, mengirim kunci idempotensi, mengembalikan JSON', async () => {
  const pct = [];
  const xhr = fakeXhr();
  const out = await uploadMultipart('/lampiran', { modul: 'keluhan', kategori: 'foto', berkas: new Blob(['x']) }, { key: 'kunci-x', onProgress: (p) => pct.push(p), xhrFactory: () => xhr });

  assert.deepEqual(out, { data: { id: 5 } });
  assert.deepEqual(pct, [25, 100, 100]);
  assert.equal(xhr.method, 'POST');
  assert.equal(xhr.url, '/api/v1/lampiran');
  assert.equal(xhr.headers['Idempotency-Key'], 'kunci-x');
  assert.equal(xhr.headers.Accept, 'application/json');
  assert.ok(xhr.body instanceof FormData);
  assert.equal(xhr.body.get('modul'), 'keluhan');
  assert.equal('lampiranable_id' in Object.fromEntries(xhr.body), false);
});

test('uploadMultipart: 422/413/429 menjadi ApiError, jaringan putus menjadi status 0', async () => {
  const gagal = (opts) => uploadMultipart('/lampiran', { berkas: new Blob(['x']) }, { xhrFactory: () => fakeXhr(opts) });

  await assert.rejects(() => gagal({ status: 422, body: '{"message":"x","errors":{"berkas":["Ukuran berkas maksimal 5 MB."]}}' }), (e) => e instanceof ApiError && e.status === 422 && e.errors.berkas[0] === 'Ukuran berkas maksimal 5 MB.');
  await assert.rejects(() => gagal({ status: 413, body: '<html>Request Entity Too Large</html>' }), (e) => e.status === 413 && e.message === PESAN_413);
  await assert.rejects(() => gagal({ status: 429, body: '{"message":"Too Many Attempts."}' }), (e) => e.status === 429 && e.message === PESAN_429);
  await assert.rejects(() => gagal({ error: true }), (e) => e.status === 0 && e.offline);
});
