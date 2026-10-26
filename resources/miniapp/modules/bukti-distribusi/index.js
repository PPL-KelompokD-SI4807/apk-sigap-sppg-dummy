// Bukti Distribusi (fitur 13, PBI 13). Mockup: Gambar 15 (unggah bukti dengan foto).
import { defineCrudModule } from '../../components/crud-module.js';
import { rowCard } from '../../components/row-card.js';
import { createAuthImage } from '../../components/photo-upload.js';
import { createLampiranPanel } from '../../components/lampiran-field.js';
import { h } from '../../core/dom.js';
import { api, fetchList } from '../../core/api.js';
import { getUser } from '../../core/auth.js';
import { resolve } from '../../core/router.js';
import { todayWIB, jamWIB, tanggalJamWIB } from '../../core/waktu.js';
import { errorMessage } from '../../core/errors.js';
import {
  unitDistribusiBoleh, jadwalDapatDipilih, jadwalOptionLabel, peringatanJadwal, statusJadwalLabel,
  terlambatLabel, terlambatBadge, selisihLabel, rowPermsBukti, buktiFields, buktiDetailFields, buktiPayload,
} from './schema.js';

const unwrap = (r) => (r && r.data !== undefined && !Array.isArray(r) ? r.data : r);

// Jadwal terpilih pada form (diisi kolom kustom jadwal; dibaca validator porsi/catatan selisih).
const jadwalSel = { cur: null, subs: [] };
const setCur = (j) => { jadwalSel.cur = j; jadwalSel.subs.forEach((fn) => fn(j)); };

// ---- Kolom kustom ----
function jadwalPicker(initialId) {
  return {
    name: 'jadwal_distribusi_id', label: 'Jadwal distribusi', type: 'custom', required: true,
    build: ({ value, onChange }) => {
      const sel = h('select', { id: 'f-jadwal_distribusi_id', name: 'jadwal_distribusi_id', 'aria-describedby': 'f-jadwal_distribusi_id-err' }, h('option', { value: '' }, 'Memuat jadwal...'));
      const info = h('div', { class: 'stack-tight', 'aria-live': 'polite' });
      const el = h('div', { class: 'stack-tight' }, sel, info);
      let list = [];
      const pick = () => list.find((x) => String(x.id) === sel.value) || null;

      function render() {
        const j = pick();
        info.replaceChildren();
        setCur(j);
        if (!j) return;
        info.append(h('div', { class: 'panel stack-tight' },
          h('div', { class: 'card-title' }, h('strong', {}, j.titik_layanan?.nama ?? `Jadwal #${j.id}`), h('span', { class: 'badge badge-info' }, j.status_label || statusJadwalLabel(j.status))),
          h('span', { class: 'muted small' }, [j.armada?.plat_nomor ? `Armada ${j.armada.plat_nomor}` : null, j.pengemudi?.nama ? `Pengemudi ${j.pengemudi.nama}` : null, j.jam_berangkat ? `Berangkat ${String(j.jam_berangkat).slice(0, 5)}` : null, j.jam_rencana_tiba ? `Rencana tiba ${String(j.jam_rencana_tiba).slice(0, 5)}` : null].filter(Boolean).join(' · '))));
        const w = peringatanJadwal(j);
        if (w) info.append(h('div', { class: w.tone === 'danger' ? 'alert-danger' : 'notice', role: 'alert' }, w.text));
        const porsi = document.getElementById('f-porsi_diterima');
        if (porsi && porsi.value === '' && j.jumlah_porsi != null) porsi.value = j.jumlah_porsi;
      }
      sel.addEventListener('change', () => { render(); onChange?.(); });

      (async () => {
        try {
          const user = getUser();
          const { items } = await fetchList('/jadwal/distribusi', { perPage: 100, extra: { tanggal: todayWIB() } });
          list = items.filter((j) => jadwalDapatDipilih(j, user));
          const want = value ?? initialId;
          if (want && !list.some((x) => String(x.id) === String(want))) {
            try { const one = unwrap(await api.get(`/jadwal/distribusi/${encodeURIComponent(want)}`, null, { silent: true })); if (one) list.unshift(one); } catch { /* abaikan */ }
          }
          sel.replaceChildren(h('option', { value: '' }, list.length ? '- Pilih jadwal -' : 'Tidak ada jadwal yang dapat dipilih'), ...list.map((j) => h('option', { value: j.id }, jadwalOptionLabel(j))));
          if (!list.length) info.append(h('div', { class: 'notice' }, user?.role === 'relawan' ? 'Tidak ada jadwal distribusi hari ini yang ditugaskan kepada Anda atau belum memiliki petugas.' : 'Tidak ada jadwal distribusi aktif hari ini.'), h('a', { class: 'btn', href: '#/jadwal/distribusi' }, 'Lihat jadwal distribusi'));
          if (want && list.some((x) => String(x.id) === String(want))) { sel.value = String(want); render(); }
        } catch (e) {
          sel.replaceChildren(h('option', { value: '' }, 'Gagal memuat jadwal'));
          info.append(h('div', { class: 'alert-danger', role: 'alert' }, errorMessage(e, 'Jadwal gagal dimuat.')));
        }
      })();

      return { el, get: () => (sel.value === '' ? null : sel.value), set: (v) => { sel.value = v ?? ''; render(); }, hasValue: () => sel.value !== '', focusEl: sel };
    },
  };
}

