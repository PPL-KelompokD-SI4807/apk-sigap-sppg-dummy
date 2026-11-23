// Layar kode login web yang jujur (fix/login-web-jelas): hitung mundur memakai waktu server, kuota kirim ulang,
// sisa percobaan, tantangan mati, dan panduan "kode tidak datang". Tanpa DOM sungguhan: elemen tiruan + jam palsu.
// Revisi 2026-11-23 (fix/login-sandi-salah-jelas): layar kode hanya tercapai bila sandi BENAR; kredensial salah dan
// "sandi benar tetapi belum bisa lanjut" dijawab 422 di layar masuk (lihat bagian bawah berkas ini).
import test, { mock } from 'node:test';
import assert from 'node:assert/strict';

class Node_ {}
class El extends Node_ {
  constructor(tag) {
    super();
    Object.assign(this, { tag, attrs: {}, listeners: {}, anak: [], className: '', dataset: {}, style: { cssText: '' }, hidden: false, disabled: false, value: '', checked: false, isConnected: true, difokus: false });
  }
  get textContent() { return this.anak.map((a) => (a instanceof El ? a.textContent : a.teks ?? '')).join(''); }
  set textContent(v) { this.anak = v === '' || v == null ? [] : [Object.assign(new Node_(), { teks: String(v) })]; }
  setAttribute(k, v) { this.attrs[k] = String(v); }
  addEventListener(n, fn) { (this.listeners[n] ||= []).push(fn); }
  append(...xs) { this.anak.push(...xs); }
  replaceChildren(...xs) { const lepas = (a) => { if (a instanceof El) { a.isConnected = false; a.anak.forEach(lepas); } }; this.anak.forEach(lepas); this.anak = [...xs]; }
  focus() { this.difokus = true; }
  remove() { this.isConnected = false; }
  async picu(n, ev = { preventDefault() {} }) { for (const fn of this.listeners[n] || []) await fn(ev); }
}
globalThis.Node = Node_;
globalThis.document = { createElement: (t) => new El(t), createTextNode: (t) => Object.assign(new Node_(), { teks: String(t) }), getElementById: () => null, title: '', body: { classList: { add() {}, remove() {} } } };
globalThis.window = globalThis.window ?? {};

const { tampilkanMasukWeb } = await import('../modules/auth-web/layar.js');
const L = await import('../modules/auth-web/logika.js');

const cari = (n, f, h = []) => { if (n instanceof El) { if (f(n)) h.push(n); n.anak.forEach((x) => cari(x, f, h)); } return h; };
const teks = (n) => (n instanceof El ? n.textContent : n.teks ?? '');
const tombol = (el, re) => cari(el, (x) => x.tag === 'button' && re.test(x.textContent))[0];
const adaTeks = (el, re) => re.test(el.textContent);

const T0 = Date.parse('2026-11-23T10:00:00+07:00');
const iso = (ms) => new Date(ms).toISOString();
/** Tantangan hidup: kode dikirim pada `kirimMs`; jeda 60 dtk, masa 5 menit (nilai seperti dari server). */
const tantangan = (kirimMs, sisa = 3, tambah = {}) => ({
  id: '01jcz8w3q4m5n6p7r8s9t0v1w2', kedaluwarsa_pada: iso(kirimMs + 300_000), kirim_ulang_tersedia_pada: iso(kirimMs + 60_000),
  sisa_kirim_ulang: sisa, maks_kirim_ulang: 3, maks_percobaan: 5, ...tambah,
});
const PESAN = 'Kode 6 digit telah dikirim ke Telegram Anda.';

/** Jalankan layar sampai layar kode. `klien.waktuServer` dibaca dari jam palsu (T0 + ofset server). */
async function bukaKode(t, { ofsetServerMs = 0, klien = {} } = {}) {
  mock.timers.enable({ apis: ['setInterval', 'Date'], now: T0 });
  const el = new El('div');
  const k = {
    waktuServer: () => Date.now() + ofsetServerMs,
    masuk: async () => ({ langkah: 'kode', message: PESAN, tantangan: t }),
    ...klien,
  };
  const layar = tampilkanMasukWeb(el, { klien: k, onSelesai: k.onSelesai });
  const id = cari(el, (x) => x.attrs.name === 'identitas')[0]; id.value = 'siti.dapur';
  const pw = cari(el, (x) => x.attrs.name === 'password')[0]; pw.value = 'Rahasia123x';
  await cari(el, (x) => x.tag === 'form')[0].picu('submit');
  return { el, layar, k };
}
const selesaiUji = () => mock.timers.reset();

