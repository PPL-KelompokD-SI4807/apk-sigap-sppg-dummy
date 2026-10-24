// Laporan Rekapitulasi Harian Operasional + dasbor Kepala SPPG (fitur 16, PBI 16). Mockup: Gambar 10 dan 27.
import { defineCrudModule } from '../../components/crud-module.js';
import { registerWidget } from '../../components/dashboard.js';
import { rowCard } from '../../components/row-card.js';
import { h, clear } from '../../core/dom.js';
import { api, request, newKey } from '../../core/api.js';
import { getUser } from '../../core/auth.js';
import { userPerms } from '../../core/permissions.js';
import { route, resolve, navigate } from '../../core/router.js';
import { showConfirm, toast, setMainButton } from '../../core/ui.js';
import { errorMessage } from '../../core/errors.js';
import { todayWIB, tanggalJamWIB, hariLengkap } from '../../core/waktu.js';
import { kpiGrid, chartsBlock } from './view.js';
import {
  rekapPayload, validasiCatatan, validasiTanggalRekap, statusKirimLabel, statusKirimBadge, CATATAN_MAX, ringkasanKosong, labelTanggalPendek,
} from './schema.js';

const SLUG = 'rekap-harian';
const unwrap = (r) => (r && r.data !== undefined && !Array.isArray(r) ? r.data : r);
const me = () => userPerms(getUser(), SLUG);
const isAdmin = () => getUser()?.role === 'admin';

// Filter riwayat (mutable; dibaca list-view tiap muat).
const listQuery = { from: '', to: '' };

async function muatRingkasan(tanggal) {
  const [ringkasan, riwayat] = await Promise.all([
    api.get('/rekap-harian/ringkasan', { tanggal }, { silent: true }).then(unwrap),
    api.get('/rekap-harian', { per_page: 7, to: tanggal }, { silent: true }).then((r) => r?.data ?? []).catch(() => []),
  ]);
  return { ringkasan, riwayat };
}

// ---- Widget beranda Kepala SPPG ----
async function renderWidget(el) {
  clear(el);
  if (!me().R) return;
  const tanggal = todayWIB();
  try {
    const { ringkasan, riwayat } = await muatRingkasan(tanggal);
    clear(el);
    const charts = chartsBlock(ringkasan, riwayat);
    el.append(
      h('div', { class: 'card-title' }, h('strong', {}, hariLengkap(tanggal)), ringkasanKosong(ringkasan) ? h('span', { class: 'badge' }, 'Belum ada aktivitas') : h('span', { class: 'badge badge-ok' }, 'Operasional')),
      kpiGrid(ringkasan), charts.el,
      h('a', { class: 'btn btn-primary btn-block', href: `#/${SLUG}/ringkasan` }, 'Lihat Laporan Harian'));
  } catch (e) {
    if (e?.status === 401) throw e;
    clear(el);
    el.append(h('div', { class: 'empty' }, h('span', { class: 'kpi-sub' }, errorMessage(e, 'Dasbor gagal dimuat.')), h('button', { class: 'btn', type: 'button', onclick: () => renderWidget(el) }, 'Coba lagi')));
  }
}
registerWidget({ id: 'rekap-kepala', title: 'Dasbor hari ini', roles: ['kepala_sppg'], render: renderWidget });

