// Ekspor Laporan BGN (R07-4): terbitkan nomor LPA (langkah 1) lalu minta PDF (langkah 2, dikirim lewat bot Telegram).
// Hak diperiksa SERVER; layar ini hanya menyembunyikan opsi (Investor tanpa 30b dan 30d-C). Akses menu dan layar = aksi.lihat_laporan_bgn atau aksi.lihat_laporan_bgn_agregat dari peta hak server.
import { h, clear } from '../../core/dom.js';
import { api, request, newKey } from '../../core/transport.js';
import { getUser } from '../../core/auth.js';
import { bolehAksi, tanpaSppgSendiri } from '../../core/permissions.js';
import { route } from '../../core/router.js';
import { setMainButton } from '../../core/ui.js';
import { todayWIB } from '../../core/waktu.js';
import { jenisUntuk, jenisByKey, periodeBawaan, validasiPeriode, badanPermintaan, ringkasGalatEkspor, kandidatDari, bolehTerbitkanNomor } from './logika.js';

const SLUG = 'laporan-bgn';
const unwrap = (r) => (r && r.data !== undefined && !Array.isArray(r) ? r.data : r);
// Laporan BGN bukan modul matriks: gerbangnya tiga aksi di peta hak server (docs/api/peta-hak.md).
const bolehBuka = (user = getUser()) => bolehAksi(user, 'lihat_laporan_bgn') || bolehAksi(user, 'lihat_laporan_bgn_agregat');