test('hitung mundur memakai kirim_ulang_tersedia_pada dari server, bukan now+60 di klien', async () => {
  // Server bilang kode terkirim 25 detik lalu: tersisa 35 detik. Tebakan klien "now + 60" akan salah (60).
  const { el, layar } = await bukaKode(tantangan(T0 - 25_000));
  const ulang = tombol(el, /Kirim ulang kode|Jatah kirim ulang/);
  assert.equal(ulang.disabled, true);
  assert.match(ulang.textContent, /dalam 35 detik/);
  mock.timers.tick(10_000);
  assert.match(ulang.textContent, /dalam 25 detik/);
  mock.timers.tick(25_000);
  assert.equal(ulang.disabled, false);
  assert.equal(ulang.textContent, 'Kirim ulang kode');
  layar.destroy(); selesaiUji();
});

test('jam perangkat meleset: hitung mundur mengikuti jam server (header Date), bukan jam perangkat', async () => {
  // Perangkat tertinggal 10 menit dari server. Tanpa koreksi, kode tampak sudah kedaluwarsa; dengan koreksi tetap hidup.
  const serverMs = T0 + 600_000;
  const { el, layar } = await bukaKode(tantangan(serverMs - 20_000), { ofsetServerMs: 600_000 });
  assert.match(tombol(el, /Kirim ulang kode/).textContent, /dalam 40 detik/);
  assert.ok(cari(el, (x) => x.attrs.name === 'kode')[0], 'form kode tampil');
  layar.destroy(); selesaiUji();
});

test('sisa_kirim_ulang 0: tombol mati permanen dengan keterangan jatah habis, tetap mati setelah jeda lewat', async () => {
  const { el, layar } = await bukaKode(tantangan(T0 - 120_000, 0)); // jeda sudah lewat, tetapi jatah habis
  const ulang = tombol(el, /Jatah kirim ulang habis/);
  assert.ok(ulang, 'label tombol menyebut jatah habis');
  assert.equal(ulang.disabled, true);
  assert.ok(adaTeks(el, /Jatah kirim ulang habis\. Bila kode belum datang, mulai dari awal\./));
  mock.timers.tick(60_000);
  assert.equal(ulang.disabled, true);
  layar.destroy(); selesaiUji();
});

test('kirim ulang: nilai baru dari server dipakai apa adanya (jeda dan jatah)', async () => {
  let dipanggil = 0;
  const { el, layar } = await bukaKode(tantangan(T0 - 70_000, 3), {
    klien: { kirimUlangKode: async () => { dipanggil += 1; return { langkah: 'kode', message: PESAN, tantangan: tantangan(Date.now(), 0) }; } },
  });
  const ulang = tombol(el, /Kirim ulang kode/);
  assert.equal(ulang.disabled, false);
  await ulang.picu('click');
  assert.equal(dipanggil, 1);
  assert.match(ulang.textContent, /Jatah kirim ulang habis/);
  assert.equal(ulang.disabled, true);
  layar.destroy(); selesaiUji();
});

test('kirim ulang ditolak jeda: jatah tidak berubah, pesan jujur bahwa belum bisa', async () => {
  const sama = tantangan(T0 - 70_000, 2);
  const { el, layar } = await bukaKode(sama, { klien: { kirimUlangKode: async () => ({ langkah: 'kode', message: PESAN, tantangan: { ...sama } }) } });
  await tombol(el, /Kirim ulang kode/).picu('click');
  assert.ok(adaTeks(el, /Sisa jatah kirim ulang: 2 dari 3/));
  layar.destroy(); selesaiUji();
});