// ---- Layar ringkasan langsung (tanggal bebas) ----
async function ringkasanScreen(ctx) {
  const tanggal = (ctx.query.get('tanggal') || todayWIB()).slice(0, 10);
  const sppgId = ctx.query.get('sppg_id');
  ctx.setTitle('Laporan Harian');
  const picker = h('input', { type: 'date', id: 'f-tgl', value: tanggal, max: todayWIB(), 'aria-label': 'Tanggal laporan' });
  picker.addEventListener('change', () => { if (picker.value) navigate(`/${SLUG}/ringkasan?tanggal=${picker.value}`, { replace: true }); });
  ctx.el.append(h('div', { class: 'panel stack-tight' }, h('label', { class: 'label', for: 'f-tgl' }, 'Tanggal'), picker, h('strong', {}, hariLengkap(tanggal))));
  if (isAdmin() && !sppgId) { ctx.el.append(h('div', { class: 'notice' }, 'Admin: tambahkan parameter sppg_id pada alamat untuk melihat SPPG tertentu.')); return; }

  const [{ ringkasan, riwayat }, tersimpan] = await Promise.all([
    muatRingkasan(tanggal),
    api.get('/rekap-harian', { from: tanggal, to: tanggal, per_page: 1 }, { silent: true }).then((r) => r?.data?.[0] ?? null).catch(() => null),
  ]);
  if (ctx.isStale()) return;
  const charts = chartsBlock(ringkasan, riwayat);
  ctx.el.append(kpiGrid(ringkasan), charts.el);
  if (tersimpan) ctx.el.append(h('a', { class: 'btn btn-block', href: `#/${SLUG}/${tersimpan.id}` }, `Lihat rekap tersimpan (${statusKirimLabel(tersimpan.status_kirim)})`));
  const p = me();
  if (p.C && (!tersimpan || p.U)) {
    setMainButton({ text: tersimpan ? 'Susun ulang rekap' : 'Susun rekap', onClick: () => navigate(`/${SLUG}/baru?tanggal=${tanggal}`) });
  }
  return charts.destroy;
}

// ---- Daftar riwayat ----
function listTop() {
  const dari = h('input', { type: 'date', id: 'f-from', value: listQuery.from, 'aria-label': 'Dari tanggal' });
  const sampai = h('input', { type: 'date', id: 'f-to', value: listQuery.to, 'aria-label': 'Sampai tanggal' });
  dari.addEventListener('change', () => { listQuery.from = dari.value; resolve(); });
  sampai.addEventListener('change', () => { listQuery.to = sampai.value; resolve(); });
  return h('div', { class: 'stack' },
    isAdmin() ? null : h('a', { class: 'btn btn-primary btn-block', href: `#/${SLUG}/ringkasan` }, 'Ringkasan hari ini'),
    h('div', { class: 'filter-row' }, dari, sampai));
}

// ---- Detail snapshot: KPI, catatan (ubah), kirim ulang ----
function catatanEditor(rec) {
  const ta = h('textarea', { id: 'f-catatan', rows: 3, maxlength: CATATAN_MAX, 'aria-describedby': 'catatan-err' });
  ta.value = rec.catatan ?? '';
  const err = h('p', { class: 'field-error', id: 'catatan-err', role: 'alert' });
  const btn = h('button', { class: 'btn btn-primary btn-block', type: 'button' }, 'Simpan catatan');
  btn.addEventListener('click', async () => {
    const msg = validasiCatatan(ta.value);
    if (msg) { err.textContent = msg; return; }
    err.textContent = '';
    btn.disabled = true;
    try {
      await request('PATCH', `/rekap-harian/${rec.id}`, { body: { catatan: ta.value.trim() || null }, key: newKey(), silent: true });
      toast('Catatan tersimpan.', 'success');
      resolve();
    } catch (e) {
      if (e?.status === 401) throw e;
      btn.disabled = false;
      if (e?.status === 422 && e.errors?.catatan) err.textContent = e.errors.catatan[0] ?? e.errors.catatan;
      else toast(errorMessage(e, 'Catatan gagal disimpan.'), 'error');
    }
  });
  return h('div', { class: 'panel stack-tight' }, h('label', { class: 'label', for: 'f-catatan' }, 'Catatan Kepala SPPG'), ta, err, btn);
}

function kirimUlang(rec) {
  const btn = h('button', { class: 'btn btn-block', type: 'button' }, 'Kirim ulang ke Telegram');
  btn.addEventListener('click', async () => {
    if (!(await showConfirm({ title: 'Kirim ulang rekap', message: 'Rekap dikirim ulang ke Kepala SPPG dan pengawas lewat bot Telegram. Lanjutkan?', okText: 'Kirim ulang' }))) return;
    btn.disabled = true;
    try {
      await request('POST', `/rekap-harian/${rec.id}/kirim-ulang`, { key: newKey(), silent: true });
      toast('Rekap dijadwalkan kirim ulang.', 'success');
      resolve();
    } catch (e) {
      if (e?.status === 401) throw e;
      btn.disabled = false;
      toast(errorMessage(e, 'Gagal menjadwalkan kirim ulang.'), 'error');
    }
  });
  return btn;
}