export async function layarEkspor(ctx) {
  const user = getUser();
  const opsi = jenisUntuk(user);
  const today = todayWIB();
  let jenis = opsi[0];
  let nilai = periodeBawaan(jenis, today);
  let sppgId = '';
  let sibuk = false;
  let pilihan = {}; // akuntan_id / kepala_sppg_id bila ada lebih dari satu kandidat penandatangan
  const wajibPilih = new Set(); // kunci pilihan penandatangan yang ditampilkan (lebih dari satu kandidat)
  let nomorGet = 0; // penjaga balapan permintaan kandidat

  const selJenis = h('select', { id: 'f-jenis', 'aria-label': 'Jenis laporan' }, opsi.map((j) => h('option', { value: j.key }, j.label)));
  const periodeBox = h('div', { class: 'stack-tight' });
  const penandatanganBox = h('div', { class: 'stack-tight' });
  const status = h('div', { class: 'stack-tight', role: 'status', 'aria-live': 'polite' });
  const galat = h('p', { class: 'field-error', role: 'alert' });
  const errs = {};

  const tandaiGalat = (fields) => { for (const [k, node] of Object.entries(errs)) node.textContent = fields[k] ?? ''; };
  function field(name, label, type, extra = {}) {
    const input = h('input', { type, id: `f-${name}`, value: nilai[name] ?? '', 'aria-describedby': `f-${name}-err`, ...extra });
    input.addEventListener('change', () => { nilai[name] = input.value; muatKandidat(); });
    errs[name] = h('p', { class: 'field-error', id: `f-${name}-err`, role: 'alert' });
    return h('div', { class: 'field' }, h('label', { class: 'label', for: `f-${name}` }, label), input, errs[name]);
  }
  function gambarPeriode() {
    clear(periodeBox);
    for (const k of Object.keys(errs)) delete errs[k];
    if (jenis.periode === 'tanggal') periodeBox.append(field('tanggal', 'Tanggal laporan', 'date', { max: today }));
    else if (jenis.periode === 'bulan') periodeBox.append(field('bulan', 'Bulan laporan', 'month', { max: today.slice(0, 7) }));
    else periodeBox.append(field('tanggal_awal', 'Dari tanggal', 'date'), field('tanggal_akhir', 'Sampai tanggal', 'date', { max: today }), h('p', { class: 'muted small' }, 'Rentang maksimal 16 hari.'));
    galat.textContent = '';
  }
  selJenis.addEventListener('change', () => {
    jenis = jenisByKey(selJenis.value) ?? jenis;
    nilai = periodeBawaan(jenis, today);
    gambarPeriode();
    clear(status);
    muatKandidat();
  });

  // Admin lintas SPPG wajib memilih SPPG (pelingkupan data); peran lain memakai SPPG aktifnya.
  let sppgBox = null;
  if (tanpaSppgSendiri(user)) {
    const sel = h('select', { id: 'f-sppg', 'aria-label': 'SPPG' }, h('option', { value: '' }, 'Pilih SPPG...'));
    sel.addEventListener('change', () => { sppgId = sel.value; muatKandidat(); });
    sppgBox = h('div', { class: 'field' }, h('label', { class: 'label', for: 'f-sppg' }, 'SPPG', h('span', { class: 'req' }, ' *')), sel);
    api.get('/sppg', { per_page: 100 }, { silent: true }).then((r) => {
      for (const s of r?.data ?? []) sel.append(h('option', { value: s.id }, s.nama));
    }).catch(() => {});
  }

  function pilihPenandatangan(kunci, label, daftar) {
    const sel = h('select', { id: `f-${kunci}`, 'aria-label': label }, h('option', { value: '' }, 'Pilih...'), daftar.map((k) => h('option', { value: k.id }, k.nama)));
    sel.addEventListener('change', () => { if (sel.value) pilihan[kunci] = sel.value; else delete pilihan[kunci]; });
    return h('div', { class: 'field' }, h('label', { class: 'label', for: `f-${kunci}` }, label, h('span', { class: 'req' }, ' *')), sel);
  }

  // Pilihan penandatangan: dari GET laporan (data.penandatangan.*.kandidat); hanya tampil bila ada lebih dari satu kandidat.
  async function muatKandidat() {
    const saya = ++nomorGet;
    clear(penandatanganBox);
    pilihan = {};
    wajibPilih.clear();
    if (Object.keys(validasiPeriode(jenis, nilai, today)).length || (sppgBox && !sppgId)) return;
    let res;
    try { res = await api.get(jenis.get, badanPermintaan(jenis, nilai, sppgId), { silent: true }); } catch { return; } // gagal: kirim tetap bisa, server memvalidasi
    if (saya !== nomorGet) return;
    const k = kandidatDari(res);
    if (k.akuntan.length > 1) wajibPilih.add('akuntan_id');
    if (k.kepala.length > 1) wajibPilih.add('kepala_sppg_id');
    if (k.akuntan.length > 1) penandatanganBox.append(pilihPenandatangan('akuntan_id', 'Pihak Kedua (Akuntan)', k.akuntan));
    if (k.kepala.length > 1) penandatanganBox.append(pilihPenandatangan('kepala_sppg_id', 'Mengetahui (Kepala SPPG)', k.kepala));
  }

  const baris = (teks, kelas = '') => h('p', { class: `small ${kelas}`.trim() }, teks);

  async function kirim() {
    if (sibuk) return;
    galat.textContent = '';
    clear(status);
    tandaiGalat({});
    if (sppgBox && !sppgId) { galat.textContent = 'Pilih SPPG terlebih dahulu.'; return; }
    const salah = validasiPeriode(jenis, nilai, today);
    if (Object.keys(salah).length) { tandaiGalat(salah); galat.textContent = Object.values(salah)[0]; return; }
    // Semua pemeriksaan masukan SEBELUM sibuk/loading: kembali di sini tidak boleh meninggalkan tombol macet.
    for (const [k, label] of [['akuntan_id', 'Pihak Kedua (Akuntan)'], ['kepala_sppg_id', 'Mengetahui (Kepala SPPG)']]) {
      if (wajibPilih.has(k) && !pilihan[k]) { galat.textContent = `Pilih ${label} terlebih dahulu.`; return; }
    }
    sibuk = true;
    setMainButton({ text: 'Memproses...', loading: true });
    const body = badanPermintaan(jenis, nilai, sppgId, pilihan);
    let langkah = 'nomor';
    try {
      if (jenis.nomor && bolehTerbitkanNomor(user)) {
        status.append(baris('Langkah 1/2: menerbitkan nomor LPA...'));
        const n = unwrap(await request('POST', jenis.nomor, { body, key: newKey(), silent: true }));
        clear(status);
        status.append(baris(`Nomor LPA: ${n?.nomor ?? '-'}${n?.baru === false ? ' (sudah pernah terbit)' : ''}`, 'ok'));
      } else {
        status.append(baris(jenis.nomor ? 'Anda tidak berhak menerbitkan nomor LPA; PDF memuat "(belum diterbitkan)".' : 'Laporan ini tidak bernomor LPA.', 'muted'));
      }
      langkah = 'pdf';
      status.append(baris(`Langkah ${jenis.nomor && bolehTerbitkanNomor(user) ? '2/2' : '1/1'}: meminta PDF...`));
      await request('POST', jenis.pdf, { body, key: newKey(), silent: true });
      status.append(h('div', { class: 'notice' }, h('strong', {}, 'PDF dikirim lewat bot Telegram. '), 'Buka chat bot SiGAP untuk menerima berkasnya (biasanya dalam beberapa saat).'));
    } catch (e) {
      if (e?.status === 401) throw e;
      const g = ringkasGalatEkspor(e, langkah);
      tandaiGalat(g.fields);
      galat.textContent = g.pesan;
    } finally {
      sibuk = false;
      setMainButton({ text: 'Kirim PDF ke Telegram', onClick: kirim });
    }
  }

  ctx.setTitle('Ekspor Laporan BGN');
  ctx.el.append(h('div', { class: 'panel stack-tight' },
    h('label', { class: 'label', for: 'f-jenis' }, 'Jenis laporan'), selJenis,
    sppgBox, periodeBox, penandatanganBox,
    h('p', { class: 'muted small' }, 'Nomor LPA diterbitkan lebih dulu (bila laporan bernomor), lalu PDF dikirim ke chat Telegram Anda lewat bot.'),
    galat, status,
    h('button', { class: 'btn btn-primary btn-block', type: 'button', onclick: kirim }, 'Kirim PDF ke Telegram')));
  gambarPeriode();
  muatKandidat();
  setMainButton({ text: 'Kirim PDF ke Telegram', onClick: kirim });
}

export default {
  slug: SLUG,
  bolehMenu: bolehBuka,
  title: 'Ekspor Laporan BGN',
  icon: '📄',
  description: 'Lampiran 30a-30d (PDF dikirim lewat bot Telegram)',
  register() {
    route(`/${SLUG}`, layarEkspor, { title: 'Ekspor Laporan BGN', guard: bolehBuka });
  },
};