const galat422 = (sisa) => Object.assign(new Error('Kode salah atau kedaluwarsa.'), {
  status: 422, errors: { kode: ['Kode salah atau kedaluwarsa.'] }, data: { alasan: 'kode_tidak_valid', ...(sisa === undefined ? {} : { sisa_percobaan: sisa }) },
});
async function kirimKode(el, kode = '123456') {
  cari(el, (x) => x.attrs.name === 'kode')[0].value = kode;
  await cari(el, (x) => x.tag === 'form' && x.attrs.novalidate !== undefined && cari(x, (y) => y.attrs.name === 'kode').length)[0].picu('submit');
}
const galatKode = (el) => cari(el, (x) => x.className === 'field-error' && x.attrs.role === 'alert').map(teks).filter(Boolean);

test('sisa percobaan tampil dari badan 422 dan teks galat tetap seragam di setiap percobaan', async () => {
  const urut = [4, 3, 2];
  const { el, layar } = await bukaKode(tantangan(T0 - 5_000), { klien: { kirimKode: async () => { throw galat422(urut.shift()); } } });
  const pesan = [];
  for (const sisa of [4, 3, 2]) {
    await kirimKode(el);
    pesan.push(galatKode(el)[0]);
    assert.ok(adaTeks(el, new RegExp(`Sisa percobaan: ${sisa} dari 5\\.`)), `sisa ${sisa} tampil`);
  }
  assert.deepEqual(new Set(pesan), new Set([L.PESAN_KODE_TIDAK_VALID]), 'teks galat seragam, tidak menyiratkan sandi benar/salah');
  assert.doesNotMatch(L.PESAN_KODE_TIDAK_VALID, /sandi|password|username/i);
  layar.destroy(); selesaiUji();
});

test('sisa_percobaan 0: teks galat tetap sama, form dikunci, pengguna diarahkan mulai dari awal', async () => {
  const { el, layar } = await bukaKode(tantangan(T0 - 5_000), { klien: { kirimKode: async () => { throw galat422(0); } } });
  await kirimKode(el);
  assert.deepEqual(galatKode(el), [L.PESAN_KODE_TIDAK_VALID]);
  assert.ok(adaTeks(el, /Sisa percobaan: 0 dari 5\./));
  assert.ok(adaTeks(el, new RegExp(L.PESAN_PERCOBAAN_HABIS.replace('.', '\\.'))));
  assert.equal(cari(el, (x) => x.attrs.name === 'kode')[0].disabled, true);
  assert.equal(tombol(el, /^Verifikasi$/).disabled, true, 'tombol verifikasi tetap mati setelah terjaga()');
  assert.equal(tombol(el, /Kirim ulang|Jatah/).disabled, true);
  assert.equal(tombol(el, /Mulai dari awal/).difokus, true);
  layar.destroy(); selesaiUji();
});

test('422 tanpa sisa_percobaan (kontrak lama): tidak menampilkan angka palsu', async () => {
  const { el, layar } = await bukaKode(tantangan(T0 - 5_000), { klien: { kirimKode: async () => { throw galat422(undefined); } } });
  await kirimKode(el);
  assert.deepEqual(galatKode(el), [L.PESAN_KODE_TIDAK_VALID]);
  assert.doesNotMatch(el.textContent, /Sisa percobaan/);
  layar.destroy(); selesaiUji();
});

test('tantangan mati (kedaluwarsa = waktu respons, jatah 0): tanpa kolom kode, ada tautan mulai dari awal', async () => {
  const mati = { id: '01jcz8w3q4m5n6p7r8s9t0v1w2', kedaluwarsa_pada: iso(T0), kirim_ulang_tersedia_pada: iso(T0), sisa_kirim_ulang: 0, maks_kirim_ulang: 3, maks_percobaan: 5 };
  const { el, layar } = await bukaKode(mati);
  assert.equal(cari(el, (x) => x.attrs.name === 'kode').length, 0, 'tidak ada kolom kode');
  assert.ok(adaTeks(el, /sudah tidak berlaku/));
  assert.equal(cari(el, (x) => x.attrs.role === 'alert').length > 0, true, 'keadaan diumumkan');
  const mulai = tombol(el, /Mulai dari awal/);
  assert.ok(mulai);
  await mulai.picu('click');
  assert.ok(cari(el, (x) => x.attrs.name === 'identitas')[0], 'kembali ke layar masuk');
  layar.destroy(); selesaiUji();
});

