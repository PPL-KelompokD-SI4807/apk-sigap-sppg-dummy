// Skema Laporan Rekapitulasi Harian + dasbor Kepala SPPG (fitur 16). Kontrak: docs/api/paths/rekap-harian.yaml,
// docs/api/components/rekap-harian.yaml. Murni (tanpa DOM) agar bisa diuji dengan node --test.
import { buildPayload } from '../../core/schema-helpers.js';
import { tanggalJamWIB, hariLengkap } from '../../core/waktu.js';

const n0 = (v) => Number(v) || 0;
const fmt = (v) => n0(v).toLocaleString('id-ID');
const pct = (v) => n0(v).toLocaleString('id-ID', { maximumFractionDigits: 1 });
/** Batas 0..100 untuk lebar bilah progres. */
export const clampPersen = (v) => Math.max(0, Math.min(100, n0(v)));

/** "Rp 28.750.000" dari string desimal "28750000.00". */
export const rupiah = (v) => `Rp ${Math.round(n0(v)).toLocaleString('id-ID')}`;
/** Ringkas: "Rp312,4 jt", "Rp1,2 M", "Rp850 rb". */
export function rupiahRingkas(v) {
  const x = n0(v);
  const a = Math.abs(x);
  const f = (d, s) => `Rp${(x / d).toLocaleString('id-ID', { maximumFractionDigits: 1 })} ${s}`;
  if (a >= 1e9) return f(1e9, 'M');
  if (a >= 1e6) return f(1e6, 'jt');
  if (a >= 1e3) return f(1e3, 'rb');
  return `Rp${Math.round(x).toLocaleString('id-ID')}`;
}

/** Ambang anggaran: >=100% merah, >=80% kuning. */
export const anggaranTone = (serapan) => (n0(serapan) >= 100 ? 'danger' : n0(serapan) >= 80 ? 'warn' : 'ok');
const porsiTone = (target, capaian) => (n0(target) === 0 ? 'muted' : n0(capaian) >= 95 ? 'ok' : n0(capaian) >= 80 ? 'warn' : 'danger');

/** Status mutu agregat: teks + nada. */
export function mutuStatus(m = {}) {
  if (n0(m.tidak_layak) > 0) return { text: 'Ada tidak layak', tone: 'danger' };
  if (n0(m.batch) === 0) return { text: 'Belum ada batch', tone: 'muted' };
  if (n0(m.belum_diperiksa) > 0) return { text: 'Belum lengkap', tone: 'warn' };
  return { text: 'Layak', tone: 'ok' };
}

/** Pemetaan data ringkasan -> kartu KPI: [{id,label,value,sub,progress,tone}]. */
export function kpiCards(r) {
  if (!r) return [];
  const p = r.porsi ?? {}; const d = r.distribusi ?? {}; const m = r.mutu ?? {}; const s = r.stok ?? {}; const k = r.keuangan ?? {}; const q = r.keluhan ?? {};
  const mutu = mutuStatus(m);
  const stokKritis = n0(s.menipis) + n0(s.sudah_kedaluwarsa);
  return [
    {
      id: 'porsi', label: 'Porsi realisasi / target', value: `${fmt(p.realisasi)} / ${fmt(p.target)}`,
      sub: `Capaian ${pct(p.capaian_persen)}%${n0(p.jadwal_tanpa_log) ? ` · ${fmt(p.jadwal_tanpa_log)} jadwal belum ada log` : ''}`,
      progress: clampPersen(p.capaian_persen), tone: porsiTone(p.target, p.capaian_persen),
    },
    {
      id: 'distribusi', label: 'Distribusi tiba', value: `${fmt(d.tiba)} / ${fmt(d.total)} titik`,
      sub: `${fmt(d.terlambat)} terlambat · ${fmt(d.batal)} batal · ${fmt(d.belum_selesai)} belum selesai`,
      progress: n0(d.total) ? clampPersen((n0(d.tiba) / n0(d.total)) * 100) : 0, tone: n0(d.terlambat) + n0(d.batal) > 0 ? 'warn' : n0(d.total) ? 'ok' : 'muted',
    },
    { id: 'mutu', label: 'Pemeriksaan mutu', value: mutu.text, sub: `${fmt(m.layak)} dari ${fmt(m.batch)} batch layak${n0(m.belum_diperiksa) ? ` · ${fmt(m.belum_diperiksa)} belum diperiksa` : ''}`, progress: null, tone: mutu.tone },
    {
      id: 'stok', label: 'Stok kritis', value: `${fmt(stokKritis)} bahan`,
      sub: `${fmt(s.menipis)} menipis · ${fmt(s.sudah_kedaluwarsa)} kedaluwarsa · ${fmt(s.akan_kedaluwarsa)} akan kedaluwarsa`,
      progress: null, tone: n0(s.sudah_kedaluwarsa) > 0 ? 'danger' : stokKritis + n0(s.akan_kedaluwarsa) > 0 ? 'warn' : 'ok',
    },
    {
      id: 'anggaran', label: 'Sisa anggaran', value: rupiahRingkas(k.sisa_anggaran),
      sub: `Terserap ${pct(k.serapan_persen)}%${k.periode ? ` · periode ${k.periode}` : ''}`, progress: clampPersen(k.serapan_persen), tone: anggaranTone(k.serapan_persen),
    },
    {
      id: 'keluhan', label: 'Keluhan terbuka', value: fmt(q.terbuka),
      sub: `${fmt(q.baru)} baru hari ini · ${fmt(q.kritis)} kritis`, progress: null, tone: n0(q.kritis) > 0 ? 'danger' : n0(q.terbuka) > 0 ? 'warn' : 'ok',
    },
    { id: 'sisa', label: 'Sisa makanan', value: `${n0(r.sisa_makanan_kg).toLocaleString('id-ID', { maximumFractionDigits: 1 })} kg`, sub: '', progress: null, tone: 'muted' },
  ];
}