const base = defineCrudModule({
  slug: SLUG,
  title: 'Rekap Harian',
  icon: '📊',
  description: 'Laporan rekapitulasi harian dan dasbor',
  endpoint: '/rekap-harian',
  createLabel: '+ Susun Rekap',
  searchable: false,
  columns: [{ key: 'tanggal', primary: true, type: 'date' }],
  fields: ({ mode }) => (mode === 'detail'
    ? [
      { name: 'tanggal', label: 'Tanggal', display: (r) => hariLengkap(r.tanggal) },
      { name: 'status_kirim', label: 'Status kirim', display: (r) => statusKirimLabel(r.status_kirim) },
      { name: 'dikirim_pada', label: 'Dikirim pada', display: (r) => tanggalJamWIB(r.dikirim_pada), onlyIfPresent: true },
      { name: 'disusun_oleh', label: 'Disusun oleh', display: (r) => r.disusun_oleh?.name ?? 'Otomatis' },
      { name: 'disusun_pada', label: 'Disusun pada', display: (r) => tanggalJamWIB(r.disusun_pada) },
    ]
    : [
      { name: 'tanggal', label: 'Tanggal rekap', type: 'date', required: true, max: todayWIB(), default: todayWIB(), validate: (v) => validasiTanggalRekap(v, todayWIB()), help: 'Bila rekap tanggal ini sudah ada, ringkasannya dihitung ulang.' },
      { name: 'catatan', label: 'Catatan (opsional)', type: 'textarea', maxlength: CATATAN_MAX, validate: validasiCatatan },
      { name: 'kirim', label: 'Kirim ke Telegram sekarang?', type: 'select', default: 'false', options: [{ value: 'false', label: 'Tidak, simpan saja' }, { value: 'true', label: 'Ya, kirim' }] },
    ]),
  mapPayload: (v) => rekapPayload(v),
  itemTitle: (r) => `Rekap ${labelTanggalPendek(r.tanggal)}`,
  listQuery,
  listTop,
  prefill: (q) => (q.get('tanggal') ? { tanggal: q.get('tanggal') } : {}),
  rowPerms: (rec, p) => ({ ...p, U: false, D: false }), // catatan diubah lewat PATCH di detail; rekap tidak dihapus
  renderCard: (r, href) => {
    const p = r.ringkasan?.porsi; const k = r.ringkasan?.keuangan;
    return rowCard({
      href,
      title: hariLengkap(r.tanggal),
      sub: [p ? `porsi ${Number(p.realisasi ?? 0).toLocaleString('id-ID')}/${Number(p.target ?? 0).toLocaleString('id-ID')}` : null, k ? `serapan ${k.serapan_persen}%` : null].filter(Boolean).join(' · '),
      badge: { text: statusKirimLabel(r.status_kirim), cls: statusKirimBadge(r.status_kirim) },
    });
  },
  detailExtras: (rec) => {
    const p = me();
    const charts = chartsBlock(rec.ringkasan, [], { tren: false });
    return [
      rec.catatan && !p.U ? h('div', { class: 'panel' }, h('strong', {}, 'Catatan'), h('p', {}, rec.catatan)) : null,
      kpiGrid(rec.ringkasan), charts.el,
      p.U ? catatanEditor(rec) : null,
      p.U ? kirimUlang(rec) : null,
      p.C ? h('a', { class: 'btn btn-block', href: `#/${SLUG}/baru?tanggal=${String(rec.tanggal).slice(0, 10)}` }, 'Susun ulang rekap') : null,
    ].filter(Boolean);
  },
});

export default {
  ...base,
  register() {
    route(`/${SLUG}/ringkasan`, ringkasanScreen, { title: 'Laporan Harian', guard: () => me().R });
    base.register();
  },
};