test('tantangan mati bila jam server sudah lewat masa berlaku saat layar terbuka; interval berhenti', async () => {
  const { el, layar } = await bukaKode(tantangan(T0 - 250_000)); // sisa 50 detik
  assert.ok(cari(el, (x) => x.attrs.name === 'kode')[0]);
  mock.timers.tick(51_000);
  assert.equal(cari(el, (x) => x.attrs.name === 'kode').length, 0);
  assert.ok(adaTeks(el, /sudah tidak berlaku/));
  layar.destroy(); selesaiUji();
});

test('tantanganMati: mati bila kedaluwarsa <= tersedia atau <= jam server; hidup bila tidak', () => {
  assert.equal(L.tantanganMati({ kedaluwarsa_pada: iso(T0), kirim_ulang_tersedia_pada: iso(T0) }, T0 - 999_999), true, 'kembar waktu respons = mati, apa pun jam perangkat');
  assert.equal(L.tantanganMati(tantangan(T0), T0 + 10_000), false);
  assert.equal(L.tantanganMati(tantangan(T0), T0 + 300_000), true);
  assert.equal(L.tantanganMati({}, T0), false, 'waktu tak terbaca bukan alasan mematikan');
});

test('panduan "kode tidak datang": hanya sebab yang masih mungkin, tanpa butir sandi salah atau belum tertaut', async () => {
  const { el, layar } = await bukaKode(tantangan(T0 - 5_000));
  const kotak = cari(el, (x) => x.className === 'panduan-kode')[0];
  assert.ok(kotak, 'kotak panduan ada (bukan kalimat kecil)');
  assert.equal(cari(kotak, (x) => x.tag === 'h3').map(teks)[0], 'Kode tidak datang?');
  const butir = cari(kotak, (x) => x.tag === 'li').map(teks);
  assert.equal(butir.length, 3);
  assert.match(butir[0], /belum menekan Start/);
  assert.match(butir[1], /Telegram.*(lambat|bermasalah)/);
  assert.match(butir[2], /akun yang berbeda/);
  // Sandi salah tidak pernah sampai ke layar ini, dan akun yang belum tertaut dijawab 422 di layar masuk: kedua butir itu menyesatkan.
  assert.doesNotMatch(kotak.textContent, /sandi|password|username|belum tertaut|tidak menyebut mana/i);
  layar.destroy(); selesaiUji();
});

test('tidak ada interval menggantung: destroy, onSelesai, dan layar mati menghentikan hitung mundur', async () => {
  // destroy
  let r = await bukaKode(tantangan(T0 - 5_000));
  r.layar.destroy();
  const label = tombol(r.el, /Kirim ulang kode dalam/).textContent;
  mock.timers.tick(10_000);
  assert.equal(tombol(r.el, /Kirim ulang kode dalam/).textContent, label, 'tidak berdetak setelah destroy');
  selesaiUji();
  // onSelesai (login berhasil)
  let selesai = 0;
  r = await bukaKode(tantangan(T0 - 5_000), { klien: { kirimKode: async () => ({ langkah: 'selesai', user: {}, browser_tepercaya: {} }), onSelesai: () => { selesai += 1; } } });
  const label2 = tombol(r.el, /Kirim ulang kode dalam/).textContent;
  await kirimKode(r.el);
  assert.equal(selesai, 1);
  mock.timers.tick(10_000);
  assert.equal(tombol(r.el, /Kirim ulang kode dalam/).textContent, label2, 'berhenti saat onSelesai');
  selesaiUji();
  // layar diganti tanpa destroy (form terlepas): tick berikutnya menghentikan diri
  r = await bukaKode(tantangan(T0 - 5_000));
  const form = cari(r.el, (x) => x.tag === 'form')[0];
  r.el.replaceChildren();
  assert.equal(form.isConnected, false);
  mock.timers.tick(1_000);
  mock.timers.tick(1_000); // tidak melempar dan tidak membuat ulang layar
  assert.equal(r.el.anak.length, 0);
  r.layar.destroy(); selesaiUji();
});