const porsiDikirimField = {
  name: 'porsi_dikirim', label: 'Porsi dikirim', type: 'custom',
  build: ({ values }) => {
    const input = h('input', { id: 'f-porsi_dikirim', type: 'text', readonly: true, 'aria-readonly': 'true', value: values.porsi_dikirim ?? '' });
    jadwalSel.subs.push((j) => { input.value = j?.jumlah_porsi ?? ''; });
    return { el: input, get: () => null, hasValue: () => false };
  },
};

function waktuTibaField(record) {
  return {
    name: 'waktu_tiba', label: 'Waktu tiba', type: 'custom',
    build: () => ({
      el: h('p', { class: 'readonly-text' }, record?.waktu_tiba ? tanggalJamWIB(record.waktu_tiba) : 'Dicatat otomatis oleh server saat bukti dikirim.'),
      get: () => null, hasValue: () => false,
    }),
  };
}

// Lokasi opsional: hanya dengan persetujuan pengguna (tombol), tidak otomatis.
const lokasiField = {
  name: 'lokasi', label: 'Lokasi (opsional)', type: 'custom',
  build: () => {
    let pos = null;
    const out = h('p', { class: 'muted small', role: 'status' });
    const btn = h('button', { type: 'button', class: 'btn btn-block' }, 'Sertakan lokasi saya');
    const paint = () => { out.textContent = pos ? `${pos.latitude}, ${pos.longitude}` : 'Tidak disertakan.'; btn.textContent = pos ? 'Hapus lokasi' : 'Sertakan lokasi saya'; };
    btn.addEventListener('click', () => {
      if (pos) { pos = null; return paint(); }
      if (!navigator.geolocation) { out.textContent = 'Perangkat tidak mendukung lokasi.'; return; }
      out.textContent = 'Mengambil lokasi...';
      navigator.geolocation.getCurrentPosition(
        (p) => { pos = { latitude: Number(p.coords.latitude.toFixed(5)), longitude: Number(p.coords.longitude.toFixed(5)) }; paint(); },
        () => { out.textContent = 'Lokasi tidak dapat diambil. Izin ditolak atau sinyal GPS lemah.'; },
        { enableHighAccuracy: false, timeout: 10000, maximumAge: 60000 });
    });
    paint();
    return { el: h('div', { class: 'stack-tight' }, btn, out), get: () => pos, hasValue: () => !!pos, focusEl: btn };
  },
};

function fields({ mode, record }) {
  if (mode === 'detail') return buktiDetailFields;
  jadwalSel.subs = [];
  jadwalSel.cur = mode === 'update' && record?.porsi_dikirim != null ? { jumlah_porsi: record.porsi_dikirim } : null;
  return buktiFields({ mode, jadwalSel, custom: { jadwal: jadwalPicker(null), porsiDikirim: porsiDikirimField, waktuTiba: waktuTibaField(record), lokasi: lokasiField } });
}

// ---- Filter daftar: per hari + titik layanan + terlambat (mutable; dibaca list-view tiap muat) ----
const listQuery = { tanggal: '', titik_layanan_id: '', terlambat: '' };
let inited = false;