/** Seri grafik donat status distribusi (tiba tepat waktu = tiba - terlambat). */
export function distribusiSeries(r) {
  const d = r?.distribusi ?? {};
  const items = [
    { key: 'ok', label: 'Tiba tepat waktu', value: Math.max(0, n0(d.tiba) - n0(d.terlambat)) },
    { key: 'warn', label: 'Tiba terlambat', value: n0(d.terlambat) },
    { key: 'danger', label: 'Batal', value: n0(d.batal) },
    { key: 'muted', label: 'Belum selesai', value: n0(d.belum_selesai) },
  ];
  return { items, total: items.reduce((a, x) => a + x.value, 0) };
}

/** "2026-11-02" -> "2/11". */
export const labelTanggalPendek = (iso) => {
  const m = /^\d{4}-(\d{2})-(\d{2})/.exec(String(iso ?? ''));
  return m ? `${Number(m[2])}/${Number(m[1])}` : '-';
};

/**
 * Seri tren capaian porsi: snapshot riwayat (urutan bebas) + ringkasan langsung hari yang dilihat.
 * Mengembalikan maksimal `max` hari terakhir, urut naik: {labels, values, tanggal, aktif}.
 */
export function trendSeries(snapshots = [], live = null, max = 7) {
  const byDate = new Map();
  for (const s of snapshots ?? []) {
    const t = String(s?.tanggal ?? '').slice(0, 10);
    if (t) byDate.set(t, n0(s.ringkasan?.porsi?.capaian_persen));
  }
  const liveDate = String(live?.tanggal ?? '').slice(0, 10);
  if (liveDate) byDate.set(liveDate, n0(live.porsi?.capaian_persen));
  const rows = [...byDate.entries()].sort(([a], [b]) => a.localeCompare(b)).slice(-max);
  return {
    tanggal: rows.map(([t]) => t),
    labels: rows.map(([t]) => labelTanggalPendek(t)),
    values: rows.map(([, v]) => v),
    aktif: liveDate ? rows.findIndex(([t]) => t === liveDate) : -1,
  };
}

/** Tambah kanal alfa ke warna #rrggbb; format lain dikembalikan apa adanya. */
export function withAlpha(color, alpha) {
  const c = String(color ?? '').trim();
  if (!/^#[0-9a-f]{6}$/i.test(c)) return c;
  return c + Math.round(Math.max(0, Math.min(1, alpha)) * 255).toString(16).padStart(2, '0');
}

export const STATUS_KIRIM = { menunggu: 'Menunggu dikirim', terkirim: 'Terkirim', gagal: 'Gagal terkirim', tidak_terkirim: 'Tidak terkirim' };
export const statusKirimLabel = (v) => STATUS_KIRIM[v] ?? v ?? '-';
export const statusKirimBadge = (v) => ({ terkirim: 'badge-ok', gagal: 'badge-danger', tidak_terkirim: 'badge-warn', menunggu: 'badge-info' }[v] || '');

export const CATATAN_MAX = 1000;
export const validasiCatatan = (v) => (String(v ?? '').length > CATATAN_MAX ? `Maksimal ${CATATAN_MAX} karakter.` : '');
/** Tanggal rekap tidak boleh di masa depan (WIB). */
export const validasiTanggalRekap = (v, hariIni) => (v && String(v).slice(0, 10) > hariIni ? 'Tanggal tidak boleh di masa depan.' : '');

/** Muatan POST /rekap-harian. catatan tidak dikirim bila kosong agar catatan lama dipertahankan. */
export function rekapPayload(values) {
  const out = buildPayload(values, { keys: ['tanggal', 'catatan', 'kirim'], bools: ['kirim'] });
  return out;
}

/** Hak aksi rekap: susun = C, susun ulang/ubah catatan/kirim ulang = U. Admin tidak menyusun. */
export function aksiRekap(p) { return { susun: !!p?.C, ubah: !!p?.U }; }

export const ringkasanKosong = (r) => !r || (n0(r.porsi?.jumlah_jadwal) === 0 && n0(r.distribusi?.total) === 0);
export const judulHari = (iso) => hariLengkap(iso);
export { tanggalJamWIB };