test('perubahan hitung mundur tidak dibacakan tiap detik: penghitung dikecualikan dari aria-live', async () => {
  const { el, layar } = await bukaKode(tantangan(T0 - 5_000));
  // #view (web.html) beraria-live=polite, jadi elemen yang berubah tiap detik wajib dikecualikan dengan aria-live=off.
  const live = cari(el, (x) => 'aria-live' in x.attrs);
  assert.ok(live.length >= 3 && live.every((x) => x.attrs['aria-live'] === 'off'), 'tidak ada live polite/assertive eksplisit');
  for (const re of [/Kirim ulang kode dalam/, /Kode berlaku/]) {
    const x = cari(el, (n) => re.test(n.textContent) && n.anak.every((a) => !(a instanceof El)))[0];
    assert.equal(x.attrs['aria-live'], 'off', String(re));
  }
  const status = cari(el, (x) => x.attrs.role === 'status' && x.className === 'sr-only')[0];
  assert.ok(status, 'wilayah status untuk perubahan keadaan penting');
  mock.timers.tick(5_000);
  assert.equal(status.textContent, '', 'detik berjalan tidak masuk wilayah status');
  mock.timers.tick(55_000); // jeda habis: keadaan penting diumumkan sekali
  assert.match(status.textContent, /sudah aktif/);
  layar.destroy(); selesaiUji();
});

// ---------- Revisi 2026-11-23: kredensial salah dan ember "sandi benar tapi belum bisa lanjut" ----------
const galatMasuk = (data, status = 422) => Object.assign(new Error(data.message ?? 'm'), { status, errors: data.errors ?? null, data });
const KRED = { message: 'Identitas atau sandi salah.', errors: { password: ['Identitas atau sandi salah.'] }, alasan: 'kredensial_salah' };
const TELEGRAM = { message: 'Sandi benar, tetapi akun ini belum terhubung ke Telegram sehingga kode tidak dapat dikirim. Buka Mini App SiGAP di Telegram untuk menghubungkan akun, lalu masuk lagi.', alasan: 'telegram_belum_terhubung' };
const SERING = { message: 'Terlalu banyak permintaan kode untuk akun ini. Tunggu beberapa menit, lalu coba masuk lagi.', alasan: 'terlalu_sering' };

async function bukaMasuk(klien, { username = 'siti.dapur' } = {}) {
  mock.timers.enable({ apis: ['setInterval', 'Date'], now: T0 });
  const el = new El('div');
  const layar = tampilkanMasukWeb(el, { klien });
  const id = cari(el, (x) => x.attrs.name === 'identitas')[0]; id.value = username;
  const pw = cari(el, (x) => x.attrs.name === 'password')[0]; pw.value = 'SalahBanget1';
  const kirim = async () => cari(el, (x) => x.tag === 'form')[0].picu('submit');
  return { el, layar, id, pw, kirim };
}
const tetapDiLayarMasuk = (el) => cari(el, (x) => x.attrs.name === 'identitas').length === 1 && cari(el, (x) => x.attrs.name === 'kode').length === 0;
const galatSandi = (el) => cari(el, (x) => x.className === 'field-error' && x.attrs.role === 'alert').map(teks);
const kotakForm = (el) => cari(el, (x) => /notice/.test(x.className) && x.attrs.role === 'alert' && !x.hidden && x.textContent)[0];

