// Ekspor Laporan BGN (R07-4): terbitkan nomor LPA (langkah 1) lalu minta PDF (langkah 2, dikirim lewat bot Telegram).
// Hak diperiksa SERVER; layar ini hanya menyembunyikan opsi (Investor tanpa 30b dan 30d-C). Akses menu mengikuti baca keuangan.
import { h, clear } from '../../core/dom.js';
import { api, request, newKey } from '../../core/api.js';
import { getUser } from '../../core/auth.js';
import { userPerms, tanpaSppgSendiri } from '../../core/permissions.js';
import { route } from '../../core/router.js';
import { setMainButton } from '../../core/ui.js';
import { todayWIB } from '../../core/waktu.js';
import { jenisUntuk, jenisByKey, periodeBawaan, validasiPeriode, badanPermintaan, ringkasGalatEkspor } from './logika.js';

const SLUG = 'laporan-bgn';
const PERMISSION_SLUG = 'keuangan'; // pembaca keuangan = admin, kepala_sppg, akuntan, investor (peta hak server)
const unwrap = (r) => (r && r.data !== undefined && !Array.isArray(r) ? r.data : r);
const bolehBuka = () => userPerms(getUser(), PERMISSION_SLUG).R;

async function layarEkspor(ctx) {
  const user = getUser();
  const opsi = jenisUntuk(user);
  const today = todayWIB();
  let jenis = opsi[0];
  let nilai = periodeBawaan(jenis, today);
  let sppgId = '';
  let sibuk = false;

  const selJenis = h('select', { id: 'f-jenis', 'aria-label': 'Jenis laporan' }, opsi.map((j) => h('option', { value: j.key }, j.label)));
  const periodeBox = h('div', { class: 'stack-tight' });
  const status = h('div', { class: 'stack-tight', role: 'status', 'aria-live': 'polite' });
  const galat = h('p', { class: 'field-error', role: 'alert' });
  const errs = {};

  const tandaiGalat = (fields) => { for (const [k, node] of Object.entries(errs)) node.textContent = fields[k] ?? ''; };
  function field(name, label, type, extra = {}) {
    const input = h('input', { type, id: `f-${name}`, value: nilai[name] ?? '', 'aria-describedby': `f-${name}-err`, ...extra });
    input.addEventListener('change', () => { nilai[name] = input.value; });
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
  });

  // Admin lintas SPPG wajib memilih SPPG (pelingkupan data); peran lain memakai SPPG aktifnya.
  let sppgBox = null;
  if (tanpaSppgSendiri(user)) {
    const sel = h('select', { id: 'f-sppg', 'aria-label': 'SPPG' }, h('option', { value: '' }, 'Pilih SPPG...'));
    sel.addEventListener('change', () => { sppgId = sel.value; });
    sppgBox = h('div', { class: 'field' }, h('label', { class: 'label', for: 'f-sppg' }, 'SPPG', h('span', { class: 'req' }, ' *')), sel);
    api.get('/sppg', { per_page: 100 }, { silent: true }).then((r) => {
      for (const s of r?.data ?? []) sel.append(h('option', { value: s.id }, s.nama));
    }).catch(() => {});
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
    sibuk = true;
    setMainButton({ text: 'Memproses...', loading: true });
    const body = badanPermintaan(jenis, nilai, sppgId);
    let langkah = 'nomor';
    try {
      if (jenis.nomor) {
        status.append(baris('Langkah 1/2: menerbitkan nomor LPA...'));
        const n = unwrap(await request('POST', jenis.nomor, { body, key: newKey(), silent: true }));
        clear(status);
        status.append(baris(`Nomor LPA: ${n?.nomor ?? '-'}${n?.baru === false ? ' (sudah pernah terbit)' : ''}`, 'ok'));
      } else {
        status.append(baris('Laporan ini tidak bernomor LPA.', 'muted'));
      }
      langkah = 'pdf';
      status.append(baris(`Langkah ${jenis.nomor ? '2/2' : '1/1'}: meminta PDF...`));
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
    sppgBox, periodeBox,
    h('p', { class: 'muted small' }, 'Nomor LPA diterbitkan lebih dulu (bila laporan bernomor), lalu PDF dikirim ke chat Telegram Anda lewat bot.'),
    galat, status,
    h('button', { class: 'btn btn-primary btn-block', type: 'button', onclick: kirim }, 'Kirim PDF ke Telegram')));
  gambarPeriode();
  setMainButton({ text: 'Kirim PDF ke Telegram', onClick: kirim });
}

export default {
  slug: SLUG,
  permissionSlug: PERMISSION_SLUG,
  title: 'Ekspor Laporan BGN',
  icon: '📄',
  description: 'Lampiran 30a-30d (PDF dikirim lewat bot Telegram)',
  register() {
    route(`/${SLUG}`, layarEkspor, { title: 'Ekspor Laporan BGN', guard: bolehBuka });
  },
};