function listTop() {
  if (!inited) { listQuery.tanggal = todayWIB(); inited = true; }
  const setF = (k, v) => { listQuery[k] = v; resolve(); };
  const tgl = h('input', { type: 'date', id: 'f-tanggal', value: listQuery.tanggal, 'aria-label': 'Tanggal distribusi' });
  tgl.addEventListener('change', () => setF('tanggal', tgl.value));
  const titik = h('select', { id: 'f-titik', 'aria-label': 'Titik layanan' }, h('option', { value: '' }, 'Semua titik layanan'));
  fetchList('/titik-layanan', { perPage: 100 }).then(({ items }) => {
    items.forEach((t) => titik.append(h('option', { value: t.id }, t.nama)));
    titik.value = listQuery.titik_layanan_id;
  }).catch(() => { /* filter opsional */ });
  titik.addEventListener('change', () => setF('titik_layanan_id', titik.value));
  const chip = (value, label) => h('button', { type: 'button', class: `seg-btn${listQuery.terlambat === value ? ' active' : ''}`, 'aria-pressed': listQuery.terlambat === value ? 'true' : 'false', onclick: () => setF('terlambat', value) }, label);
  return h('div', { class: 'stack' },
    h('div', { class: 'filter-row' }, tgl, titik),
    h('div', { class: 'seg', role: 'group', 'aria-label': 'Ketepatan waktu' }, chip('', 'Semua'), chip('1', 'Terlambat')),
    listQuery.tanggal ? null : h('p', { class: 'muted small' }, 'Menampilkan semua tanggal.'));
}

export default defineCrudModule({
  slug: 'bukti-distribusi',
  title: 'Bukti Distribusi',
  icon: '📸',
  description: 'Bukti serah terima makanan di titik layanan',
  endpoint: '/bukti-distribusi',
  createLabel: '+ Bukti',
  columns: [{ key: 'titik', primary: true, format: (r) => r.jadwal_distribusi?.titik_layanan?.nama ?? `Bukti #${r.id}` }],
  extraGuard: unitDistribusiBoleh,
  fields,
  mapPayload: buktiPayload,
  itemTitle: (r) => `Bukti ${r.jadwal_distribusi?.titik_layanan?.nama ?? `#${r.id}`}`,
  searchPlaceholder: 'Cari penerima atau titik layanan...',
  listQuery,
  listTop,
  prefill: (q) => (q.get('jadwal') ? { jadwal_distribusi_id: q.get('jadwal') } : {}),
  rowPerms: (rec, p) => rowPermsBukti(getUser(), rec, p),
  renderCard: (r, href) => rowCard({
    href,
    title: r.jadwal_distribusi?.titik_layanan?.nama ?? `Bukti #${r.id}`,
    sub: [jamWIB(r.waktu_tiba), `${r.porsi_diterima ?? 0}/${r.porsi_dikirim ?? '-'} porsi`, r.nama_pic].filter(Boolean).join(' · '),
    badges: [
      { text: terlambatLabel(r), cls: terlambatBadge(r) },
      ...(r.selisih_porsi ? [{ text: selisihLabel(r), cls: 'badge-warn' }] : []),
    ],
  }),
  detailExtras: (rec, p) => {
    const nodes = [];
    if (rec.terlambat) nodes.push(h('div', { class: 'alert-danger', role: 'alert' }, `${terlambatLabel(rec)} dari acuan ${rec.acuan_tiba ? jamWIB(rec.acuan_tiba) : '-'}.`));
    if (rec.selisih_porsi) nodes.push(h('div', { class: 'notice' }, `Selisih porsi: ${selisihLabel(rec)}.${rec.catatan_selisih ? ` Catatan: ${rec.catatan_selisih}` : ''}`));
    const gambar = h('div', { class: 'panel stack-tight' }, h('h2', { class: 'panel-title' }, 'Foto serah terima'),
      rec.foto ? createAuthImage(rec.foto, { alt: 'Foto serah terima' }) : h('p', { class: 'muted small' }, 'Tidak ada foto.'));
    if (rec.berita_acara) gambar.append(h('h2', { class: 'panel-title' }, 'Berita acara'), createAuthImage(rec.berita_acara, { alt: 'Foto berita acara' }));
    nodes.push(gambar);
    const utama = new Set([rec.foto?.lampiran_id, rec.berita_acara?.lampiran_id].filter((x) => x != null));
    const lain = (rec.lampiran || []).filter((l) => !utama.has(l.id));
    if (lain.length || p.U) nodes.push(createLampiranPanel({ modul: 'bukti-distribusi', recordId: rec.id, kategori: 'foto', lampiran: lain, canAdd: !!p.U, title: 'Lampiran lain' }));
    return nodes;
  },
});