test('422 kredensial_salah: tetap di layar masuk, pesan di field sandi, username dipertahankan, sandi kosong dan difokus', async () => {
  const klien = { masuk: async () => { throw galatMasuk(KRED); } };
  const { el, layar, id, pw, kirim } = await bukaMasuk(klien);
  await kirim();
  assert.ok(tetapDiLayarMasuk(el), 'tidak pindah ke layar kode');
  assert.equal(cari(el, (x) => x.attrs.name === 'identitas')[0], id, 'layar masuk tidak dibangun ulang (elemen sama)');
  assert.equal(id.value, 'siti.dapur', 'username dipertahankan');
  assert.equal(pw.value, '', 'sandi dikosongkan');
  assert.equal(pw.difokus, true, 'fokus ke field sandi');
  assert.deepEqual(galatSandi(el).filter(Boolean), ['Identitas atau sandi salah.']);
  assert.equal(kotakForm(el), undefined, 'bukan galat tingkat form');
  assert.equal(pw.attrs['aria-invalid'], 'true');
  assert.match(cari(el, (x) => x.tag === 'label' && /field/.test(x.className) && cari(x, (y) => y === pw).length)[0].className, /invalid/);
  assert.equal(tombol(el, /^Masuk$/).disabled, false, 'tombol aktif lagi untuk mencoba ulang');
  assert.doesNotMatch(el.textContent, /Kode 6 digit|Masukkan kode|Kode tidak datang/);
  layar.destroy(); selesaiUji();
});

test('422 kredensial_salah: memakai pesan server; cadangan klien hanya bila server tidak mengirim pesan', async () => {
  let balasan = { ...KRED, message: 'Pesan dari server.', errors: { password: ['Pesan dari server.'] } };
  const klien = { masuk: async () => { throw galatMasuk(balasan); } };
  const { el, layar, pw, kirim } = await bukaMasuk(klien);
  await kirim();
  assert.deepEqual(galatSandi(el).filter(Boolean), ['Pesan dari server.']);
  balasan = { alasan: 'kredensial_salah' };
  pw.value = 'SalahLagi2'; // sandi dikosongkan setelah galat
  await kirim();
  assert.deepEqual(galatSandi(el).filter(Boolean), [L.PESAN_KREDENSIAL_SALAH]);
  assert.equal(L.PESAN_KREDENSIAL_SALAH, 'Identitas atau sandi salah.');
  layar.destroy(); selesaiUji();
});

test('kredensial salah berulang: galat diganti (bukan ditumpuk) dan dihapus saat kirim berikutnya', async () => {
  let n = 0;
  const klien = { masuk: async () => { n += 1; throw galatMasuk(KRED); } };
  const { el, layar, pw, kirim } = await bukaMasuk(klien);
  await kirim(); pw.value = 'lagi'; await kirim();
  assert.equal(n, 2);
  assert.equal(galatSandi(el).filter(Boolean).length, 1, 'satu pesan saja yang terpasang');
  // Kirim dengan sandi kosong: validasi klien menghapus galat server lama dan tidak memanggil server.
  pw.value = '';
  await kirim();
  assert.equal(n, 2);
  assert.deepEqual(galatSandi(el).filter(Boolean), ['Wajib diisi.']);
  layar.destroy(); selesaiUji();
});

for (const [nama, badan, pola] of [['telegram_belum_terhubung', TELEGRAM, /Buka Mini App SiGAP di Telegram untuk menghubungkan akun/], ['terlalu_sering', SERING, /Tunggu beberapa menit/]]) {
  test(`422 ${nama}: galat tingkat form dengan pesan server, tetap di layar masuk, bukan galat per-field`, async () => {
    const klien = { masuk: async () => { throw galatMasuk(badan); } };
    const { el, layar, id, pw, kirim } = await bukaMasuk(klien);
    await kirim();
    assert.ok(tetapDiLayarMasuk(el), 'tidak pindah ke layar kode');
    const kotak = kotakForm(el);
    assert.ok(kotak, 'kotak galat form tampil');
    assert.equal(kotak.textContent, badan.message, 'pesan server apa adanya');
    assert.match(kotak.textContent, pola);
    assert.match(kotak.className, /notice-tegas/, 'menonjol, bukan teks kecil');
    assert.equal(kotak.attrs.role, 'alert');
    assert.deepEqual(galatSandi(el).filter(Boolean), [], 'tidak ada galat per-field');
    assert.equal(id.value, 'siti.dapur');
    assert.equal(pw.value, '');
    layar.destroy(); selesaiUji();
  });
}

test('ember B: pesan cadangan dipakai bila badan tidak memuat pesan, dan pesan server menang bila ada', () => {
  const e = (data) => Object.assign(new Error('x'), { status: 422, errors: null, data });
  assert.deepEqual(L.klasifikasiGalat(e({ alasan: 'telegram_belum_terhubung' })), { tipe: 'form', pesan: L.PESAN_TELEGRAM_BELUM_TERHUBUNG });
  assert.deepEqual(L.klasifikasiGalat(e({ alasan: 'terlalu_sering' })), { tipe: 'form', pesan: L.PESAN_TERLALU_SERING });
  assert.equal(L.klasifikasiGalat(e({ alasan: 'terlalu_sering', message: 'dari server' })).pesan, 'dari server');
  assert.match(L.PESAN_TELEGRAM_BELUM_TERHUBUNG, /Mini App/);
  assert.equal(L.klasifikasiGalat(e({ alasan: 'kredensial_salah', message: 'm', errors: { password: ['dari field'] } })).tipe, 'kredensial');
});

test('sandi benar: tetap 200 langkah kode dan layar kode memakai pesan server', async () => {
  const { el, layar } = await bukaKode(tantangan(T0 - 5_000));
  assert.ok(cari(el, (x) => x.attrs.name === 'kode')[0]);
  assert.ok(adaTeks(el, /Kode 6 digit telah dikirim ke Telegram Anda\./));
  layar.destroy(); selesaiUji();
});

test('tanpa pesan server: cadangan layar kode dan toast kirim ulang tidak lagi berpagar "bila data benar"', async () => {
  const kotakToast = new El('div');
  const asli = document.getElementById;
  document.getElementById = (id) => (id === 'toasts' ? kotakToast : null);
  try {
    let balasan;
    const { el, layar } = await bukaKode(tantangan(T0 - 70_000, 3), {
      klien: { masuk: async () => ({ langkah: 'kode', tantangan: tantangan(T0 - 70_000, 3) }), kirimUlangKode: async () => balasan },
    });
    assert.ok(adaTeks(el, new RegExp(L.PESAN_KODE_DIKIRIM.replace('.', '\\.'))), 'cadangan dipakai bila server tak mengirim message');
    assert.doesNotMatch(el.textContent, /bila data benar/i);
    // Kirim ulang berhasil (sisa turun), server tanpa message -> cadangan "Kode baru ..."; dengan message -> pesan server.
    balasan = { langkah: 'kode', tantangan: tantangan(Date.now(), 2) };
    await tombol(el, /Kirim ulang kode/).picu('click');
    assert.equal(kotakToast.textContent, L.PESAN_KODE_BARU_DIKIRIM);
    // Kirim ulang ditolak (jatah tidak bergeser): pesan server TIDAK dipakai karena server menjawab pesan yang sama.
    const sama = tantangan(Date.now() - 70_000, 2);
    balasan = { langkah: 'kode', message: 'Kode 6 digit telah dikirim ke Telegram Anda.', tantangan: { ...sama, kirim_ulang_tersedia_pada: iso(Date.now() - 1000) } };
    await tombol(el, /Kirim ulang kode/).picu('click');
    assert.match(kotakToast.textContent, /Kirim ulang belum bisa dilakukan sekarang/);
    for (const t of [L.PESAN_KODE_DIKIRIM, L.PESAN_KODE_BARU_DIKIRIM, L.PESAN_KIRIM_ULANG_BELUM, L.PESAN_KREDENSIAL_SALAH, L.PESAN_TELEGRAM_BELUM_TERHUBUNG, L.PESAN_TERLALU_SERING, L.PESAN_TANTANGAN_MATI]) assert.doesNotMatch(t, /bila data benar/i);
    layar.destroy();
  } finally { document.getElementById = asli; selesaiUji(); }
});

test('tidak ada lagi teks "Bila data benar" di berkas layar masuk web', async () => {
  const { readFileSync, readdirSync } = await import('node:fs');
  const { fileURLToPath } = await import('node:url');
  const dir = fileURLToPath(new URL('../modules/auth-web/', import.meta.url));
  for (const f of readdirSync(dir)) assert.doesNotMatch(readFileSync(dir + f, 'utf8'), /bila data benar/i, f);
});
